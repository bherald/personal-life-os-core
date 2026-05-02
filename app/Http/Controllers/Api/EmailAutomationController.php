<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailClassificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Email Automation API Controller
 *
 * Manages email classification, templates, rules, drafts, and scheduled emails.
 * All operations use direct SQL for performance and security.
 */
class EmailAutomationController extends Controller
{
    private EmailClassificationService $emailService;

    public function __construct(EmailClassificationService $emailService)
    {
        $this->emailService = $emailService;
    }

    /**
     * GET /api/email/stats
     * Get email automation statistics
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = $this->emailService->getStats();

            // Get additional stats
            $templates = DB::selectOne('SELECT COUNT(*) as count FROM email_templates WHERE is_active = 1')->count ?? 0;
            $rules = 0; // email_rules table dropped (D1: Thunderbird MCP)
            $drafts = DB::selectOne('SELECT COUNT(*) as count FROM email_reply_drafts WHERE status = "pending"')->count ?? 0;
            $scheduled = 0; // email_scheduled table dropped (D1: Thunderbird MCP)

            return response()->json([
                'success' => true,
                'stats' => array_merge($stats, [
                    'active_templates' => $templates,
                    'active_rules' => $rules,
                    'pending_drafts' => $drafts,
                    'scheduled_emails' => $scheduled,
                ]),
            ]);
        } catch (\Exception $e) {
            Log::error('Email stats failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/email/classify
     * Classify emails
     */
    public function classify(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'query' => 'nullable|string|max:500',
            'folder' => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->emailService->searchAndClassify(
                $request->input('query', ''),
                $request->input('folder'),
                $request->input('limit', 10)
            );

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Email classification API failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/email/classifications
     * List classified emails with filters
     */
    public function listClassifications(Request $request): JsonResponse
    {
        try {
            $category = $request->input('category');
            $priority = $request->input('priority');
            $limit = min((int) $request->input('limit', 20), 100);
            $offset = max((int) $request->input('offset', 0), 0);

            $sql = 'SELECT * FROM email_classifications WHERE 1=1';
            $params = [];

            if ($category) {
                $sql .= ' AND category = ?';
                $params[] = $category;
            }

            if ($priority) {
                $sql .= ' AND priority = ?';
                $params[] = $priority;
            }

            $sql .= ' ORDER BY classified_at DESC LIMIT ? OFFSET ?';
            $params[] = $limit;
            $params[] = $offset;

            $results = DB::select($sql, $params);

            $totalSql = 'SELECT COUNT(*) as count FROM email_classifications WHERE 1=1';
            $totalParams = [];
            if ($category) {
                $totalSql .= ' AND category = ?';
                $totalParams[] = $category;
            }
            if ($priority) {
                $totalSql .= ' AND priority = ?';
                $totalParams[] = $priority;
            }

            $total = DB::selectOne($totalSql, $totalParams)->count ?? 0;

            return response()->json([
                'success' => true,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'classifications' => $results,
            ]);
        } catch (\Exception $e) {
            Log::error('List classifications failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/email/reply/generate
     * Generate AI reply draft
     */
    public function generateReply(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message_id' => 'required|string',
            'email' => 'required|array',
            'tone' => 'nullable|in:professional,casual,formal,friendly',
            'template_id' => 'nullable|integer|exists:email_templates,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $draftId = $this->emailService->generateReplyDraft(
                $request->input('message_id'),
                $request->input('email'),
                [
                    'tone' => $request->input('tone', 'professional'),
                    'template_id' => $request->input('template_id'),
                ]
            );

            $draft = DB::selectOne('SELECT * FROM email_reply_drafts WHERE id = ?', [$draftId]);

            return response()->json([
                'success' => true,
                'draft_id' => $draftId,
                'draft' => $draft,
            ]);
        } catch (\Exception $e) {
            Log::error('Generate reply failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/email/drafts
     * List reply drafts
     */
    public function listDrafts(Request $request): JsonResponse
    {
        try {
            $status = $request->input('status', 'pending');
            $limit = min((int) $request->input('limit', 20), 100);

            $drafts = DB::select(
                'SELECT * FROM email_reply_drafts WHERE status = ? ORDER BY created_at DESC LIMIT ?',
                [$status, $limit]
            );

            return response()->json([
                'success' => true,
                'count' => count($drafts),
                'drafts' => $drafts,
            ]);
        } catch (\Exception $e) {
            Log::error('List drafts failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PATCH /api/email/drafts/{id}/approve
     * Approve a reply draft
     */
    public function approveDraft(int $id): JsonResponse
    {
        try {
            $affected = DB::update(
                'UPDATE email_reply_drafts SET status = ?, approved_at = ?, updated_at = ? WHERE id = ?',
                ['approved', now(), now(), $id]
            );

            if ($affected === 0) {
                return response()->json([
                    'success' => false,
                    'error' => 'Draft not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Draft approved successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Approve draft failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/email/templates
     * List email templates
     */
    public function listTemplates(Request $request): JsonResponse
    {
        try {
            $category = $request->input('category');
            $active = $request->input('active', true);

            $sql = 'SELECT * FROM email_templates WHERE 1=1';
            $params = [];

            if ($active !== null) {
                $sql .= ' AND is_active = ?';
                $params[] = (bool) $active;
            }

            if ($category) {
                $sql .= ' AND category = ?';
                $params[] = $category;
            }

            $sql .= ' ORDER BY category, name';

            $templates = DB::select($sql, $params);

            return response()->json([
                'success' => true,
                'count' => count($templates),
                'templates' => $templates,
            ]);
        } catch (\Exception $e) {
            Log::error('List templates failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/email/rules
     * List email classification rules
     */
    public function listRules(Request $request): JsonResponse
    {
        try {
            $active = $request->input('active', true);

            $sql = 'SELECT * FROM email_rules WHERE 1=1';
            $params = [];

            if ($active !== null) {
                $sql .= ' AND is_active = ?';
                $params[] = (bool) $active;
            }

            $sql .= ' ORDER BY priority DESC, id';

            $rules = DB::select($sql, $params);

            return response()->json([
                'success' => true,
                'count' => count($rules),
                'rules' => $rules,
            ]);
        } catch (\Exception $e) {
            Log::error('List rules failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/email/rules
     * Create a new email rule
     */
    public function createRule(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'rule_type' => 'required|in:from,to,subject,body,sender_domain,category,priority',
            'operator' => 'required|in:contains,equals,starts_with,ends_with,regex',
            'value' => 'required|string',
            'action' => 'required|in:classify,tag,move,priority,forward,generate_reply',
            'action_params' => 'required|json',
            'priority' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // email_rules table dropped (D1: Thunderbird MCP)
        return response()->json([
            'success' => false,
            'error' => 'Email rules feature removed — email managed via Thunderbird MCP',
        ], 410);
    }
}
