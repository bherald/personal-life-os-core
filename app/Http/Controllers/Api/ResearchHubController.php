<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgentProceduralMemoryService;
use App\Services\Genealogy\PersonService;
use App\Services\Review\ReviewContextEnrichmentService;
use App\Services\Review\ReviewTargetReferenceService;
use App\Services\ReviewTypeRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ResearchHubController - Unified Research Hub API
 *
 * Combines review functionality with agent status into a single hub.
 * Uses ReviewTypeRegistryService for pluggable review types.
 */
class ResearchHubController extends Controller
{
    private const GENEALOGY_PACKET_TARGET_REF_LOOKUP_LIMIT = 2000;

    public function __construct(
        private ReviewTypeRegistryService $registry,
        private ReviewContextEnrichmentService $enrichment,
    ) {}

    /**
     * Phase 1 of the Genealogy Review UI redesign.
     *
     * Returns the enriched detail-pane payload for a single review item:
     * raw item + on-file person dossier + per-field comparison rows.
     * The frontend's master/detail layout calls this on item selection
     * so it can render side-by-side without three round trips.
     *
     * @see App\Services\Review\ReviewContextEnrichmentService
     * @see docs/research-reviews/2026-04-23-genealogy-review-ui-redesign.md
     */
    public function context(string $unifiedId): JsonResponse
    {
        $payload = $this->enrichment->getContext($unifiedId);
        if ($payload === null) {
            return response()->json(['error' => 'Review item not found'], 404);
        }

        return response()->json($payload);
    }

    /**
     * Phase 3 of the Genealogy Review UI redesign — per-field accept.
     *
     * Operator selects which proposals to apply (accepted_indices), which
     * to reject with a structured reason code (rejected_indices +
     * reject_reason_codes), how to resolve any conflict rows
     * (conflict_resolutions), plus optional free-text notes. The audit
     * blob is captured into agent_review_queue.reviewer_notes for the
     * agent learning loop.
     *
     * Body: {
     *   "accepted_indices": [int],
     *   "rejected_indices": [int],
     *   "reject_reason_codes": {index: "wrong_person|fan_mismatch|...", ...},
     *   "conflict_resolutions": {index: "proposed|on_file", ...},
     *   "notes": "string|null"
     * }
     */
    public function applyFields(Request $request, string $unifiedId): JsonResponse
    {
        if (! preg_match('/^([a-z_]+):(\d+)$/', $unifiedId, $m)) {
            return response()->json(['success' => false, 'error' => 'Malformed unified id'], 400);
        }
        [$type, $idStr] = [$m[1], $m[2]];

        // Phase 3 only wires the genealogy_finding partial-apply path.
        // Other types fall back to whole-item approve/reject endpoints.
        if ($type !== 'genealogy_finding') {
            return response()->json([
                'success' => false,
                'error' => "Per-field apply is not yet wired for review type '{$type}' — use approve/reject",
            ], 422);
        }

        $accepted = (array) $request->input('accepted_indices', []);
        $rejected = (array) $request->input('rejected_indices', []);
        $reasonCodes = (array) $request->input('reject_reason_codes', []);
        $resolutions = (array) $request->input('conflict_resolutions', []);
        $notes = $request->input('notes');

        $result = app(PersonService::class)->applyPartialFinding(
            (int) $idStr,
            $accepted,
            $rejected,
            $reasonCodes,
            $resolutions,
            is_string($notes) ? $notes : null
        );

        // F3 fix: 200 for any successfully PROCESSED call (including
        // partial-failure and stays-pending outcomes). Pre-fix, 422 was
        // returned when final_status === 'pending' which made axios
        // throw, the 'applied' event never fired, and the
        // ResearchHubView fallback branch that handles
        // partial-pending toasts was unreachable. Reserve 4xx for
        // genuine input errors that block processing entirely
        // (out-of-range indices, no decisions supplied) — those carry
        // no final_status because nothing happened.
        $isInputError = $result['success'] === false && ! isset($result['final_status']);
        $status = $isInputError ? 400 : 200;

        return response()->json($result, $status);
    }

    /**
     * Get all registered review types and their configuration
     */
    public function types(): JsonResponse
    {
        $types = $this->registry->getTypes();
        $byCategory = $this->registry->getTypesByCategory();

        return response()->json([
            'types' => $types,
            'categories' => $byCategory,
        ]);
    }

    /**
     * Get stats for all review types + agent status
     */
    public function stats(): JsonResponse
    {
        $reviewStats = $this->registry->getAllStats();
        $agentStats = $this->getAgentStats();

        return response()->json([
            'reviews' => $reviewStats,
            'agents' => $agentStats,
            'total_pending' => $reviewStats['_total'] ?? 0,
        ]);
    }

    /**
     * Get all pending items (optionally filtered by category)
     */
    public function items(Request $request): JsonResponse
    {
        $category = $request->query('category');
        $type = $request->query('type');
        $limit = min((int) $request->query('limit', 25), 500);
        $offset = max((int) $request->query('offset', 0), 0);
        $includeExpired = (bool) $request->query('include_expired', false);

        // Hide low-confidence research findings from the human review surface
        // until research raises them above the threshold. NULL-confidence
        // items (system alerts, non-scored types) always pass through.
        // Default 0.55 matches the operator's expected visibility cutoff;
        // pass min_confidence=0 to disable.
        $minConfidenceRaw = $request->query('min_confidence');
        $minConfidence = $minConfidenceRaw === null
            ? (float) config('review_queue.min_confidence', 0.55)
            : (float) $minConfidenceRaw;
        if ($minConfidence <= 0.0) {
            $minConfidence = null;
        }

        // If specific type requested
        if ($type) {
            $result = $this->registry->fetchItems($type, $limit, $offset, $minConfidence);
            if ($includeExpired) {
                $result = $this->mergeExpiredItems($result, $type, $limit, $offset);
            }

            return response()->json([
                'items' => $result['items'] ?? [],
                'total' => $result['total'] ?? 0,
                'has_more' => $result['has_more'] ?? false,
                'stats' => $this->registry->getAllStats(),
                'types' => $this->registry->getTypes(),
                'min_confidence' => $minConfidence,
            ]);
        }

        // Otherwise fetch all (optionally filtered by category)
        $result = $this->registry->fetchAllItems($category, $limit, $offset, $minConfidence);
        if ($includeExpired) {
            $result = $this->mergeExpiredItems($result, null, $limit, $offset);
        }
        $stats = $this->registry->getAllStats();

        // INF-10c: Enrich items with remediation availability
        $items = $this->enrichItemsWithRemediation($result['items']);

        // UI-6: Enrich items with tree name
        $items = $this->enrichItemsWithTreeName($items);

        return response()->json([
            'items' => $items,
            'total' => $result['total'],
            'has_more' => $result['has_more'],
            'stats' => $stats,
            'types' => $this->registry->getTypes(),
            'min_confidence' => $minConfidence,
        ]);
    }

    /**
     * Resolve a sanitized Review Hub target_ref to the current pending packet.
     *
     * This keeps operator handoff links based on target refs instead of row ids
     * or tokens. It returns the same display item shape as /items and does not
     * review, apply, or mutate genealogy records.
     */
    public function itemByTargetRef(Request $request): JsonResponse
    {
        $targetRef = $this->normalizeGenealogyPacketTargetRef($request->query('target_ref'));
        if ($targetRef === null) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid target_ref',
            ], 422);
        }

        $result = $this->registry->fetchItems('genealogy_review_packet', 500, 0, null);
        $items = $this->enrichItemsWithTreeName(
            $this->enrichItemsWithRemediation($result['items'] ?? [])
        );

        foreach ($items as $item) {
            if (! $this->isCurrentPendingGenealogyPacketItem($item)) {
                continue;
            }

            if ($this->itemTargetRef($item) === $targetRef) {
                return response()->json([
                    'success' => true,
                    'target_ref' => $targetRef,
                    'item' => $item,
                ]);
            }
        }

        $item = $this->directPendingGenealogyPacketItemByTargetRef($targetRef);
        if ($item !== null) {
            return response()->json([
                'success' => true,
                'target_ref' => $targetRef,
                'item' => $item,
            ]);
        }

        return response()->json([
            'success' => false,
            'target_ref' => $targetRef,
            'error' => 'Review packet target not found',
        ], 404);
    }

    /**
     * Approve a single item
     */
    public function approve(Request $request, string $unifiedId): JsonResponse
    {
        $notes = $request->input('notes');
        $result = $this->registry->approveItem(
            $unifiedId,
            is_string($notes) ? $notes : null,
            $this->optionalRequestString($request, 'reason_code')
        );
        $status = $result['success']
            ? 200
            : (($result['requires_materialization'] ?? false) ? 422 : 400);

        return response()->json($result, $status);
    }

    /**
     * Reject a single item
     */
    public function reject(Request $request, string $unifiedId): JsonResponse
    {
        $reason = $request->input('reason');
        $result = $this->registry->rejectItem(
            $unifiedId,
            is_string($reason) ? $reason : null,
            $this->optionalRequestString($request, 'reason_code')
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Request clarification for a single item.
     */
    public function clarify(Request $request, string $unifiedId): JsonResponse
    {
        $notes = $request->input('notes');
        $result = $this->registry->clarifyItem(
            $unifiedId,
            is_string($notes) ? $notes : null,
            $this->optionalRequestString($request, 'reason_code')
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Defer a single item.
     */
    public function defer(Request $request, string $unifiedId): JsonResponse
    {
        $notes = $request->input('notes');
        $result = $this->registry->deferItem(
            $unifiedId,
            is_string($notes) ? $notes : null,
            $this->optionalRequestString($request, 'reason_code')
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    private function optionalRequestString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function normalizeGenealogyPacketTargetRef(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (preg_match('/genealogy_review_packet:target-[a-f0-9]{12}/i', $text, $matches) === 1) {
            return strtolower($matches[0]);
        }

        if (preg_match('/target-[a-f0-9]{12}/i', $text, $matches) === 1) {
            return 'genealogy_review_packet:'.strtolower($matches[0]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemTargetRef(array $item): ?string
    {
        return $this->normalizeGenealogyPacketTargetRef($item['target_ref'] ?? null)
            ?? $this->normalizeGenealogyPacketTargetRef($item['review_focus']['target_ref'] ?? null)
            ?? $this->normalizeGenealogyPacketTargetRef($item['details']['target_ref'] ?? null);
    }

    /**
     * Target-ref links are current-pending packet pointers only. Recheck the
     * mapped item defensively so stale registry SQL or DB/app timezone drift
     * cannot expose terminal or expired packet details through an exact link.
     *
     * @param  array<string, mixed>  $item
     */
    private function isCurrentPendingGenealogyPacketItem(array $item): bool
    {
        $status = $item['status'] ?? null;
        if (is_scalar($status) && trim((string) $status) !== '' && strtolower(trim((string) $status)) !== 'pending') {
            return false;
        }

        $expiresAt = $item['expires_at'] ?? null;
        if (! is_scalar($expiresAt) || trim((string) $expiresAt) === '') {
            return true;
        }

        $expiresAtTimestamp = strtotime((string) $expiresAt);
        if ($expiresAtTimestamp === false) {
            return false;
        }

        return $expiresAtTimestamp > now()->getTimestamp();
    }

    private function directPendingGenealogyPacketItemByTargetRef(string $targetRef): ?array
    {
        $rows = DB::table('agent_review_queue')
            ->select([
                'id',
                'agent_id',
                'review_type',
                'title',
                'summary',
                'details',
                'confidence',
                'priority',
                'token',
                'expires_at',
                'created_at',
            ])
            ->where('status', 'pending')
            ->where('review_type', 'genealogy_review_packet')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->limit(self::GENEALOGY_PACKET_TARGET_REF_LOOKUP_LIMIT)
            ->get()
            ->all();

        if ($rows === []) {
            return null;
        }

        $targetReference = app(ReviewTargetReferenceService::class);
        foreach ($rows as $row) {
            if ($targetReference->forReviewRow($row, 'genealogy_review_packet') !== $targetRef) {
                continue;
            }

            $items = $this->enrichItemsWithTreeName(
                $this->enrichItemsWithRemediation(
                    $this->registry->mapRowsForDisplay('genealogy_review_packet', [$row])
                )
            );

            return $items[0] ?? null;
        }

        return null;
    }

    /**
     * Quick approve via GET (Pushover action button callback).
     * Returns human-friendly HTML confirmation for mobile browser.
     */
    public function quickApprove(string $unifiedId)
    {
        $item = $this->lookupReviewItem($unifiedId);
        $result = $this->registry->approveItem($unifiedId, 'Approved via Pushover');

        if ($result['success']) {
            return $this->renderActionPage('approved', $item, $unifiedId);
        }

        return $this->renderActionPage('failed', $item, $unifiedId, $result['error'] ?? 'Unknown error');
    }

    /**
     * Quick reject via GET (Pushover action button callback).
     */
    public function quickReject(string $unifiedId)
    {
        $item = $this->lookupReviewItem($unifiedId);
        $result = $this->registry->rejectItem($unifiedId, 'Rejected via Pushover');

        if ($result['success']) {
            return $this->renderActionPage('rejected', $item, $unifiedId);
        }

        return $this->renderActionPage('failed', $item, $unifiedId, $result['error'] ?? 'Unknown error');
    }

    /**
     * Quick view via GET (Pushover View button).
     * Shows item details with approve/reject action buttons.
     */
    public function quickView(string $unifiedId)
    {
        $item = $this->lookupReviewItem($unifiedId);

        if (! $item) {
            return $this->renderActionPage('failed', null, $unifiedId, 'Item not found');
        }

        return $this->renderViewPage($item, $unifiedId);
    }

    /**
     * Look up review item details for human-friendly display.
     */
    private function lookupReviewItem(string $unifiedId): ?object
    {
        $parts = explode(':', $unifiedId, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$type, $id] = $parts;
        $column = $this->resolveLookupColumn($type, $id);

        return DB::selectOne(
            'SELECT agent_id, review_type, title, summary, details, confidence, priority, status
             FROM agent_review_queue WHERE '.$column.' = ?',
            [$id]
        );
    }

    private function resolveLookupColumn(string $typeName, string $id): string
    {
        try {
            $type = $this->registry->getType($typeName);
            $template = (string) ($type['field_mapping']['unified_id_template'] ?? '');
            if (preg_match('/\{\{(\w+)\}\}/', $template, $matches) === 1) {
                $column = $matches[1];
                if ($column !== 'id' && ctype_digit($id)) {
                    return 'id';
                }

                return in_array($column, ['id', 'token'], true) ? $column : 'id';
            }
        } catch (\Throwable) {
        }

        return 'id';
    }

    /**
     * Render a mobile-friendly HTML confirmation page after approve/reject.
     */
    private function renderActionPage(string $action, ?object $item, string $unifiedId, ?string $error = null): \Illuminate\Http\Response
    {
        $agentLabels = [
            'genealogy-researcher' => 'Genealogy Researcher',
            'file-ops' => 'File Operations',
            'log-analyst' => 'Log Analyst',
            'ai-ops' => 'AI Operations',
            'system-guardian' => 'System Guardian',
            'research-ops' => 'Research Operations',
            'email-ops' => 'Email Operations',
            'file-curator' => 'File Curator',
            'youtube-ops' => 'YouTube Operations',
            'workflow-ops' => 'Workflow Operations',
            'knowledge-curator' => 'Knowledge Curator',
            'factcheck-ops' => 'Fact Check Operations',
            'data-removal-ops' => 'Data Removal',
            'research-analyst' => 'Research Analyst',
        ];

        $typeLabels = [
            'genealogy_finding' => 'Genealogy Finding',
            'genealogy_merge' => 'Genealogy Merge',
            'finding' => 'Finding',
            'alert' => 'Alert',
            'tool_proposal' => 'Tool Proposal',
            'skill_optimization' => 'Skill Optimization',
            'log_analyst_finding' => 'Log Analysis',
            'status' => 'Status Update',
            'suggestion' => 'Suggestion',
        ];

        $title = $item->title ?? 'Review Item';
        $agentName = $agentLabels[$item->agent_id ?? ''] ?? ucwords(str_replace('-', ' ', $item->agent_id ?? 'System'));
        $typeName = $typeLabels[$item->review_type ?? ''] ?? ucwords(str_replace('_', ' ', $item->review_type ?? 'Item'));
        $confidence = $item->confidence !== null ? round($item->confidence * 100).'%' : '';
        $summary = htmlspecialchars($this->summarizeItemForPage($item, 400));
        $summary = nl2br($summary);

        if ($action === 'approved') {
            $icon = '✓';
            $color = '#00ff88';
            $heading = 'Approved';
            $statusCode = 200;
        } elseif ($action === 'rejected') {
            $icon = '✗';
            $color = '#ff6b6b';
            $heading = 'Rejected';
            $statusCode = 200;
        } else {
            $icon = '⚠';
            $color = '#ffaa00';
            $heading = 'Failed';
            $summary = htmlspecialchars($error ?? 'Unknown error');
            $statusCode = 400;
        }

        $viewUrl = rtrim((string) config('app.public_url', config('app.url')), '/').'/research-hub';

        $html = <<<HTML
<!DOCTYPE html>
<html><head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PLOS Review — {$heading}</title>
<style>
  body { font-family: -apple-system, system-ui, sans-serif; background: #0d1117; color: #e6edf3; margin: 0; padding: 1.5em; }
  .card { max-width: 500px; margin: 0 auto; background: #161b22; border-radius: 12px; padding: 1.5em; border: 1px solid #30363d; }
  .status { text-align: center; font-size: 2.5em; margin: 0.3em 0; }
  .heading { text-align: center; color: {$color}; font-size: 1.4em; font-weight: 600; margin: 0 0 1em; }
  .label { color: #8b949e; font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 1em; }
  .value { color: #e6edf3; font-size: 0.95em; margin: 0.2em 0 0.8em; line-height: 1.5; }
  .meta { display: flex; gap: 1.5em; flex-wrap: wrap; }
  .meta-item { flex: 1; min-width: 100px; }
  .divider { border-top: 1px solid #30363d; margin: 1em 0; }
  .btn { display: block; text-align: center; background: #21262d; color: #58a6ff; padding: 0.8em; border-radius: 8px; text-decoration: none; margin-top: 1em; font-size: 0.9em; }
  .close-hint { text-align: center; color: #484f58; font-size: 0.8em; margin-top: 1.5em; }
</style>
</head><body>
<div class="card">
  <div class="status">{$icon}</div>
  <div class="heading">{$heading}</div>

  <div class="label">Item</div>
  <div class="value">{$title}</div>

  <div class="meta">
    <div class="meta-item">
      <div class="label">Agent</div>
      <div class="value">{$agentName}</div>
    </div>
    <div class="meta-item">
      <div class="label">Type</div>
      <div class="value">{$typeName}</div>
    </div>
    <div class="meta-item">
      <div class="label">Confidence</div>
      <div class="value">{$confidence}</div>
    </div>
  </div>

  <div class="divider"></div>

  <div class="label">Summary</div>
  <div class="value" style="font-size:0.85em; color:#8b949e;">{$summary}</div>

  <a class="btn" href="{$viewUrl}">Open Review Hub</a>
</div>
<div class="close-hint">You can close this tab.</div>
</body></html>
HTML;

        return response($html, $statusCode, ['Content-Type' => 'text/html']);
    }

    /**
     * Render a mobile-friendly detail view page with approve/reject buttons.
     */
    private function renderViewPage(object $item, string $unifiedId): \Illuminate\Http\Response
    {
        $agentLabels = [
            'genealogy-researcher' => 'Genealogy Researcher',
            'file-ops' => 'File Operations',
            'log-analyst' => 'Log Analyst',
            'ai-ops' => 'AI Operations',
            'system-guardian' => 'System Guardian',
            'research-ops' => 'Research Operations',
            'email-ops' => 'Email Operations',
            'file-curator' => 'File Curator',
            'youtube-ops' => 'YouTube Operations',
            'workflow-ops' => 'Workflow Operations',
            'knowledge-curator' => 'Knowledge Curator',
            'factcheck-ops' => 'Fact Check Operations',
            'data-removal-ops' => 'Data Removal',
            'research-analyst' => 'Research Analyst',
        ];

        $typeLabels = [
            'genealogy_finding' => 'Genealogy Finding',
            'genealogy_merge' => 'Genealogy Merge',
            'finding' => 'Finding',
            'alert' => 'Alert',
            'tool_proposal' => 'Tool Proposal',
            'skill_optimization' => 'Skill Optimization',
            'log_analyst_finding' => 'Log Analysis',
            'status' => 'Status Update',
            'suggestion' => 'Suggestion',
        ];

        $title = htmlspecialchars($item->title ?? 'Review Item');
        $agentName = $agentLabels[$item->agent_id ?? ''] ?? ucwords(str_replace('-', ' ', $item->agent_id ?? 'System'));
        $typeName = $typeLabels[$item->review_type ?? ''] ?? ucwords(str_replace('_', ' ', $item->review_type ?? 'Item'));
        $confidence = $item->confidence !== null ? round($item->confidence * 100).'%' : '—';
        $status = htmlspecialchars($item->status ?? 'pending');
        $summary = htmlspecialchars($this->summarizeItemForPage($item));
        $summary = nl2br($summary);

        $baseUrl = rtrim((string) config('app.public_url', config('app.url')), '/');
        $approveUrl = "{$baseUrl}/api/research-hub/quick-approve/{$unifiedId}";
        $rejectUrl = "{$baseUrl}/api/research-hub/quick-reject/{$unifiedId}";
        $hubUrl = "{$baseUrl}/research-hub?unified_id=".rawurlencode($unifiedId);

        $isPending = ($item->status ?? '') === 'pending';
        $actionButtons = $isPending
            ? "<a class='btn approve' href='{$approveUrl}'>Approve</a><a class='btn reject' href='{$rejectUrl}'>Reject</a>"
            : "<div class='resolved'>Already {$status}</div>";

        $priorityBadge = match ((int) ($item->priority ?? 0)) {
            2 => "<span style='color:#ff4444;font-weight:600'>URGENT</span>",
            1 => "<span style='color:#ffaa00;font-weight:600'>HIGH</span>",
            default => "<span style='color:#8b949e'>Normal</span>",
        };

        $html = <<<HTML
<!DOCTYPE html>
<html><head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PLOS Review — {$title}</title>
<style>
  body { font-family: -apple-system, system-ui, sans-serif; background: #0d1117; color: #e6edf3; margin: 0; padding: 1.5em; }
  .card { max-width: 500px; margin: 0 auto; background: #161b22; border-radius: 12px; padding: 1.5em; border: 1px solid #30363d; }
  .title { font-size: 1.2em; font-weight: 600; margin: 0 0 1em; line-height: 1.3; }
  .label { color: #8b949e; font-size: 0.75em; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0.8em; }
  .value { color: #e6edf3; font-size: 0.9em; margin: 0.2em 0 0.6em; }
  .meta { display: flex; gap: 1em; flex-wrap: wrap; }
  .meta-item { flex: 1; min-width: 80px; }
  .divider { border-top: 1px solid #30363d; margin: 1em 0; }
  .summary { color: #c9d1d9; font-size: 0.85em; line-height: 1.6; max-height: 400px; overflow-y: auto; }
  .actions { display: flex; gap: 0.8em; margin-top: 1.2em; }
  .btn { flex: 1; text-align: center; padding: 0.9em; border-radius: 8px; text-decoration: none; font-size: 0.95em; font-weight: 600; }
  .approve { background: #238636; color: #fff; }
  .reject { background: #da3633; color: #fff; }
  .resolved { text-align: center; color: #8b949e; font-style: italic; padding: 0.8em; }
  .hub-link { display: block; text-align: center; color: #58a6ff; margin-top: 1em; font-size: 0.85em; text-decoration: none; }
</style>
</head><body>
<div class="card">
  <div class="title">{$title}</div>

  <div class="meta">
    <div class="meta-item">
      <div class="label">Agent</div>
      <div class="value">{$agentName}</div>
    </div>
    <div class="meta-item">
      <div class="label">Type</div>
      <div class="value">{$typeName}</div>
    </div>
    <div class="meta-item">
      <div class="label">Confidence</div>
      <div class="value">{$confidence}</div>
    </div>
    <div class="meta-item">
      <div class="label">Priority</div>
      <div class="value">{$priorityBadge}</div>
    </div>
  </div>

  <div class="divider"></div>

  <div class="label">Summary</div>
  <div class="summary">{$summary}</div>

  <div class="divider"></div>

  <div class="actions">{$actionButtons}</div>
  <a class="hub-link" href="{$hubUrl}">Open Full Review Hub</a>
</div>
</body></html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html']);
    }

    private function sanitizeReviewSummaryForPage(?string $summary, int $limit = 1200): string
    {
        $clean = $this->normalizeReviewSummaryText((string) $summary);
        if ($clean === '') {
            return '';
        }

        $jsonOffset = $this->findStructuredPayloadOffset($clean);
        if ($jsonOffset !== null) {
            $prefix = rtrim(substr($clean, 0, $jsonOffset));
            $clean = $prefix !== ''
                ? "{$prefix} [structured data]"
                : '[structured data]';
        }

        return mb_substr($clean, 0, $limit);
    }

    private function normalizeReviewSummaryText(string $summary): string
    {
        $clean = trim($summary);
        if ($clean === '') {
            return '';
        }

        $clean = str_replace(["\r\n", "\r"], "\n", $clean);
        $clean = preg_replace('/[ \t]+/', ' ', $clean) ?? $clean;
        $clean = preg_replace("/ *\n */", "\n", $clean) ?? $clean;
        $clean = preg_replace("/\n{3,}/", "\n\n", $clean) ?? $clean;

        return trim($clean);
    }

    private function summarizeItemForPage(object $item, int $limit = 1200): string
    {
        $decorated = $this->registry->decorateItemForDisplay((string) ($item->review_type ?? ''), [
            'summary' => $item->summary ?? '',
            'details' => json_decode($item->details ?? 'null', true),
        ]);

        $displaySummary = (string) ($decorated['details_human'] ?? $decorated['summary'] ?? $item->summary ?? '');

        return $this->sanitizeReviewSummaryForPage($displaySummary, $limit);
    }

    private function findStructuredPayloadOffset(string $summary): ?int
    {
        $candidates = [
            '/\{\s*\\\\?"success"\s*:/i',
            '/\{\s*"success"\s*:/i',
            '/\{\s*\\\\?"query"\s*:/i',
            '/\{\s*"query"\s*:/i',
            '/\{\s*\\\\?"sources_searched"\s*:/i',
            '/\{\s*"sources_searched"\s*:/i',
        ];

        foreach ($candidates as $pattern) {
            if (preg_match($pattern, $summary, $matches, PREG_OFFSET_CAPTURE) === 1) {
                return $matches[0][1];
            }
        }

        return null;
    }

    /**
     * Ignore a single item (prevents re-discovery)
     */
    public function ignore(Request $request, string $unifiedId): JsonResponse
    {
        $result = $this->registry->ignoreItem($unifiedId);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Revive an expired or rejected item back to pending
     */
    public function revive(string $unifiedId): JsonResponse
    {
        $parts = explode(':', $unifiedId, 2);
        if (count($parts) !== 2) {
            return response()->json(['success' => false, 'error' => 'Invalid unified ID format'], 400);
        }

        [$prefix, $id] = $parts;
        $newExpiry = now()->addDays(7)->format('Y-m-d H:i:s');
        $count = 0;

        try {
            switch ($prefix) {
                case 'agent':
                    // Token-based: revive all expired items for this agent token
                    $count = DB::update(
                        "UPDATE agent_review_queue
                         SET status = 'pending', expires_at = ?, updated_at = NOW()
                         WHERE token = ? AND status IN ('expired', 'rejected')",
                        [$newExpiry, $id]
                    );
                    if ($count === 0) {
                        // Try as agent_id
                        $count = app(\App\Services\AgentLoopService::class)->reviveExpiredItems($id);
                    }
                    break;

                case 'change':
                    try {
                        $count = DB::update(
                            "UPDATE genealogy_proposed_relationships
                             SET status = 'pending', updated_at = NOW()
                             WHERE id = ? AND status IN ('expired', 'rejected')",
                            [$id]
                        );
                    } catch (\Exception $e) {
                        Log::warning('ResearchHub: revive change failed', ['id' => $id, 'error' => $e->getMessage()]);
                        $count = 0;
                    }
                    break;

                case 'proposal':
                    $count = DB::update(
                        "UPDATE genealogy_proposed_relationships
                         SET status = 'pending', updated_at = NOW()
                         WHERE id = ? AND status IN ('expired', 'rejected')",
                        [$id]
                    );
                    break;

                default:
                    return response()->json(['success' => false, 'error' => "Unknown prefix: {$prefix}"], 400);
            }

            return response()->json([
                'success' => $count > 0,
                'revived' => $count,
            ]);
        } catch (\Throwable $e) {
            Log::error('ResearchHub: Revive failed', ['unified_id' => $unifiedId, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Merge expired agent_review_queue items into a result set.
     * Enriches each expired row with ui_schema/color/source from its registry entry
     * so DynamicReviewCard can render it the same as a live item.
     */
    private function mergeExpiredItems(array $result, ?string $type, int $limit, int $offset): array
    {
        try {
            $params = [];
            $typeClause = '';
            if ($type) {
                $typeClause = ' AND review_type = ?';
                $params[] = $type;
            }
            $expired = DB::select(
                "SELECT id, agent_id, review_type, title, summary, details, confidence, priority,
                        status, token, expires_at, created_at, updated_at
                 FROM agent_review_queue
                 WHERE (status = 'expired' OR (status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW()))
                 {$typeClause}
                 ORDER BY created_at DESC
                 LIMIT 100",
                $params
            );

            if (empty($expired)) {
                return $result;
            }

            $registryTypes = $this->registry->getTypes();

            $expiredItems = array_map(function ($row) use ($registryTypes) {
                $typeName = (string) $row->review_type;
                $typeDef = $registryTypes[$typeName] ?? null;
                $details = is_string($row->details ?? null) ? json_decode($row->details, true) : ($row->details ?? null);

                return [
                    'unified_id' => 'agent:'.$row->token,
                    'type' => $typeName,
                    'source' => $typeDef['name'] ?? $typeName,
                    'category' => $typeDef['category'] ?? 'agent',
                    'ui_schema' => $typeDef['ui_schema'] ?? null,
                    'vue_renderer' => $typeDef['vue_renderer'] ?? null,
                    'color' => $typeDef['color'] ?? null,
                    'actions' => $typeDef['actions'] ?? [],
                    'batch_enabled' => (bool) ($typeDef['batch_enabled'] ?? false),
                    'title' => $row->title,
                    'summary' => $row->summary,
                    'details' => $details,
                    'review_type' => $typeName,
                    'agent_id' => $row->agent_id,
                    'confidence' => $row->confidence,
                    'priority' => $row->priority,
                    'status' => 'expired',
                    'created_at' => $row->created_at,
                    'expires_at' => $row->expires_at,
                ];
            }, $expired);

            $result['items'] = array_merge($result['items'], $expiredItems);
            $result['total'] += count($expiredItems);

            return $result;
        } catch (\Throwable $e) {
            Log::warning('ResearchHub: Failed to merge expired items', ['error' => $e->getMessage()]);

            return $result;
        }
    }

    /**
     * Batch approve multiple items
     */
    public function batchApprove(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);
        $notes = $request->input('notes');

        if (empty($ids)) {
            return response()->json(['success' => false, 'error' => 'No IDs provided'], 400);
        }

        $results = $this->registry->batchApprove($ids, $notes);
        $successCount = count(array_filter($results, fn ($r) => $r['success'] ?? false));

        return response()->json([
            'success' => $successCount > 0,
            'processed' => count($ids),
            'succeeded' => $successCount,
            'failed' => count($ids) - $successCount,
            'results' => $results,
        ]);
    }

    /**
     * Batch reject multiple items
     */
    public function batchReject(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);
        $reason = $request->input('reason');

        if (empty($ids)) {
            return response()->json(['success' => false, 'error' => 'No IDs provided'], 400);
        }

        $results = $this->registry->batchReject($ids, $reason);
        $successCount = count(array_filter($results, fn ($r) => $r['success'] ?? false));

        return response()->json([
            'success' => $successCount > 0,
            'processed' => count($ids),
            'succeeded' => $successCount,
            'failed' => count($ids) - $successCount,
            'results' => $results,
        ]);
    }

    /**
     * Get agent status for the hub dashboard.
     *
     * Item 1 of the 2026-04-23 batch sprint: bundle reviewer-feedback
     * (per-agent acceptance rate over the last 30 days) into the same
     * payload so the hub UI can render it next to the live counters.
     * The morning digest already shows this; the UI was the missing
     * surface. Failure to compute reviewer feedback never breaks the
     * status response — falls back to an empty array.
     */
    public function agentStatus(): JsonResponse
    {
        $stats = $this->getAgentStats();
        try {
            $stats['reviewer_feedback'] = app(AgentProceduralMemoryService::class)
                ->getReviewerFeedbackForAllAgents(30);
        } catch (\Throwable $e) {
            Log::debug('ResearchHub: reviewer feedback rollup failed', ['error' => $e->getMessage()]);
            $stats['reviewer_feedback'] = [];
        }

        return response()->json($stats);
    }

    /**
     * Read-only reviewer feedback endpoint for structured genealogy reject
     * codes. Keeps the Research Hub panel and CLI using the same source blobs.
     */
    public function reviewerFeedback(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        if ($days < 1 || $days > 365) {
            return response()->json([
                'success' => false,
                'error' => 'days must be between 1 and 365',
            ], 422);
        }

        $agent = trim((string) $request->query('agent', ''));
        $daily = filter_var($request->query('daily', false), FILTER_VALIDATE_BOOL);
        $memory = app(AgentProceduralMemoryService::class);

        $payload = [
            'success' => true,
            'generated_at' => now()->toIso8601String(),
            'window_days' => $days,
            'agent' => $agent !== '' ? $agent : null,
        ];

        if ($agent !== '') {
            $payload['summary'] = $memory->getReviewerFeedbackSummary($agent, $days);
        } else {
            $payload['summaries'] = $memory->getReviewerFeedbackForAllAgents($days);
        }

        if ($daily) {
            $payload['daily_rollup'] = $memory->getReviewerFeedbackDailyRollup(
                $days,
                $agent !== '' ? $agent : null
            );
        }

        return response()->json($payload);
    }

    /**
     * Get recent agent activity
     */
    public function agentActivity(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 20), 100);

        try {
            $sessions = DB::select("
                SELECT id, agent_name, session_type, status, total_tokens,
                       message_count, created_at, updated_at
                FROM agent_sessions
                WHERE session_type = 'agent'
                ORDER BY created_at DESC
                LIMIT ?
            ", [$limit]);

            $toolCalls = DB::select("
                SELECT agent_id, session_id, summary,
                       JSON_UNQUOTE(JSON_EXTRACT(details, '$.tool')) as tool_name,
                       duration_ms, created_at
                FROM agent_episodes
                WHERE event_type = 'tool_call'
                ORDER BY created_at DESC
                LIMIT ?
            ", [$limit]);

            return response()->json([
                'sessions' => $sessions,
                'tool_calls' => $toolCalls,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ResearchHub: Failed to get agent activity', ['error' => $e->getMessage()]);

            return response()->json([
                'sessions' => [],
                'tool_calls' => [],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Recent episodes for a specific agent (used by "View agent session" link)
     */
    public function agentEpisodes(Request $request, string $agentId): JsonResponse
    {
        $limit = min((int) $request->query('limit', 15), 50);

        try {
            $episodes = DB::select("
                SELECT id, event_type, summary, tokens_used, duration_ms, session_id, created_at
                FROM agent_episodes
                WHERE agent_id = ? AND event_type != 'tool_call'
                ORDER BY created_at DESC
                LIMIT ?
            ", [$agentId, $limit]);

            return response()->json(['episodes' => $episodes, 'agent_id' => $agentId]);
        } catch (\Throwable $e) {
            Log::warning('ResearchHub: Failed to get agent episodes', ['agent' => $agentId, 'error' => $e->getMessage()]);

            return response()->json(['episodes' => [], 'agent_id' => $agentId]);
        }
    }

    /**
     * Agent Reports Dashboard — aggregated metrics for all agents
     */
    public function agentReports(Request $request): JsonResponse
    {
        $range = $request->query('range', '24h');
        $interval = match ($range) {
            '1h' => 'INTERVAL 1 HOUR',
            '7d' => 'INTERVAL 7 DAY',
            '30d' => 'INTERVAL 30 DAY',
            default => 'INTERVAL 24 HOUR',
        };

        try {
            // Per-agent summary: runs, completions, errors, avg duration, tool calls
            $agentSummary = DB::select("
                SELECT
                    agent_id,
                    COUNT(CASE WHEN event_type = 'task_started' THEN 1 END) as total_runs,
                    COUNT(CASE WHEN event_type = 'task_completed' THEN 1 END) as completed,
                    COUNT(CASE WHEN event_type = 'error' THEN 1 END) as errors,
                    COUNT(CASE WHEN event_type = 'tool_call' THEN 1 END) as tool_calls,
                    COUNT(CASE WHEN event_type = 'hallucination_blocked' THEN 1 END) as hallucinations,
                    ROUND(AVG(CASE WHEN event_type = 'task_completed' THEN duration_ms END)) as avg_duration_ms,
                    MAX(created_at) as last_activity
                FROM agent_episodes
                WHERE created_at > DATE_SUB(NOW(), {$interval})
                GROUP BY agent_id
                ORDER BY total_runs DESC
            ");

            // Tool usage frequency
            $toolUsage = DB::select("
                SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(details, '$.tool')) as tool_name,
                    agent_id,
                    COUNT(*) as call_count
                FROM agent_episodes
                WHERE event_type = 'tool_call'
                  AND created_at > DATE_SUB(NOW(), {$interval})
                GROUP BY tool_name, agent_id
                ORDER BY call_count DESC
                LIMIT 50
            ");

            // Timeline: hourly event counts for chart
            $timelineFormat = $range === '30d' ? '%Y-%m-%d' : ($range === '7d' ? '%Y-%m-%d %H:00' : '%Y-%m-%d %H:00');
            $timeline = DB::select("
                SELECT
                    DATE_FORMAT(created_at, ?) as period,
                    COUNT(CASE WHEN event_type = 'task_completed' THEN 1 END) as completed,
                    COUNT(CASE WHEN event_type = 'error' THEN 1 END) as errors,
                    COUNT(CASE WHEN event_type = 'tool_call' THEN 1 END) as tool_calls
                FROM agent_episodes
                WHERE created_at > DATE_SUB(NOW(), {$interval})
                GROUP BY period
                ORDER BY period
            ", [$timelineFormat]);

            // Scheduled job status with last run info
            $schedules = DB::select("
                SELECT sj.id, sj.name, sj.cron_expression, sj.enabled,
                       sjr.status as last_status, sjr.started_at as last_run,
                       sjr.duration_seconds as last_duration
                FROM scheduled_jobs sj
                LEFT JOIN (
                    SELECT scheduled_job_id, status, started_at, duration_seconds,
                           ROW_NUMBER() OVER (PARTITION BY scheduled_job_id ORDER BY started_at DESC) as rn
                    FROM scheduled_job_runs
                ) sjr ON sjr.scheduled_job_id = sj.id AND sjr.rn = 1
                WHERE sj.name LIKE '%agent%'
                ORDER BY sj.name
            ");

            // Overall totals
            $totals = DB::selectOne("
                SELECT
                    COUNT(CASE WHEN event_type = 'task_started' THEN 1 END) as total_runs,
                    COUNT(CASE WHEN event_type = 'task_completed' THEN 1 END) as completed,
                    COUNT(CASE WHEN event_type = 'error' THEN 1 END) as errors,
                    COUNT(CASE WHEN event_type = 'tool_call' THEN 1 END) as tool_calls,
                    COUNT(DISTINCT agent_id) as active_agents
                FROM agent_episodes
                WHERE created_at > DATE_SUB(NOW(), {$interval})
            ");

            return response()->json([
                'range' => $range,
                'totals' => $totals,
                'agents' => $agentSummary,
                'tool_usage' => $toolUsage,
                'timeline' => $timeline,
                'schedules' => $schedules,
            ]);
        } catch (\Throwable $e) {
            Log::error('AgentReports: Failed to get reports', ['error' => $e->getMessage()]);

            return response()->json([
                'error' => $e->getMessage(),
                'range' => $range,
                'totals' => null,
                'agents' => [],
                'tool_usage' => [],
                'timeline' => [],
                'schedules' => [],
            ], 500);
        }
    }

    /**
     * Get agent handoff statistics and recent history
     */
    public function agentHandoffs(Request $request): JsonResponse
    {
        $range = $request->query('range', '24h');
        $hours = match ($range) {
            '1h' => 1,
            '7d' => 168,
            '30d' => 720,
            default => 24,
        };

        try {
            $handoffService = app(\App\Services\AgentHandoffService::class);
            $stats = $handoffService->getStats($hours);
            $history = $handoffService->getHandoffHistory(50);

            // Get registered agents for the UI
            $agents = $handoffService->getAgents();
            $rules = $handoffService->getRoutingRules();

            return response()->json([
                'range' => $range,
                'stats' => $stats,
                'history' => $history,
                'agents' => array_values(array_map(fn ($a) => [
                    'agent_id' => $a['agent_id'],
                    'name' => $a['name'],
                    'capabilities' => $a['capabilities'],
                    'is_active' => $a['is_active'],
                ], $agents)),
                'routing_rules_count' => count($rules),
            ]);
        } catch (\Throwable $e) {
            Log::error('AgentHandoffs: Failed to get stats', ['error' => $e->getMessage()]);

            return response()->json([
                'error' => $e->getMessage(),
                'stats' => [],
                'history' => [],
                'agents' => [],
                'routing_rules_count' => 0,
            ], 500);
        }
    }

    /**
     * Get scheduled jobs for agent/research display
     */
    public function scheduledJobs(): JsonResponse
    {
        try {
            $jobs = DB::select("
                SELECT sj.id, sj.name, sj.cron_expression, sj.enabled, sj.category,
                       sjr.status as last_status, sjr.started_at as last_run, sjr.duration_seconds as last_duration
                FROM scheduled_jobs sj
                LEFT JOIN (
                    SELECT scheduled_job_id, status, started_at, duration_seconds,
                           ROW_NUMBER() OVER (PARTITION BY scheduled_job_id ORDER BY started_at DESC) as rn
                    FROM scheduled_job_runs
                ) sjr ON sjr.scheduled_job_id = sj.id AND sjr.rn = 1
                WHERE sj.enabled = 1
                  AND (sj.category LIKE '%agent%' OR sj.category LIKE '%research%' OR sj.category LIKE '%genealogy%')
                ORDER BY sj.category, sj.name
            ");

            return response()->json(['jobs' => $jobs]);
        } catch (\Throwable $e) {
            return response()->json(['jobs' => [], 'error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function getAgentStats(): array
    {
        try {
            // Active agents
            $active = DB::selectOne("
                SELECT COUNT(*) as count FROM agent_sessions
                WHERE status = 'running' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");

            // Recent completions
            $completed = DB::selectOne("
                SELECT COUNT(*) as count FROM agent_sessions
                WHERE status = 'completed' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");

            // Recent errors
            $errors = DB::selectOne("
                SELECT COUNT(*) as count FROM agent_sessions
                WHERE status = 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");

            // Pending in agent_review_queue
            $pendingReviews = DB::selectOne("
                SELECT COUNT(*) as count FROM agent_review_queue
                WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())
            ");

            return [
                'active' => (int) ($active->count ?? 0),
                'completed_24h' => (int) ($completed->count ?? 0),
                'failed_24h' => (int) ($errors->count ?? 0),
                'pending_reviews' => (int) ($pendingReviews->count ?? 0),
            ];
        } catch (\Throwable $e) {
            Log::warning('ResearchHub: Failed to get agent stats', ['error' => $e->getMessage()]);

            return [
                'active' => 0,
                'completed_24h' => 0,
                'failed_24h' => 0,
                'pending_reviews' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // PROCEDURAL MEMORY
    // =========================================================================

    /**
     * GET /api/research-hub/agents/procedures
     */
    public function agentProcedures(Request $request): JsonResponse
    {
        try {
            $service = app(\App\Services\AgentProceduralMemoryService::class);
            $filters = [];

            if ($request->has('agent_id')) {
                $filters['agent_id'] = $request->input('agent_id');
            }
            if ($request->has('type')) {
                $filters['type'] = $request->input('type');
            }
            if ($request->has('retired')) {
                $filters['is_retired'] = (int) $request->input('retired');
            } else {
                $filters['is_retired'] = 0; // Default: active only
            }

            $procedures = $service->getProcedures($filters);

            return response()->json(['success' => true, 'data' => $procedures]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/research-hub/agents/procedures/stats
     */
    public function agentProcedureStats(): JsonResponse
    {
        try {
            $service = app(\App\Services\AgentProceduralMemoryService::class);
            $stats = $service->getDashboardStats();

            return response()->json(['success' => true, 'data' => $stats]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/research-hub/agents/procedures/{id}/retire
     */
    public function retireProcedure(int $id): JsonResponse
    {
        try {
            $service = app(\App\Services\AgentProceduralMemoryService::class);
            $result = $service->retireProcedure($id);

            return response()->json(['success' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/research-hub/agents/procedures/{id}/restore
     */
    public function restoreProcedure(int $id): JsonResponse
    {
        try {
            $service = app(\App\Services\AgentProceduralMemoryService::class);
            $result = $service->restoreProcedure($id);

            return response()->json(['success' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Speculative Execution (S19) ────────────────────────────────────

    public function speculativeStats(Request $request): JsonResponse
    {
        try {
            $service = app(\App\Services\SpeculativeExecutionService::class);
            $agentId = $request->query('agent_id');
            $stats = $service->getStats($agentId);

            return response()->json(['success' => true, 'stats' => $stats]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function speculativeHistory(Request $request): JsonResponse
    {
        try {
            $service = app(\App\Services\SpeculativeExecutionService::class);
            $agentId = $request->query('agent_id');
            $limit = min(100, max(1, (int) $request->query('limit', 20)));
            $runs = $service->getHistory($limit, $agentId);

            return response()->json(['success' => true, 'runs' => $runs]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function speculativeDetail(string $specRunId): JsonResponse
    {
        try {
            $service = app(\App\Services\SpeculativeExecutionService::class);
            $result = $service->getResult($specRunId);

            if ($result) {
                return response()->json(['success' => true, 'result' => $result]);
            }

            $run = $service->getRun($specRunId);
            if (! $run) {
                return response()->json(['success' => false, 'error' => 'Not found'], 404);
            }

            return response()->json([
                'success' => true,
                'status' => $run->status,
                'branch_a_status' => $run->branch_a_status,
                'branch_b_status' => $run->branch_b_status,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function speculativeRun(Request $request): JsonResponse
    {
        try {
            $agentId = $request->input('agent_id');
            $task = $request->input('task');

            if (! $agentId || ! $task) {
                return response()->json(['success' => false, 'error' => 'agent_id and task required'], 422);
            }

            $service = app(\App\Services\SpeculativeExecutionService::class);
            $result = $service->execute($agentId, $task, ['trigger_type' => 'manual']);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function speculativeCancel(string $specRunId): JsonResponse
    {
        try {
            $service = app(\App\Services\SpeculativeExecutionService::class);
            $cancelled = $service->cancel($specRunId);

            return response()->json(['success' => $cancelled]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // Adaptive Mode Selection (S20)
    // =========================================================================

    public function adaptiveModeStats(Request $request): JsonResponse
    {
        try {
            $service = app(\App\Services\AdaptiveModeService::class);
            $agentId = $request->query('agent_id');
            $stats = $service->getStats($agentId);

            return response()->json(['success' => true, 'stats' => $stats]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function adaptiveModeHistory(string $agentId): JsonResponse
    {
        try {
            $service = app(\App\Services\AdaptiveModeService::class);
            $history = $service->getSelectionHistory($agentId);

            return response()->json(['success' => true, 'history' => $history]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function adaptiveModeRecommend(string $agentId, Request $request): JsonResponse
    {
        try {
            $service = app(\App\Services\AdaptiveModeService::class);
            $task = $request->query('task');
            $taskKey = $task ? $service->classifyTask($agentId, $task) : null;
            $scores = $service->scoreModes($agentId, $taskKey);

            if (empty($scores)) {
                return response()->json([
                    'success' => true,
                    'recommendation' => null,
                    'message' => "No benchmark data for {$agentId}",
                ]);
            }

            uasort($scores, fn ($a, $b) => $b['composite'] <=> $a['composite']);

            return response()->json([
                'success' => true,
                'recommendation' => array_key_first($scores),
                'task_key' => $taskKey,
                'scores' => $scores,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * INF-10c: Enrich items with remediation action availability.
     * Adds 'remediation' key to items whose type has a registered action.
     */
    private function enrichItemsWithRemediation(array $items): array
    {
        try {
            $registry = app(\App\Services\RemediationRegistryService::class);
            $allActions = $registry->getAllActions();
            $actionMap = [];
            foreach ($allActions as $action) {
                $actionMap[$action['finding_type']] = $action;
            }

            foreach ($items as &$item) {
                // INF-10e: Match by finding_type (from agent_review_queue) or source (review type name)
                $findingType = $item['finding_type'] ?? $item['source'] ?? '';
                if (isset($actionMap[$findingType])) {
                    $action = $actionMap[$findingType];
                    $item['remediation'] = [
                        'action_id' => $action['id'],
                        'description' => $action['description'],
                        'risk_level' => $action['risk_level'],
                        'requires_confirmation' => $action['requires_confirmation'],
                        'executable' => $action['risk_level'] !== 'destructive'
                            && ! $registry->isInCooldown($action),
                    ];
                }
            }
            unset($item);
        } catch (\Throwable $e) {
            // Non-critical: don't fail item loading if remediation enrichment fails
        }

        return $items;
    }

    /**
     * UI-6: Enrich items with tree name for items that have tree_id.
     */
    private function enrichItemsWithTreeName(array $items): array
    {
        try {
            // Collect unique tree_ids
            $treeIds = [];
            foreach ($items as $item) {
                $treeId = $item['tree_id'] ?? null;
                if ($treeId && ! isset($treeIds[$treeId])) {
                    $treeIds[$treeId] = true;
                }
            }

            if (empty($treeIds)) {
                return $items;
            }

            // Batch lookup tree names
            $placeholders = implode(',', array_fill(0, count($treeIds), '?'));
            $trees = DB::select(
                "SELECT id, name FROM genealogy_trees WHERE id IN ({$placeholders})",
                array_keys($treeIds)
            );

            $nameMap = [];
            foreach ($trees as $tree) {
                $nameMap[$tree->id] = $tree->name;
            }

            // Enrich items
            foreach ($items as &$item) {
                $treeId = $item['tree_id'] ?? null;
                if ($treeId && isset($nameMap[$treeId])) {
                    $item['tree_name'] = $nameMap[$treeId];
                }
            }
            unset($item);
        } catch (\Throwable $e) {
            // Non-critical
        }

        return $items;
    }

    // =========================================================================
    // INF-10c: Remediation Actions
    // =========================================================================

    /**
     * Get available remediation action for a review item.
     */
    public function getRemediation(string $unifiedId): JsonResponse
    {
        [$typeName] = explode(':', $unifiedId, 2);

        $remediation = app(\App\Services\RemediationRegistryService::class)
            ->getActionForFinding($typeName);

        if (! $remediation) {
            return response()->json([
                'has_remediation' => false,
                'finding_type' => $typeName,
            ]);
        }

        $registry = app(\App\Services\RemediationRegistryService::class);

        return response()->json([
            'has_remediation' => true,
            'finding_type' => $typeName,
            'action' => [
                'id' => $remediation['id'],
                'description' => $remediation['description'],
                'risk_level' => $remediation['risk_level'],
                'requires_confirmation' => $remediation['requires_confirmation'],
                'in_cooldown' => $registry->isInCooldown($remediation),
                'cooldown_minutes' => $remediation['cooldown_minutes'],
                'last_executed_at' => $remediation['last_executed_at'],
                'execution_count' => $remediation['execution_count'],
                'success_count' => $remediation['success_count'],
            ],
            'executable' => $remediation['risk_level'] !== 'destructive'
                && ! $registry->isInCooldown($remediation),
        ]);
    }

    /**
     * Execute a remediation action for a review item.
     */
    public function executeRemediation(Request $request, string $unifiedId): JsonResponse
    {
        [$typeName] = explode(':', $unifiedId, 2);

        $result = app(\App\Services\RemediationExecutionService::class)->executeFindingType(
            $typeName,
            $request->boolean('confirmed')
        );
        $action = $result['action'] ?? null;

        // Log
        Log::info('ResearchHub: Remediation executed via UI', [
            'finding_type' => $typeName,
            'unified_id' => $unifiedId,
            'action' => $action['description'] ?? null,
            'success' => $result['success'],
        ]);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'detail' => $result['detail'] ?? '',
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Remediation failed',
        ], $result['status_code'] ?? 500);
    }

    /**
     * Get all remediation actions registry (for admin view).
     */
    public function remediationRegistry(): JsonResponse
    {
        $registry = app(\App\Services\RemediationRegistryService::class);

        return response()->json([
            'actions' => $registry->getAllActions(),
            'stats' => $registry->getStatistics(),
        ]);
    }

    private function getCooldownRemaining(array $action): int
    {
        if (empty($action['last_executed_at']) || $action['cooldown_minutes'] <= 0) {
            return 0;
        }
        $end = strtotime($action['last_executed_at']) + ($action['cooldown_minutes'] * 60);

        return max(0, (int) ceil(($end - time()) / 60));
    }
}
