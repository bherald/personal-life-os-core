<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteWorkflow;
use App\Services\YouTubeApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * YouTube Integration Controller
 *
 * Handles OAuth authentication, channel configuration, and manual video processing
 */
class YouTubeController extends Controller
{
    public function __construct(
        private YouTubeApiService $youtubeService
    ) {}

    private function getYouTubeCallbackUrl(): string
    {
        return config(
            'youtube.redirect_uri',
            rtrim(config('app.url', 'http://localhost'), '/') . '/api/youtube/auth/callback'
        );
    }

    /**
     * Get YouTube connection status
     *
     * @return JsonResponse
     */
    public function status(): JsonResponse
    {
        $tokenStatus = $this->youtubeService->getTokenStatus();

        if (!$tokenStatus) {
            return response()->json([
                'connected' => false,
                'message' => 'No YouTube account connected'
            ]);
        }

        return response()->json([
            'connected' => true,
            'has_refresh_token' => $tokenStatus['has_refresh_token'],
            'has_access_token' => $tokenStatus['has_access_token'],
            'access_token_expires_at' => $tokenStatus['access_token_expires_at'],
            'is_expired' => $tokenStatus['is_expired'],
            'created_at' => $tokenStatus['created_at'],
            'updated_at' => $tokenStatus['updated_at'],
        ]);
    }

    /**
     * Initiate YouTube OAuth flow
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function initiateAuth()
    {
        $clientId = config('youtube.client_id');
        $redirectUri = $this->getYouTubeCallbackUrl();

        if (!$clientId) {
            return response()->json([
                'error' => 'YouTube Client ID not configured. Please set YOUTUBE_CLIENT_ID in .env'
            ], 500);
        }

        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', [
                'https://www.googleapis.com/auth/youtube.readonly',
                'https://www.googleapis.com/auth/youtube.force-ssl'
            ]),
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);

        return redirect($authUrl);
    }

    /**
     * Handle OAuth callback from Google
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function handleCallback(Request $request): JsonResponse
    {
        $code = $request->input('code');
        $error = $request->input('error');

        if ($error) {
            Log::error('YouTube OAuth authorization failed', ['error' => $error]);
            return response()->json([
                'success' => false,
                'error' => 'OAuth authorization failed',
                'details' => $error
            ], 400);
        }

        if (!$code) {
            return response()->json([
                'success' => false,
                'error' => 'No authorization code received'
            ], 400);
        }

        try {
            // Exchange code for tokens
            $response = Http::asForm()->connectTimeout(5)->timeout(30)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('youtube.client_id'),
                'client_secret' => config('youtube.client_secret'),
                'code' => $code,
                'redirect_uri' => $this->getYouTubeCallbackUrl(),
                'grant_type' => 'authorization_code',
            ]);

            if (!$response->successful()) {
                Log::error('YouTube OAuth token exchange failed', [
                    'response' => $response->body()
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Failed to exchange authorization code for tokens',
                    'details' => $response->json()
                ], 500);
            }

            $tokens = $response->json();

            // Store tokens using the service (database-backed)
            if (isset($tokens['refresh_token'])) {
                $this->youtubeService->storeTokens(
                    $tokens['access_token'],
                    $tokens['refresh_token'],
                    $tokens['expires_in'] ?? 3600
                );

                Log::info('YouTube OAuth tokens stored successfully');

                return response()->json([
                    'success' => true,
                    'message' => 'YouTube OAuth authentication successful!',
                    'expires_in' => $tokens['expires_in'] ?? null,
                    'scopes' => $tokens['scope'] ?? null,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => 'No refresh token received. Make sure access_type=offline is set.',
                    'tokens_received' => array_keys($tokens)
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('YouTube OAuth callback exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'OAuth callback failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Disconnect YouTube account (remove tokens)
     *
     * @return JsonResponse
     */
    public function disconnect(): JsonResponse
    {
        try {
            $deleted = DB::delete("DELETE FROM oauth_tokens WHERE provider = ?", ['youtube']);

            if ($deleted > 0) {
                Log::info('YouTube OAuth tokens removed');
                return response()->json([
                    'success' => true,
                    'message' => 'YouTube account disconnected'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No YouTube account was connected'
                ], 404);
            }
        } catch (\Exception $e) {
            Log::error('Failed to disconnect YouTube account', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to disconnect account',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's YouTube subscriptions
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSubscriptions(Request $request): JsonResponse
    {
        try {
            $maxResults = $request->input('maxResults', 50);
            $pageToken = $request->input('pageToken');

            $data = $this->youtubeService->getSubscriptions($maxResults, $pageToken, false);

            // Format for UI consumption
            $subscriptions = collect($data['items'] ?? [])->map(function ($item) {
                return [
                    'channel_id' => $item['snippet']['resourceId']['channelId'] ?? null,
                    'channel_title' => $item['snippet']['title'] ?? 'Unknown',
                    'description' => $item['snippet']['description'] ?? '',
                    'thumbnail' => $item['snippet']['thumbnails']['default']['url'] ?? null,
                    'published_at' => $item['snippet']['publishedAt'] ?? null,
                ];
            });

            return response()->json([
                'success' => true,
                'subscriptions' => $subscriptions,
                'total_results' => $data['pageInfo']['totalResults'] ?? 0,
                'next_page_token' => $data['nextPageToken'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch YouTube subscriptions', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch subscriptions',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get channel tier configuration
     *
     * @return JsonResponse
     */
    public function getChannelConfig(): JsonResponse
    {
        try {
            // Get configuration from youtube_daily_digest workflow
            $configRows = DB::select("
                SELECT wnc.config_key, wnc.config_value
                FROM workflow_nodes wn
                JOIN workflows w ON wn.workflow_id = w.id
                JOIN workflow_node_configs wnc ON wn.id = wnc.workflow_node_id
                WHERE w.name = ? AND wn.node_type = ?
            ", ['youtube_daily_digest', 'App\\Nodes\\YouTube\\YouTubeSubscriptions']);

            $config = collect($configRows)->keyBy('config_key')->map(fn($item) => $item->config_value);

            return response()->json([
                'success' => true,
                'config' => [
                    'tier1_channels' => json_decode($config['filter_channels'] ?? '[]'),
                    'tier2_keywords' => json_decode($config['tier2_keywords'] ?? '[]'),
                    'max_age_hours' => (int)($config['max_age_hours'] ?? 24),
                    'min_duration' => (int)($config['min_duration'] ?? 10),
                    'max_duration' => (int)($config['max_duration'] ?? 60),
                    'limit' => (int)($config['limit'] ?? 10),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get channel configuration', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to get configuration',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update channel tier configuration
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateChannelConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tier1_channels' => 'nullable|array',
            'tier1_channels.*' => 'string',
            'tier2_keywords' => 'nullable|array',
            'tier2_keywords.*' => 'string',
            'max_age_hours' => 'nullable|integer|min:1|max:168',
            'min_duration' => 'nullable|integer|min:0',
            'max_duration' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        try {
            // Get the YouTubeSubscriptions node ID from youtube_daily_digest workflow
            $nodeRow = DB::select("
                SELECT wn.id
                FROM workflow_nodes wn
                JOIN workflows w ON wn.workflow_id = w.id
                WHERE w.name = ? AND wn.node_type = ?
                LIMIT 1
            ", ['youtube_daily_digest', 'App\\Nodes\\YouTube\\YouTubeSubscriptions']);
            $nodeId = $nodeRow[0]->id ?? null;

            if (!$nodeId) {
                return response()->json([
                    'success' => false,
                    'error' => 'YouTube workflow not found'
                ], 404);
            }

            // Update configurations
            $configMap = [
                'tier1_channels' => 'filter_channels',
                'tier2_keywords' => 'tier2_keywords',
                'max_age_hours' => 'max_age_hours',
                'min_duration' => 'min_duration',
                'max_duration' => 'max_duration',
                'limit' => 'limit',
            ];

            foreach ($validated as $key => $value) {
                if (isset($configMap[$key])) {
                    $configKey = $configMap[$key];
                    $configValue = is_array($value) ? json_encode($value) : (string)$value;

                    // Check if config exists
                    $exists = DB::select("
                        SELECT id FROM workflow_node_configs
                        WHERE workflow_node_id = ? AND config_key = ?
                        LIMIT 1
                    ", [$nodeId, $configKey]);

                    if ($exists) {
                        DB::update("
                            UPDATE workflow_node_configs
                            SET config_value = ?
                            WHERE workflow_node_id = ? AND config_key = ?
                        ", [$configValue, $nodeId, $configKey]);
                    } else {
                        DB::insert("
                            INSERT INTO workflow_node_configs (workflow_node_id, config_key, config_value)
                            VALUES (?, ?, ?)
                        ", [$nodeId, $configKey, $configValue]);
                    }
                }
            }

            Log::info('YouTube channel configuration updated', $validated);

            return response()->json([
                'success' => true,
                'message' => 'Channel configuration updated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update channel configuration', [
                'error' => $e->getMessage(),
                'config' => $validated
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to update configuration',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process a single YouTube video URL manually
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function processVideo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => 'required|string',
        ]);

        $url = $validated['url'];

        // Extract video ID
        $videoId = \App\Services\YouTubeTranscriptService::extractVideoId($url);

        if (!$videoId) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid YouTube URL'
            ], 400);
        }

        try {
            // Queue manual processing workflow on the dedicated workflow lane.
            ExecuteWorkflow::dispatch('youtube_manual_process', null, [
                'video_url' => $url,
                'video_id' => $videoId,
            ]);

            Log::info('Manual YouTube video processing started', [
                'video_id' => $videoId,
                'url' => $url
            ]);

            return response()->json([
                'success' => true,
                'video_id' => $videoId,
                'message' => 'Video processing queued'
            ], 202);

        } catch (\Exception $e) {
            Log::error('Failed to start YouTube workflow', [
                'error' => $e->getMessage(),
                'video_id' => $videoId,
                'url' => $url,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to start workflow',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get YouTube integration statistics
     *
     * @return JsonResponse
     */
    public function getStats(): JsonResponse
    {
        try {
            // Get stats from RAG documents (via Joplin notes) — PostgreSQL
            $db = DB::connection('pgsql_rag');
            $statsRow = $db->select("
                SELECT
                    COUNT(*) as total_videos,
                    SUM(CAST(metadata->>'word_count' AS INTEGER)) as total_words,
                    MAX(created_at) as last_processed
                FROM rag_documents
                WHERE designation = ? AND metadata @> ?
            ", ['joplin_note', json_encode(['tags' => ['youtube']])]);
            $stats = $statsRow[0] ?? (object)['total_videos' => 0, 'total_words' => 0, 'last_processed' => null];

            // Get recent videos
            $recentVideosRows = $db->select("
                SELECT title, metadata, created_at
                FROM rag_documents
                WHERE designation = ? AND metadata @> ?
                ORDER BY created_at DESC
                LIMIT 5
            ", ['joplin_note', json_encode(['tags' => ['youtube']])]);

            $recentVideos = collect($recentVideosRows)->map(function ($doc) {
                $metadata = json_decode($doc->metadata, true);
                return [
                    'title' => $doc->title,
                    'channel' => $metadata['channel'] ?? 'Unknown',
                    'video_id' => $metadata['video_id'] ?? null,
                    'processed_at' => $doc->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'stats' => [
                    'total_videos' => $stats->total_videos ?? 0,
                    'total_words' => $stats->total_words ?? 0,
                    'last_processed' => $stats->last_processed,
                ],
                'recent_videos' => $recentVideos,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get YouTube stats', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to get statistics',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
