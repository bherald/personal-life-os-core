<?php

namespace App\Services;

use App\Support\JoplinPaths;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * YouTube Operations Service
 *
 * Provides agent-callable tool methods for the youtube-ops agent.
 * Monitors the Watch Later pipeline (workflow 14): transcript acquisition,
 * Joplin sync, key points generation, note organization, and RAG indexing.
 */
class YouTubeOpsService
{
    private const WATCH_LATER_WORKFLOW_NAME = 'youtube_watch_later';

    private function getJoplinDirectory(): ?string
    {
        $dataPath = config('services.nextcloud.data_path');
        if (! $dataPath) {
            return null;
        }

        $joplinDir = rtrim($dataPath, '/').JoplinPaths::syncPath(false);

        return is_dir($joplinDir) ? $joplinDir : null;
    }

    // =========================================================================
    // ASSESS TOOLS
    // =========================================================================

    /**
     * Get Watch Later pipeline health overview — last run status,
     * videos processed, transcript success rate, key points coverage.
     */
    public function getWatchLaterHealth(): array
    {
        try {
            $workflow = $this->getWatchLaterWorkflow();
            if (! $workflow) {
                return ['error' => 'Watch Later workflow not found: '.self::WATCH_LATER_WORKFLOW_NAME];
            }

            $lastRun = DB::selectOne('
                SELECT wr.id, wr.status, wr.started_at, wr.completed_at,
                       TIMESTAMPDIFF(SECOND, wr.started_at, COALESCE(wr.completed_at, NOW())) as duration_seconds
                FROM workflow_runs wr
                WHERE wr.workflow_id = ?
                ORDER BY wr.started_at DESC
                LIMIT 1
            ', [$workflow->id]);

            // Node-level results from last run
            $nodeResults = [];
            if ($lastRun) {
                $nodeResults = DB::select('
                    SELECT ne.node_type, ne.state as status,
                           TIMESTAMPDIFF(SECOND, ne.executed_at, NOW()) as duration_seconds
                    FROM node_executions ne
                    WHERE ne.run_id = ?
                    ORDER BY ne.executed_at ASC
                ', [$lastRun->id]);
            }

            // Recent runs summary (last 7 days)
            $recentStats = DB::selectOne("
                SELECT COUNT(*) as total_runs,
                       SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as succeeded,
                       SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                       SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial
                FROM workflow_runs
                WHERE workflow_id = ?
                AND started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ", [$workflow->id]);

            // Scheduled job status
            $jobStatus = DB::selectOne("
                SELECT sj.name, sj.enabled, sj.cron_expression, sj.last_run_at, sj.last_run_status,
                       sj.timeout_minutes,
                       (SELECT COUNT(*) FROM scheduled_job_runs
                        WHERE scheduled_job_id = sj.id
                          AND status = 'failed'
                          AND started_at > COALESCE(
                              (SELECT MAX(started_at) FROM scheduled_job_runs
                               WHERE scheduled_job_id = sj.id AND status = 'success'),
                              '2000-01-01'
                          )
                       ) as consecutive_failures
                FROM scheduled_jobs sj
                WHERE sj.job_type = 'workflow'
                  AND (sj.command = ? OR sj.name = ?)
                LIMIT 1
            ", [
                $workflow->name,
                $this->getWatchLaterScheduledJobName($workflow->name),
            ]);

            return [
                'last_run' => $lastRun ? [
                    'id' => $lastRun->id,
                    'status' => $lastRun->status,
                    'started_at' => $lastRun->started_at,
                    'completed_at' => $lastRun->completed_at,
                    'duration_seconds' => (int) $lastRun->duration_seconds,
                ] : null,
                'node_results' => array_map(fn ($n) => [
                    'node' => $n->node_type,
                    'status' => $n->status,
                    'duration_seconds' => (int) $n->duration_seconds,
                ], $nodeResults),
                'last_7_days' => [
                    'total_runs' => (int) ($recentStats->total_runs ?? 0),
                    'succeeded' => (int) ($recentStats->succeeded ?? 0),
                    'failed' => (int) ($recentStats->failed ?? 0),
                    'partial' => (int) ($recentStats->partial ?? 0),
                ],
                'scheduled_job' => $jobStatus ? [
                    'name' => $jobStatus->name,
                    'enabled' => (bool) $jobStatus->enabled,
                    'cron' => $jobStatus->cron_expression,
                    'last_run_at' => $jobStatus->last_run_at,
                    'last_run_status' => $jobStatus->last_run_status,
                    'consecutive_failures' => (int) ($jobStatus->consecutive_failures ?? 0),
                ] : null,
            ];
        } catch (\Exception $e) {
            Log::error('YouTubeOpsService::getWatchLaterHealth failed', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get transcript storage statistics — totals, by source method,
     * recent activity, word count metrics.
     */
    public function getTranscriptStats(): array
    {
        try {
            return app(YouTubeTranscriptStorageService::class)->getStats();
        } catch (\Exception $e) {
            Log::error('YouTubeOpsService::getTranscriptStats failed', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get Joplin sync status for Watch Later notes — counts, categorization,
     * and subfolder breakdown. Uses direct filesystem access (same as
     * checkJoplinIntegrity) to avoid WebDAV reliability issues.
     */
    public function getJoplinSyncStatus(): array
    {
        try {
            $joplinDir = $this->getJoplinDirectory();
            if (! $joplinDir) {
                return ['error' => 'Joplin data directory not found'];
            }

            $organizer = app(JoplinYouTubeOrganizer::class);
            $watchLaterFolderId = $organizer->getWatchLaterFolderId();

            $files = glob($joplinDir.'/*.md');
            if (empty($files)) {
                return ['error' => 'No Joplin files found in '.$joplinDir];
            }

            $noteCount = 0;
            $folderCount = 0;
            $watchLaterNoteCount = 0;
            $folders = []; // id => ['title', 'parent_id']

            foreach ($files as $filepath) {
                $content = file_get_contents($filepath);
                if ($content === false) {
                    continue;
                }
                $parsed = $organizer->parseNote($content);

                if ($parsed['type'] === 1) { // note
                    $noteCount++;
                    if ($watchLaterFolderId && ($parsed['parent_id'] ?? '') === $watchLaterFolderId) {
                        $watchLaterNoteCount++;
                    }
                } elseif ($parsed['type'] === 2) { // folder
                    $folderCount++;
                    $folders[$parsed['id']] = [
                        'title' => $parsed['title'],
                        'parent_id' => $parsed['parent_id'] ?? '',
                    ];
                }
            }

            // Watch Later subfolders
            $watchLaterSubfolders = array_filter(
                $folders,
                fn ($f) => $watchLaterFolderId && $f['parent_id'] === $watchLaterFolderId
            );
            $subfolderNames = array_map(fn ($f) => $f['title'], $watchLaterSubfolders);

            return [
                'joplin_dir' => $joplinDir,
                'total_notes' => $noteCount,
                'total_folders' => $folderCount,
                'watch_later_notes' => $watchLaterNoteCount,
                'category_folders' => count($watchLaterSubfolders),
                'folder_names' => array_values($subfolderNames),
                'note' => 'Lightweight assess check. Run youtube_joplin_integrity_check for key points analysis.',
            ];
        } catch (\Exception $e) {
            Log::error('YouTubeOpsService::getJoplinSyncStatus failed', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get RAG index status for YouTube transcripts — indexed vs pending,
     * drift, recent indexing activity.
     */
    public function getRagIndexStatus(): array
    {
        try {
            // Count transcripts in storage
            $totalTranscripts = DB::selectOne('SELECT COUNT(*) as count FROM youtube_transcripts');

            // Count RAG-indexed YouTube documents (PostgreSQL)
            $ragIndexed = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) as count
                FROM rag_documents
                WHERE source_type = 'youtube_transcript'
                   OR metadata->>'source_type' = 'youtube_transcript'
            ");

            // Recent indexing activity (PostgreSQL)
            $recentIndexed = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) as count
                FROM rag_documents
                WHERE (source_type = 'youtube_transcript'
                   OR metadata->>'source_type' = 'youtube_transcript')
                AND created_at >= NOW() - INTERVAL '7 days'
            ");

            $total = (int) ($totalTranscripts->count ?? 0);
            $indexed = (int) ($ragIndexed->count ?? 0);
            $drift = max(0, $total - $indexed);

            return [
                'total_transcripts' => $total,
                'rag_indexed' => $indexed,
                'drift' => $drift,
                'recent_7d_indexed' => (int) ($recentIndexed->count ?? 0),
                'coverage_pct' => $total > 0 ? round(($indexed / $total) * 100, 1) : 100,
            ];
        } catch (\Exception $e) {
            // RAG is on PostgreSQL, may fail differently
            Log::error('YouTubeOpsService::getRagIndexStatus failed', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage(), 'note' => 'RAG index is on PostgreSQL'];
        }
    }

    /**
     * Get recent Watch Later workflow execution history.
     */
    public function getRecentRuns(int $limit = 10): array
    {
        try {
            $workflow = $this->getWatchLaterWorkflow();
            if (! $workflow) {
                return ['error' => 'Watch Later workflow not found: '.self::WATCH_LATER_WORKFLOW_NAME];
            }

            $runs = DB::select("
                SELECT wr.id, wr.status, wr.started_at, wr.completed_at,
                       TIMESTAMPDIFF(SECOND, wr.started_at, COALESCE(wr.completed_at, NOW())) as duration_seconds,
                       (SELECT GROUP_CONCAT(CONCAT(ne.node_type, ':', ne.state) SEPARATOR ', ')
                        FROM node_executions ne
                        WHERE ne.run_id = wr.id) as node_summary
                FROM workflow_runs wr
                WHERE wr.workflow_id = ?
                ORDER BY wr.started_at DESC
                LIMIT ?
            ", [$workflow->id, $limit]);

            return [
                'workflow_id' => $workflow->id,
                'workflow_name' => $workflow->name,
                'runs' => array_map(fn ($r) => [
                    'id' => $r->id,
                    'status' => $r->status,
                    'started_at' => $r->started_at,
                    'completed_at' => $r->completed_at,
                    'duration_seconds' => (int) $r->duration_seconds,
                    'node_summary' => $r->node_summary,
                ], $runs),
            ];
        } catch (\Exception $e) {
            Log::error('YouTubeOpsService::getRecentRuns failed', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // ACT TOOLS
    // =========================================================================

    /**
     * Check transcript quality for recent videos — completeness,
     * word count thresholds, source method distribution, and content
     * quality heuristics (repetition, gibberish, low information density).
     */
    public function checkTranscriptQuality(int $limit = 20): array
    {
        try {
            // Get recent transcripts with text for quality analysis
            $transcripts = DB::select('
                SELECT video_id, source_method, word_count, language, fetched_at,
                       LENGTH(content) as text_length,
                       SUBSTRING(content, 1, 5000) as text_sample
                FROM youtube_transcripts
                ORDER BY fetched_at DESC
                LIMIT ?
            ', [$limit]);

            $issues = [];
            $byMethod = [];
            $totalWords = 0;
            $qualityScores = [];

            foreach ($transcripts as $t) {
                $method = $t->source_method ?? 'unknown';
                $byMethod[$method] = ($byMethod[$method] ?? 0) + 1;
                $totalWords += (int) ($t->word_count ?? 0);

                // Basic checks
                if (($t->text_length ?? 0) === 0) {
                    $issues[] = [
                        'video_id' => $t->video_id,
                        'issue' => 'empty_transcript',
                        'source' => $method,
                    ];

                    continue;
                }
                if (($t->word_count ?? 0) < 50) {
                    $issues[] = [
                        'video_id' => $t->video_id,
                        'issue' => 'very_short_transcript',
                        'word_count' => (int) $t->word_count,
                        'source' => $method,
                    ];
                }

                // Content quality heuristics on text sample
                $sample = $t->text_sample ?? '';
                if (strlen($sample) > 100) {
                    $qualityResult = $this->analyzeTextQuality($sample);
                    $qualityScores[] = $qualityResult['score'];

                    foreach ($qualityResult['issues'] as $issue) {
                        $issues[] = [
                            'video_id' => $t->video_id,
                            'issue' => $issue['type'],
                            'detail' => $issue['detail'],
                            'source' => $method,
                        ];
                    }
                }
            }

            return [
                'checked' => count($transcripts),
                'avg_word_count' => count($transcripts) > 0 ? (int) ($totalWords / count($transcripts)) : 0,
                'avg_quality_score' => ! empty($qualityScores) ? round(array_sum($qualityScores) / count($qualityScores), 2) : null,
                'by_source_method' => $byMethod,
                'quality_issues' => $issues,
                'issue_count' => count($issues),
            ];
        } catch (\Exception $e) {
            Log::error('YouTubeOpsService::checkTranscriptQuality failed', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Verify Joplin notes integrity — missing key points, duplicates,
     * notes missing from category folders.
     *
     * Joplin WebDAV sync stores ALL items (notes, folders, resources) as flat
     * .md files with UUID filenames in the configured Joplin sync path. Hierarchy is
     * encoded via parent_id metadata inside each file, NOT filesystem directories.
     *
     * Uses direct filesystem access (faster than WebDAV batch fetch).
     */
    public function checkJoplinIntegrity(): array
    {
        try {
            $joplinDir = $this->getJoplinDirectory();
            if (! $joplinDir) {
                return ['error' => 'Joplin data directory not found'];
            }

            $organizer = app(JoplinYouTubeOrganizer::class);
            $watchLaterFolderId = $organizer->getWatchLaterFolderId();

            // Scan all .md files directly from filesystem
            $files = glob($joplinDir.'/*.md');
            if (empty($files)) {
                return ['error' => 'No Joplin files found in '.$joplinDir];
            }

            $notes = [];
            $folders = [];       // id => title
            $folderParents = []; // id => parent_id
            $resources = 0;
            $other = 0;

            foreach ($files as $filepath) {
                $content = file_get_contents($filepath);
                if ($content === false) {
                    continue;
                }
                $parsed = $organizer->parseNote($content);
                $parsed['filename'] = basename($filepath);

                switch ($parsed['type']) {
                    case 1:
                        $notes[] = $parsed;
                        break;
                    case 2:
                        $folders[$parsed['id']] = $parsed['title'];
                        $folderParents[$parsed['id']] = $parsed['parent_id'];
                        break;
                    case 4:
                        $resources++;
                        break;
                    default:
                        $other++;
                        break;
                }
            }

            // Count notes by parent folder
            $folderCounts = [];
            $orphaned = 0;
            foreach ($notes as $note) {
                $pid = $note['parent_id'];
                if (empty($pid) || ! isset($folders[$pid])) {
                    $orphaned++;
                } else {
                    $folderCounts[$pid] = ($folderCounts[$pid] ?? 0) + 1;
                }
            }

            // Find Watch Later notes and check for duplicates by title
            $watchLaterNotes = array_filter($notes, fn ($n) => $n['parent_id'] === $watchLaterFolderId);
            $titleCounts = [];
            foreach ($watchLaterNotes as $note) {
                $title = strtolower(trim($note['title']));
                if ($title !== '') {
                    $titleCounts[$title] = ($titleCounts[$title] ?? 0) + 1;
                }
            }
            $duplicateTitles = array_filter($titleCounts, fn ($count) => $count > 1);

            // Watch Later subfolder detection
            $watchLaterSubfolders = array_filter(
                $folderParents,
                fn ($parentId) => $parentId === $watchLaterFolderId
            );

            // Build folder distribution (top 15 by note count)
            arsort($folderCounts);
            $distribution = [];
            foreach (array_slice($folderCounts, 0, 15, true) as $id => $count) {
                $distribution[] = ['folder' => $folders[$id] ?? $id, 'notes' => $count];
            }

            return [
                'total_files' => count($files),
                'total_notes' => count($notes),
                'total_folders' => count($folders),
                'total_resources' => $resources,
                'watch_later_notes' => count($watchLaterNotes),
                'watch_later_subfolders' => count($watchLaterSubfolders),
                'orphaned_notes' => $orphaned,
                'duplicates_found' => count($duplicateTitles),
                'duplicate_titles' => array_slice(array_keys($duplicateTitles), 0, 10),
                'folder_distribution' => $distribution,
                'integrity' => (count($duplicateTitles) === 0 && $orphaned === 0) ? 'clean' : 'issues_found',
            ];
        } catch (\Exception $e) {
            Log::error('YouTubeOpsService::checkJoplinIntegrity failed', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Retry transcript fetching for videos that failed in recent runs.
     * Limited to avoid API quota exhaustion.
     */
    public function retryFailedVideos(int $limit = 3): array
    {
        try {
            $workflow = $this->getWatchLaterWorkflow();
            if (! $workflow) {
                return ['error' => 'Watch Later workflow not found: '.self::WATCH_LATER_WORKFLOW_NAME];
            }

            // Find videos from recent failed node executions
            $failedNodes = DB::select("
                SELECT ne.output
                FROM node_executions ne
                JOIN workflow_runs wr ON wr.id = ne.run_id
                WHERE wr.workflow_id = ?
                AND ne.node_type LIKE '%Transcript%'
                AND ne.state = 'failed'
                AND wr.started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY wr.started_at DESC
                LIMIT ?
            ", [$workflow->id, $limit]);

            $retried = [];
            $transcriptService = app(YouTubeTranscriptService::class);

            foreach ($failedNodes as $node) {
                $data = json_decode($node->output, true);
                $videoId = $data['video_id'] ?? $data['videoId'] ?? null;

                if (! $videoId || in_array($videoId, array_column($retried, 'video_id'))) {
                    continue;
                }

                try {
                    $result = $transcriptService->getTranscript($videoId, 'en', false);
                    $retried[] = [
                        'video_id' => $videoId,
                        'status' => $result['success'] ?? false ? 'success' : 'failed',
                        'method' => $result['method'] ?? 'unknown',
                    ];
                } catch (\Exception $e) {
                    $retried[] = [
                        'video_id' => $videoId,
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ];
                }

                if (count($retried) >= $limit) {
                    break;
                }
            }

            return [
                'retried' => count($retried),
                'results' => $retried,
            ];
        } catch (\Exception $e) {
            Log::error('YouTubeOpsService::retryFailedVideos failed', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Clean up stale transcripts — orphaned entries older than retention
     * period or for videos no longer in the pipeline.
     */
    public function cleanupStaleTranscripts(int $daysOld = 365): array
    {
        try {
            $storageService = app(YouTubeTranscriptStorageService::class);
            $deleted = $storageService->cleanupOld($daysOld);

            return [
                'deleted' => $deleted,
                'retention_days' => $daysOld,
                'message' => $deleted > 0
                    ? "Cleaned up {$deleted} transcripts older than {$daysOld} days"
                    : 'No stale transcripts found',
            ];
        } catch (\Exception $e) {
            Log::error('YouTubeOpsService::cleanupStaleTranscripts failed', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // QUALITY ANALYSIS HELPERS
    // =========================================================================

    /**
     * Analyze text quality using lightweight heuristics (no LLM).
     *
     * Detects: repetition loops, gibberish/nonsense, low information density.
     * Returns a score (0.0-1.0) and list of detected issues.
     */
    private function analyzeTextQuality(string $text): array
    {
        $issues = [];
        $score = 1.0;

        $words = preg_split('/\s+/', mb_strtolower(trim($text)));
        $wordCount = count($words);

        if ($wordCount < 10) {
            return ['score' => 0.0, 'issues' => [['type' => 'insufficient_text', 'detail' => "Only {$wordCount} words"]]];
        }

        // 1. Repetition ratio — unique words / total words
        //    Healthy transcript: >0.30. Garbage loops: <0.15
        $uniqueWords = count(array_unique($words));
        $uniqueRatio = $uniqueWords / $wordCount;

        if ($uniqueRatio < 0.10) {
            $score -= 0.5;
            $issues[] = [
                'type' => 'extreme_repetition',
                'detail' => sprintf('Unique word ratio %.1f%% (threshold 10%%). Likely garbage loop.', $uniqueRatio * 100),
            ];
        } elseif ($uniqueRatio < 0.20) {
            $score -= 0.3;
            $issues[] = [
                'type' => 'high_repetition',
                'detail' => sprintf('Unique word ratio %.1f%% (threshold 20%%). Very repetitive content.', $uniqueRatio * 100),
            ];
        }

        // 2. Repeated phrase detection — find longest repeating n-gram (3-8 words)
        //    Catches "um um um", "subscribe subscribe subscribe", hallucination loops
        $maxRepeatCount = 0;
        $worstPhrase = '';

        for ($n = 3; $n <= min(8, (int) ($wordCount / 3)); $n++) {
            $ngrams = [];
            for ($i = 0; $i <= $wordCount - $n; $i++) {
                $phrase = implode(' ', array_slice($words, $i, $n));
                $ngrams[$phrase] = ($ngrams[$phrase] ?? 0) + 1;
            }

            foreach ($ngrams as $phrase => $count) {
                if ($count > $maxRepeatCount && $count >= 4) {
                    $maxRepeatCount = $count;
                    $worstPhrase = $phrase;
                }
            }
        }

        if ($maxRepeatCount >= 10) {
            $score -= 0.4;
            $issues[] = [
                'type' => 'phrase_loop',
                'detail' => sprintf('Phrase "%s" repeated %d times. Likely auto-caption artifact.', $worstPhrase, $maxRepeatCount),
            ];
        } elseif ($maxRepeatCount >= 6) {
            $score -= 0.2;
            $issues[] = [
                'type' => 'repeated_phrase',
                'detail' => sprintf('Phrase "%s" repeated %d times.', $worstPhrase, $maxRepeatCount),
            ];
        }

        // 3. Average word length — gibberish tends to have unusual word lengths
        //    Normal English: avg 4-6 chars. Garbled output: <3 or >8
        $totalChars = array_sum(array_map('strlen', $words));
        $avgWordLen = $totalChars / $wordCount;

        if ($avgWordLen < 2.5) {
            $score -= 0.3;
            $issues[] = [
                'type' => 'gibberish_short_words',
                'detail' => sprintf('Average word length %.1f chars (expected 4-6). Likely nonsense.', $avgWordLen),
            ];
        } elseif ($avgWordLen > 9.0) {
            $score -= 0.2;
            $issues[] = [
                'type' => 'gibberish_long_words',
                'detail' => sprintf('Average word length %.1f chars (expected 4-6). Possibly garbled encoding.', $avgWordLen),
            ];
        }

        // 4. Filler word density — "um", "uh", "like", "you know", "basically"
        //    Mild filler is normal; extreme density suggests raw unprocessed audio captions
        $fillerWords = ['um', 'uh', 'uh-huh', 'uhm', 'hmm', 'mm', 'mhm', 'ah', 'eh', 'er'];
        $fillerCount = 0;
        foreach ($words as $w) {
            if (in_array($w, $fillerWords, true)) {
                $fillerCount++;
            }
        }
        $fillerRatio = $fillerCount / $wordCount;

        if ($fillerRatio > 0.15) {
            $score -= 0.3;
            $issues[] = [
                'type' => 'excessive_filler',
                'detail' => sprintf('Filler word ratio %.1f%% (%d filler words). Raw unprocessed captions.', $fillerRatio * 100, $fillerCount),
            ];
        } elseif ($fillerRatio > 0.08) {
            $score -= 0.1;
            $issues[] = [
                'type' => 'high_filler',
                'detail' => sprintf('Filler word ratio %.1f%% (%d filler words).', $fillerRatio * 100, $fillerCount),
            ];
        }

        return [
            'score' => max(0.0, round($score, 2)),
            'issues' => $issues,
        ];
    }

    private function getWatchLaterWorkflow(): ?object
    {
        return DB::selectOne(
            'SELECT id, name FROM workflows WHERE name = ? LIMIT 1',
            [self::WATCH_LATER_WORKFLOW_NAME]
        );
    }

    private function getWatchLaterScheduledJobName(string $workflowName): string
    {
        return 'workflow_'.strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($workflowName)));
    }
}
