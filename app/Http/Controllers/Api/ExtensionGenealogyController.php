<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExtensionCookieStoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExtensionGenealogyController extends Controller
{
    private ?ExtensionCookieStoreService $cookieStore = null;

    private function getCookieStore(): ExtensionCookieStoreService
    {
        if ($this->cookieStore === null) {
            $this->cookieStore = app(ExtensionCookieStoreService::class);
        }
        return $this->cookieStore;
    }

    // =========================================================================
    // Cookie Sharing
    // =========================================================================

    /**
     * Store cookies from browser extension
     *
     * POST /api/extension/genealogy/cookies
     */
    public function storeCookies(Request $request): JsonResponse
    {
        $domain = $request->input('domain');
        $cookies = $request->input('cookies', []);

        if (!$domain || empty($cookies)) {
            return response()->json(['error' => 'domain and cookies required'], 400);
        }

        $this->getCookieStore()->store($domain, $cookies);

        return response()->json([
            'success' => true,
            'domain' => $domain,
            'cookies_stored' => count($cookies),
        ]);
    }

    /**
     * Get stored cookie info (no values, for debugging)
     *
     * GET /api/extension/genealogy/cookies
     */
    public function getCookies(Request $request): JsonResponse
    {
        $domain = $request->input('domain');
        if (!$domain) {
            return response()->json(['error' => 'domain parameter required'], 400);
        }

        $info = $this->getCookieStore()->getInfo($domain);

        return response()->json([
            'success' => true,
            'data' => $info,
        ]);
    }

    // =========================================================================
    // Genealogy Clipping
    // =========================================================================

    /**
     * Save a genealogy clipping from the browser extension
     *
     * POST /api/extension/genealogy/clips
     */
    public function saveClip(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => 'required|url',
            'title' => 'nullable|string|max:500',
            'source_domain' => 'required|string|max:255',
            'source_type' => 'nullable|string|max:50',
            'extracted_data' => 'nullable|array',
            'raw_text' => 'nullable|string',
            'person_name' => 'nullable|string|max:255',
            'tree_id' => 'nullable|integer',
        ]);

        $treeId = $data['tree_id'] ?? 1;
        $apiSource = $this->mapDomainToSource($data['source_domain']);

        try {
            DB::insert("
                INSERT INTO genealogy_newspaper_clippings
                    (tree_id, original_url, headline, newspaper_name, publication_date, clipping_text, api_source, created_at, updated_at)
                VALUES (?, ?, ?, ?, NOW(), ?, ?, NOW(), NOW())
            ", [
                $treeId,
                $data['url'],
                $data['title'] ?? $data['url'],
                $data['source_domain'],
                $data['raw_text'] ?? '',
                $apiSource,
            ]);

            $id = (int) DB::getPdo()->lastInsertId();

            Log::info('ExtensionGenealogy: Clipping saved', [
                'id' => $id,
                'source' => $apiSource,
                'url' => $data['url'],
            ]);

            return response()->json([
                'success' => true,
                'clipping_id' => $id,
                'source' => $apiSource,
            ]);
        } catch (\Exception $e) {
            Log::error('ExtensionGenealogy: Clip save failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to save clipping: ' . $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // Browse Queue
    // =========================================================================

    /**
     * Get pending browse queue items for the extension
     *
     * GET /api/extension/genealogy/browse-queue
     */
    public function getBrowseQueue(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 20), 100);

        $items = DB::select("
            SELECT id, url, domain, purpose, status, context, priority, created_at
            FROM extension_browse_queue
            WHERE status = 'pending'
            ORDER BY priority DESC, created_at ASC
            LIMIT ?
        ", [$limit]);

        return response()->json([
            'success' => true,
            'items' => array_map(function ($item) {
                return [
                    'id' => $item->id,
                    'url' => $item->url,
                    'domain' => $item->domain,
                    'purpose' => $item->purpose,
                    'context' => json_decode($item->context, true),
                    'priority' => $item->priority,
                    'created_at' => $item->created_at,
                ];
            }, $items),
            'count' => count($items),
        ]);
    }

    /**
     * Submit browse result from extension
     *
     * POST /api/extension/genealogy/browse-queue/{id}/result
     */
    public function submitBrowseResult(Request $request, int $id): JsonResponse
    {
        $item = DB::selectOne("SELECT * FROM extension_browse_queue WHERE id = ?", [$id]);
        if (!$item) {
            return response()->json(['error' => 'Queue item not found'], 404);
        }

        $result = $request->input('result', []);
        $status = $request->input('status', 'completed');

        if (!in_array($status, ['completed', 'failed', 'skipped'])) {
            $status = 'completed';
        }

        DB::update("
            UPDATE extension_browse_queue
            SET status = ?, result = ?, completed_at = NOW()
            WHERE id = ?
        ", [$status, json_encode($result), $id]);

        Log::info('ExtensionGenealogy: Browse result submitted', [
            'id' => $id,
            'status' => $status,
            'url' => $item->url,
        ]);

        return response()->json([
            'success' => true,
            'id' => $id,
            'status' => $status,
        ]);
    }

    /**
     * Create browse queue items (from server-side, e.g. research tasks)
     *
     * POST /api/extension/genealogy/browse-queue
     */
    public function createBrowseQueue(Request $request): JsonResponse
    {
        $items = $request->input('items', []);
        if (empty($items)) {
            return response()->json(['error' => 'items array required'], 400);
        }

        $created = 0;
        foreach ($items as $item) {
            if (empty($item['url'])) continue;

            $url = $item['url'];
            $domain = parse_url($url, PHP_URL_HOST) ?? '';
            $domain = preg_replace('/^www\./', '', $domain);

            DB::insert("
                INSERT INTO extension_browse_queue (url, domain, purpose, status, context, priority, created_at)
                VALUES (?, ?, ?, 'pending', ?, ?, NOW())
            ", [
                $url,
                $domain,
                $item['purpose'] ?? 'general',
                json_encode($item['context'] ?? []),
                (int) ($item['priority'] ?? 0),
            ]);
            $created++;
        }

        return response()->json([
            'success' => true,
            'created' => $created,
        ]);
    }

    /**
     * Get browse queue stats
     *
     * GET /api/extension/genealogy/browse-queue/stats
     */
    public function browseQueueStats(): JsonResponse
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM extension_browse_queue
        ");

        return response()->json([
            'success' => true,
            'data' => [
                'total' => (int) ($stats->total ?? 0),
                'pending' => (int) ($stats->pending ?? 0),
                'completed' => (int) ($stats->completed ?? 0),
                'failed' => (int) ($stats->failed ?? 0),
            ],
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function mapDomainToSource(string $domain): string
    {
        $domain = strtolower(preg_replace('/^(www\.|m\.)/', '', $domain));

        return match (true) {
            str_contains($domain, 'findagrave') => 'findagrave_extension',
            str_contains($domain, 'newspapers.com') => 'newspapers_extension',
            str_contains($domain, 'ancestry') => 'ancestry_extension',
            str_contains($domain, 'familysearch') => 'familysearch_extension',
            str_contains($domain, 'myheritage') => 'myheritage_extension',
            str_contains($domain, 'billiongraves') => 'billiongraves_extension',
            default => 'extension_' . str_replace('.', '_', $domain),
        };
    }
}
