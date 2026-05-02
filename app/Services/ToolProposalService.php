<?php

namespace App\Services;

use App\Contracts\ReviewApprovalHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for agent tool proposals.
 *
 * Wraps AgentToolRegistryService proposal methods with review queue integration.
 * Agents propose tools → review queue entry created → human approves/rejects →
 * approval handler enables the tool in agent_tool_registry.
 */
class ToolProposalService implements ReviewApprovalHandler
{
    private AgentToolRegistryService $toolRegistry;

    public function __construct(AgentToolRegistryService $toolRegistry)
    {
        $this->toolRegistry = $toolRegistry;
    }

    /**
     * Agent proposes a new tool. Creates disabled tool + review queue entry.
     *
     * Called by agents via the `propose_tool` registered tool.
     *
     * @param array $params Tool proposal from agent (merged with context by resolver):
     *   - name (required): tool name (snake_case)
     *   - service_class (required): fully qualified class name
     *   - method (required): method name on service class
     *   - description (required): what the tool does
     *   - parameters (optional): JSON schema of parameters
     *   - returns (optional): description of return value
     *   - reason (optional): why the agent thinks this tool is needed
     *   - category (optional): tool category
     *   - risk_level (optional): read|write|destructive (default: read)
     *   - agent_id (injected from context): agent proposing the tool
     * @return array
     */
    public function propose(array $params): array
    {
        $agentId = $params['agent_id'] ?? 'unknown';
        $name = $params['name'] ?? null;
        if (!$name) {
            return ['success' => false, 'error' => 'Tool name is required'];
        }

        // Validate name format
        if (!preg_match('/^[a-z][a-z0-9_]{2,99}$/', $name)) {
            return ['success' => false, 'error' => 'Tool name must be snake_case, 3-100 chars, start with letter'];
        }

        // Check for duplicate (existing or already proposed)
        $existing = DB::select("SELECT name, source, enabled FROM agent_tool_registry WHERE name = ?", [$name]);
        if ($existing) {
            $row = $existing[0];
            if ($row->source === 'agent_proposed' && !$row->enabled) {
                return ['success' => false, 'error' => "Tool '{$name}' already proposed and awaiting review"];
            }
            return ['success' => false, 'error' => "Tool '{$name}' already exists in registry"];
        }

        // Validate required fields
        $serviceClass = $params['service_class'] ?? null;
        $method = $params['method'] ?? null;
        $description = $params['description'] ?? null;

        if (!$serviceClass || !$method || !$description) {
            return ['success' => false, 'error' => 'service_class, method, and description are required'];
        }

        // Register as disabled proposed tool
        $toolDef = [
            'name' => $name,
            'service_class' => $serviceClass,
            'method' => $method,
            'description' => $description,
            'parameters' => $params['parameters'] ?? [],
            'returns' => $params['returns'] ?? null,
            'permissions' => $params['permissions'] ?? [],
        ];

        $result = $this->toolRegistry->proposeTool($toolDef, $agentId);

        if (!$result['success']) {
            return $result;
        }

        // Set optional fields that proposeTool doesn't handle
        $updates = [];
        $bindings = [];
        if (!empty($params['category'])) {
            $updates[] = 'category = ?';
            $bindings[] = $params['category'];
        }
        if (!empty($params['risk_level']) && in_array($params['risk_level'], ['read', 'write', 'destructive'])) {
            $updates[] = 'risk_level = ?';
            $bindings[] = $params['risk_level'];
        }
        if (!empty($params['notes'])) {
            $updates[] = 'notes = ?';
            $bindings[] = $params['notes'];
        }
        if ($updates) {
            $bindings[] = $name;
            DB::update("UPDATE agent_tool_registry SET " . implode(', ', $updates) . " WHERE name = ?", $bindings);
        }

        // Create review queue entry
        $reason = $params['reason'] ?? "Agent {$agentId} identified a capability gap";
        $reviewResult = $this->submitForReview($name, $agentId, $toolDef, $reason);

        Log::info("ToolProposal: Tool proposed", [
            'tool' => $name,
            'agent' => $agentId,
            'service' => $serviceClass,
            'method' => $method,
            'review_id' => $reviewResult['review_id'] ?? null,
        ]);

        return [
            'success' => true,
            'tool' => $name,
            'status' => 'proposed',
            'review_id' => $reviewResult['review_id'] ?? null,
            'message' => "Tool '{$name}' proposed and submitted for human review",
        ];
    }

    /**
     * Get pending tool proposals (for agents to check status).
     */
    public function getPending(): array
    {
        $proposals = $this->toolRegistry->getPendingProposals();

        return [
            'success' => true,
            'count' => count($proposals),
            'proposals' => $proposals,
        ];
    }

    /**
     * Approve a tool proposal via review queue.
     *
     * Called by ReviewTypeRegistryService::callServiceMethod($token, $notes).
     * The $token comes from the unified_id_template 'tool_proposal:{{token}}'.
     * We look up the review queue entry by token to find the tool name in details JSON.
     *
     * @param string $token Review queue token from unified ID
     * @param string|null $notes Optional reviewer notes
     * @return array
     */
    public function approveProposal(string $token, ?string $notes = null): array
    {
        // Look up review queue entry by token to get tool name
        $review = DB::selectOne(
            "SELECT id, details FROM agent_review_queue WHERE token = ? AND review_type = 'tool_proposal'",
            [$token]
        );

        if (!$review) {
            return ['success' => false, 'error' => 'Review queue entry not found for token'];
        }

        $details = json_decode($review->details, true) ?: [];
        $toolName = $details['tool_name'] ?? null;

        if (!$toolName) {
            return ['success' => false, 'error' => 'No tool_name in review details'];
        }

        // Verify tool exists and is pending
        $rows = DB::select(
            "SELECT name, source, enabled, approved_at FROM agent_tool_registry WHERE name = ? AND source = 'agent_proposed'",
            [$toolName]
        );

        if (!$rows) {
            return ['success' => false, 'error' => "Proposed tool '{$toolName}' not found in registry"];
        }

        if ($rows[0]->approved_at) {
            return ['success' => false, 'error' => "Tool '{$toolName}' already approved"];
        }

        // Approve: enable the tool in registry
        $result = $this->toolRegistry->approveTool($toolName, 'human');

        // Update review queue status
        DB::update(
            "UPDATE agent_review_queue SET status = 'approved', reviewed_at = NOW(), reviewer_notes = ?, updated_at = NOW() WHERE token = ?",
            [$notes, $token]
        );

        if ($result['success']) {
            Log::info("ToolProposal: Tool approved", ['tool' => $toolName, 'token' => $token]);
        }

        return array_merge($result, [
            'message' => "Tool '{$toolName}' approved and enabled in registry",
        ]);
    }

    /**
     * Reject a tool proposal via review queue.
     *
     * Called by ReviewTypeRegistryService::callServiceMethod($token, $reason).
     *
     * @param string $token Review queue token from unified ID
     * @param string|null $reason Rejection reason
     * @return array
     */
    public function rejectProposal(string $token, ?string $reason = null): array
    {
        $review = DB::selectOne(
            "SELECT id, details FROM agent_review_queue WHERE token = ? AND review_type = 'tool_proposal'",
            [$token]
        );

        if (!$review) {
            return ['success' => false, 'error' => 'Review queue entry not found for token'];
        }

        $details = json_decode($review->details, true) ?: [];
        $toolName = $details['tool_name'] ?? null;

        if (!$toolName) {
            return ['success' => false, 'error' => 'No tool_name in review details'];
        }

        // Delete the proposed tool from registry
        DB::delete("DELETE FROM agent_tool_registry WHERE name = ? AND source = 'agent_proposed'", [$toolName]);

        // Update review queue status
        DB::update(
            "UPDATE agent_review_queue SET status = 'rejected', reviewed_at = NOW(), reviewer_notes = ?, updated_at = NOW() WHERE token = ?",
            [$reason, $token]
        );

        Log::info("ToolProposal: Tool rejected", ['tool' => $toolName, 'reason' => $reason]);

        return [
            'success' => true,
            'message' => "Tool '{$toolName}' proposal rejected and removed",
            'reason' => $reason,
        ];
    }

    /**
     * Implements ReviewApprovalHandler contract.
     * Delegates to approveProposal.
     */
    public function onApprove(int $itemId, array $details): array
    {
        $toolName = $details['tool_name'] ?? null;
        if (!$toolName) {
            return ['success' => false, 'error' => 'No tool_name in review details'];
        }

        // Find the token for this review queue item
        $review = DB::selectOne("SELECT token FROM agent_review_queue WHERE id = ?", [$itemId]);
        if (!$review) {
            return ['success' => false, 'error' => 'Review queue entry not found'];
        }

        return $this->approveProposal($review->token);
    }

    /**
     * Submit a tool proposal to the review queue for human review.
     */
    private function submitForReview(string $toolName, string $agentId, array $toolDef, string $reason): array
    {
        $token = bin2hex(random_bytes(16));

        $details = [
            'tool_name' => $toolName,
            'service_class' => $toolDef['service_class'],
            'method' => $toolDef['method'],
            'description' => $toolDef['description'],
            'parameters' => $toolDef['parameters'] ?? [],
            'reason' => $reason,
        ];

        try {
            DB::insert("
                INSERT INTO agent_review_queue
                (agent_id, review_type, title, summary, details, confidence, priority, status, token, expires_at, created_at, updated_at)
                VALUES (?, 'tool_proposal', ?, ?, ?, 0.50, 1, 'pending', ?, DATE_ADD(NOW(), INTERVAL 7 DAY), NOW(), NOW())
            ", [
                $agentId,
                "Tool Proposal: {$toolName}",
                "Agent '{$agentId}' proposes new tool '{$toolName}': {$toolDef['description']}. Reason: {$reason}",
                json_encode($details),
                $token,
            ]);

            $reviewId = (int) DB::selectOne("SELECT LAST_INSERT_ID() as id")->id;

            return ['success' => true, 'review_id' => $reviewId, 'token' => $token];
        } catch (\Throwable $e) {
            Log::error("ToolProposal: Failed to create review entry", ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
