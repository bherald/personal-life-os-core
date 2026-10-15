<?php

namespace App\Nodes\YouTube;

use App\DTOs\TrustEnvelope;
use App\Exceptions\AI\InferenceAdmissionException;
use App\Nodes\BaseNode;
use App\Services\AIService;
use App\Services\JoplinFilesService;
use App\Services\JoplinYouTubeOrganizer;
use App\Services\SystemConfigService;
use App\Services\TrustBoundaryFormatterService;
use App\Services\YouTubeJoplinDeliveryService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class YouTubeKeyPointsPostProcessor extends BaseNode
{
    private const RUNTIME_POLICY_CONFIG_KEY = 'youtube.key_points_post_processor_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'execute_default_limit' => 50,
        'direct_process_default_limit' => 20,
        'dry_run_default' => false,
        'file_batch_size' => 50,
        'max_seconds' => 900,
        'title_preview_chars' => 50,
        'transcript_min_chars' => 50,
        'transcript_max_chars' => 12000,
        'ai_max_tokens' => 1000,
        'ai_temperature' => 0.0,
    ];

    private const PLACEHOLDER = '*Key points will be extracted*';

    private ?TrustBoundaryFormatterService $trustBoundaryFormatter = null;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    private function trustBoundaryFormatter(): TrustBoundaryFormatterService
    {
        return $this->trustBoundaryFormatter ??= app(TrustBoundaryFormatterService::class);
    }

    public function execute(array $input): array
    {
        try {
            $limit = $this->configuredPositiveInt('limit') ?? $this->runtimePolicyInt('execute_default_limit');
            $dryRun = $this->configuredBool('dry_run') ?? $this->runtimePolicyBool('dry_run_default');

            Log::info('YouTubeKeyPointsPostProcessor: Starting', [
                'limit' => $limit,
                'dry_run' => $dryRun,
                'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
            ]);

            $organizer = app(JoplinYouTubeOrganizer::class);
            $aiService = app(AIService::class);

            $data = $input['data'] ?? $input;
            $result = $this->processPlaceholderNotes($organizer, $aiService, $limit, $dryRun,
                workflowVideos: is_array($data['videos'] ?? null) ? $data['videos'] : []);

            Log::info('YouTubeKeyPointsPostProcessor: Complete', $result);

            return $this->standardOutput(
                array_merge($input['data'] ?? $input, ['key_points_stats' => $result]),
                [
                    'notes_scanned' => $result['scanned'],
                    'placeholders_found' => $result['found'],
                    'notes_updated' => $result['updated'],
                    'notes_failed' => $result['failed'],
                    'limit' => $limit,
                    'dry_run' => $dryRun,
                    'max_seconds' => $result['max_seconds'],
                    'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
                ]
            );

        } catch (Exception $e) {
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                throw $e;
            }
            Log::error('YouTubeKeyPointsPostProcessor: Failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->standardOutput(array_merge($input['data'] ?? $input,
                ['key_points_stats' => ['failed' => 1]]), [], $e->getMessage());
        }
    }

    /**
     * Scan notes for placeholder key points and backfill with AI
     */
    public function processPlaceholderNotes(
        JoplinYouTubeOrganizer $organizer,
        AIService $aiService,
        int $limit = 20,
        bool $dryRun = false,
        ?object $output = null,
        array $workflowVideos = []
    ): array {
        $limit = $limit > 0 ? $limit : $this->runtimePolicyInt('direct_process_default_limit');
        $fileBatchSize = $this->configuredPositiveInt('file_batch_size') ?? $this->runtimePolicyInt('file_batch_size');
        $maxSeconds = $this->configuredNonNegativeInt('max_seconds') ?? $this->runtimePolicyNonNegativeInt('max_seconds');
        $titlePreviewChars = $this->configuredPositiveInt('title_preview_chars')
            ?? $this->runtimePolicyInt('title_preview_chars');

        $stats = [
            'scanned' => 0,
            'found' => 0,
            'updated' => 0,
            'failed' => 0,
            'notes' => [],
            'ai_failures' => [],
            'selected_note_ids' => [],
            'max_seconds' => $maxSeconds,
            'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
        ];

        $joplinPath = $organizer->getJoplinPath();
        $allSources = $this->resolveBooleanConfig('all_youtube_sources', false);
        $watchLaterFolderId = $allSources ? '' : $organizer->getWatchLaterFolderId();
        $reviewMirrorIds = $allSources ? DB::table('joplin_review_items')->pluck('note_id')->all() : [];

        // Fetch all Joplin files
        $files = $organizer->listDirectory($joplinPath);
        $allContents = $organizer->getFileContentsBatch($files, $fileBatchSize);
        try {
            $heldNotes = array_fill_keys(array_column(app(YouTubeJoplinDeliveryService::class)->heldSourceWrites(), 'note_id'), true);
        } catch (\Throwable $failure) {
            if (InferenceAdmissionException::isAdmissionFailure($failure)) {
                throw $failure;
            }
            throw new InferenceAdmissionException('YouTube source write custody is unqualified; processing held', previous: $failure);
        }
        $preferredNotes = [];
        foreach ($workflowVideos as $video) {
            if (is_array($video) && is_string($video['joplin_note_id'] ?? null) && is_string($video['video_id'] ?? null)
                && preg_match('/^[a-f0-9]{32}$/D', $video['joplin_note_id'])
                && preg_match('/^[A-Za-z0-9_-]{11}$/D', $video['video_id'])) {
                if (($video['delivery_repair']['status'] ?? null) === 'hold') {
                    $heldNotes[$video['joplin_note_id']] = true;
                } else {
                    $preferredNotes[$video['joplin_note_id']] = $video['video_id'];
                }
            }
        }
        // Stable ordering keeps the existing backlog order after workflow targets.
        $priority = array_flip(array_keys($preferredNotes));
        if ($priority !== []) {
            uksort($allContents, fn (string $a, string $b): int => ($priority[pathinfo($a, PATHINFO_FILENAME)] ?? count($priority))
                <=> ($priority[pathinfo($b, PATHINFO_FILENAME)] ?? count($priority)));
        }

        // Find the Watch Later folder and all its subfolders
        $watchLaterFolderIds = [$watchLaterFolderId];
        foreach ($allContents as $content) {
            $note = $organizer->parseNote($content);
            if ($note['type'] == 2 && $note['parent_id'] === $watchLaterFolderId) {
                $watchLaterFolderIds[] = $note['id'];
            }
        }

        // Scan notes in Watch Later tree for placeholder
        $placeholderNotes = [];
        foreach ($allContents as $filename => $content) {
            $note = $organizer->parseNote($content);
            $note['filename'] = $filename;
            $stats['scanned']++;

            if (isset($heldNotes[$note['id']]) || $note['type'] != 1 || in_array($note['id'], $reviewMirrorIds, true)
                || ($allSources ? app(JoplinFilesService::class)->youtubeVideoId($note) === null
                    : ! in_array($note['parent_id'], $watchLaterFolderIds))) {
                continue;
            }
            if (isset($preferredNotes[$note['id']])
                && app(JoplinFilesService::class)->youtubeVideoId($note) !== $preferredNotes[$note['id']]) {
                continue;
            }

            if (strpos($content, self::PLACEHOLDER) !== false) {
                $placeholderNotes[] = $note;
                $stats['found']++;
                $stats['selected_note_ids'][] = $note['id'];

                if (count($placeholderNotes) >= $limit) {
                    break;
                }
            }
        }

        $this->logMessage($output, "Found {$stats['found']} notes with placeholder key points (scanned {$stats['scanned']})");

        // Process each placeholder note with wall-clock limit
        $startTime = microtime(true);
        foreach ($placeholderNotes as $note) {
            if ((microtime(true) - $startTime) >= $maxSeconds) {
                $this->logMessage($output, "Wall-clock limit reached ({$maxSeconds}s), stopping");
                break;
            }
            $title = substr($note['title'], 0, $titlePreviewChars);

            // Select only a unique, complete existing stored original. Edited or
            // missing source evidence cannot authorize model dispatch.
            $delivery = app(YouTubeJoplinDeliveryService::class);
            $binding = $delivery->placeholderSource($note);
            if ($binding === null) {
                $this->logMessage($output, "  Skip (exact stored source unavailable): {$title}");
                $stats['failed']++;

                continue;
            }
            $transcript = $binding['content'];
            $minChars = $this->configuredNonNegativeInt('transcript_min_chars')
                ?? $this->runtimePolicyNonNegativeInt('transcript_min_chars');
            if (strlen($transcript) <= $minChars) {
                $stats['failed']++;

                continue;
            }

            // Generate key points via AI
            try {
                $keyPoints = $this->generateKeyPoints($aiService, $transcript, $note['title']);
            } catch (Exception $e) {
                if (InferenceAdmissionException::isAdmissionFailure($e)) {
                    throw $e;
                }
                $cause = InferenceAdmissionException::controllerFailureFrom($e);
                $diagnostic = ['failure_kind' => $cause === [] ? 'exception' : 'controller_refusal', 'controller_failure' => $cause];
                $stats['ai_failures'][] = $diagnostic;
                Log::warning('YouTubeKeyPointsPostProcessor: Typed AI failure', $diagnostic);
                $this->logMessage($output, '  AI generation failed; typed diagnostic retained.');
                $stats['failed']++;

                continue;
            }

            if (empty($keyPoints)) {
                $this->logMessage($output, "  Skip (required key-point output incomplete): {$title}");
                $stats['failed']++;

                continue;
            }

            $this->logMessage($output, '  OK ('.count($keyPoints)." points): {$title}");
            $stats['notes'][] = ['title' => $note['title'], 'key_points_count' => count($keyPoints)];

            if ($dryRun) {
                $stats['updated']++;

                continue;
            }

            try {
                $repair = $delivery->retainMissingOriginal($binding['video_id'], $binding['note_id'], $binding['source_id'], true,
                    $binding['wire_hash'], $binding['source_hash'], $keyPoints, replacePlaceholder: true);
                if (($repair['reason_code'] ?? null) === 'source_write_outcome_unknown') {
                    throw new InferenceAdmissionException('YouTube key-point write outcome is unknown; processing held');
                }
                $success = ($repair['status'] ?? null) === 'retained';
            } catch (\Throwable $e) {
                if (InferenceAdmissionException::isAdmissionFailure($e)) {
                    throw $e;
                }
                try {
                    $held = $delivery->sourceWriteHeld($binding['note_id']);
                } catch (\Throwable $proofFailure) {
                    throw new InferenceAdmissionException('YouTube key-point write custody is unavailable; processing held', previous: $e);
                }
                if ($held) {
                    throw new InferenceAdmissionException('YouTube key-point write outcome is unknown; processing held', previous: $e);
                }
                $success = false;
            }
            if ($success) {
                $stats['updated']++;
            } else {
                $this->logMessage($output, "  <error>Write failed:</error> {$title}");
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Extract transcript text from note body (between ## Full Transcript and ---)
     */
    private function extractTranscript(string $noteContent): ?string
    {
        // Internal separators belong to the source. Only the note's Metadata section ends it.
        if (preg_match('/^## Full Transcript[^\r\n]*\R(.*?)(?=^---[\t ]*\R+## Metadata[\t ]*(?:\R|\z)|^## Metadata[\t ]*(?:\R|\z)|\z)/ms', $noteContent, $matches)) {
            $transcript = trim($matches[1]);

            // Strip the stats block (Word Count, Caption Type, Language lines)
            $transcript = preg_replace('/^\*\*Transcript Stats:\*\*\n(?:- .*\n)*/m', '', $transcript);

            $transcript = trim($transcript);
            $minChars = $this->configuredNonNegativeInt('transcript_min_chars')
                ?? $this->runtimePolicyNonNegativeInt('transcript_min_chars');

            return strlen($transcript) > $minChars ? $transcript : null;
        }

        return null;
    }

    /**
     * Generate key points from transcript using AI
     */
    private function generateKeyPoints(AIService $aiService, string $transcript, string $title): array
    {
        // Keep the complete source; the selected runtime must admit its actual context.
        $wrappedTranscript = $this->trustBoundaryFormatter()->format(new TrustEnvelope(
            sourceType: 'transcript',
            contentType: 'text/plain',
            origin: (string) $title,
            payload: $transcript,
            maxChars: $this->configuredPositiveInt('transcript_max_chars')
                ?? $this->runtimePolicyInt('transcript_max_chars'),
            preservePayload: true,
        ));

        $prompt = <<<PROMPT
Generate 3-8 bullet point key points from this YouTube video transcript.

Video Title: {$title}

RULES:
- Each point: 1-2 sentences of SPECIFIC factual content (names, numbers, dates, technical claims)
- Do NOT write generic summaries like "The video discusses various topics"
- Do NOT write "The speaker talks about..." — state the actual facts
- Chronological order
- Return ONLY bullet points, one per line, each starting with "- "

GOOD example:
- SpaceX launched Starship SN20 from Boca Chica, achieving a 6-minute powered flight before a controlled ocean landing
- The Raptor 2 engines produced 230 tons of thrust each, a 15% improvement over the previous version

BAD example:
- The video covers recent space launches and developments
- Various technical details about rockets are discussed

Transcript:
{$wrappedTranscript}
PROMPT;

        $request = [
            'max_tokens' => $this->configuredPositiveInt('ai_max_tokens') ?? $this->runtimePolicyInt('ai_max_tokens'),
            'temperature' => $this->configuredFloat('ai_temperature') ?? $this->runtimePolicyFloat('ai_temperature'),
        ];
        $role = $this->getConfigValue('model_role');
        if (is_string($role) && trim($role) !== '') {
            $request['model_role'] = trim($role);
        }
        foreach (['allowed_ollama_instance_ids', 'preferred_ollama_instance_ids'] as $key) {
            $instances = $this->getConfigValue($key);
            if (is_array($instances)) {
                $request[$key] = $instances;
            }
        }
        // AIService retains an explicit CLI workflow scope over this node default.
        $result = $aiService->process($prompt, $request);

        if (! ($result['success'] ?? false)) {
            throw new InferenceAdmissionException('AI processing failed', controllerFailure: is_array($result['controller_failure'] ?? null) ? $result['controller_failure'] : []);
        }

        $response = $result['response'] ?? '';
        if (! is_string($response) || trim($response) === '') {
            return [];
        }
        $points = [];

        // The task requires only one complete bullet per line. Never silently
        // discard extra prose or a continuation from a budget-limited reply.
        $lines = preg_split('/\R/u', trim($response));
        if ($lines === false) {
            return [];
        }
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! preg_match('/^-[\t ]+(\S[^\r\n]*)$/D', $line, $match)) {
                return [];
            }
            $points[] = trim($match[1]);
        }

        return app(YouTubeJoplinDeliveryService::class)->validGeneratedKeyPoints($points) ? $points : [];
    }

    private function logMessage(?object $output, string $message): void
    {
        if ($output) {
            $output->writeln($message);
        }
        Log::info('[YouTubeKeyPointsPostProcessor] '.strip_tags($message));
    }

    private function configuredBool(string $key): ?bool
    {
        $value = $this->getConfigValue($key);
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    private function configuredPositiveInt(string $key): ?int
    {
        $value = $this->getConfigValue($key);
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function configuredNonNegativeInt(string $key): ?int
    {
        $value = $this->getConfigValue($key);
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue >= 0 ? $intValue : null;
    }

    private function configuredFloat(string $key): ?float
    {
        $value = $this->getConfigValue($key);
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function runtimePolicyBool(string $key): bool
    {
        $fallback = (bool) self::DEFAULT_RUNTIME_POLICY[$key];
        $value = $this->runtimePolicy()[$key] ?? $fallback;
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $fallback;
    }

    private function runtimePolicyInt(string $key): int
    {
        return $this->positiveInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    private function runtimePolicyNonNegativeInt(string $key): int
    {
        return $this->nonNegativeInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    private function runtimePolicyFloat(string $key): float
    {
        $fallback = (float) self::DEFAULT_RUNTIME_POLICY[$key];
        $value = $this->runtimePolicy()[$key] ?? null;

        return is_numeric($value) ? (float) $value : $fallback;
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        if ($this->runtimePolicy !== null) {
            return $this->runtimePolicy;
        }

        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return $this->runtimePolicy = array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }

    private function nonNegativeInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue >= 0 ? $intValue : $fallback;
    }
}
