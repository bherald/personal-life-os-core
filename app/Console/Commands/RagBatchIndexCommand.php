<?php

namespace App\Console\Commands;

use App\Services\RAGService;
use App\Services\DomainRegistryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RagBatchIndexCommand - Batch index pending records across all domains
 *
 * Processes RAG indexing backlog for all enabled domains.
 */
class RagBatchIndexCommand extends Command
{
    private const DEFAULT_TIMEOUT_MINUTES = 90;
    private const TIMEOUT_SAFETY_BUFFER_SECONDS = 300;
    private const FALLBACK_SECONDS_PER_RECORD = 20.0;

    protected $signature = 'rag:batch-index
                            {--domain= : Specific domain to index}
                            {--limit=100 : Records per domain}
                            {--dry-run : Show what would be indexed without indexing}';

    protected $description = 'Batch index pending records to RAG across all domains';

    private RAGService $ragService;

    public function handle(RAGService $ragService, DomainRegistryService $registry): int
    {
        $this->ragService = $ragService;
        $specificDomain = $this->option('domain');
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info('RAG Batch Indexing' . ($dryRun ? ' (DRY RUN)' : ''));
        $this->newLine();

        $totalIndexed = 0;
        $startedAt = microtime(true);
        $deadlineSeconds = $this->resolveDeadlineSeconds();
        $domains = $specificDomain
            ? [$specificDomain => $registry->getDomain($specificDomain)]
            : $registry->getEnabledDomains();

        foreach ($domains as $key => $domain) {
            if (!$domain) {
                $this->warn("Domain not found: {$key}");
                continue;
            }

            $indexed = $this->indexDomain($key, $domain, $limit, $dryRun, $startedAt, $deadlineSeconds);
            $totalIndexed += $indexed;
        }

        $this->newLine();
        $this->info("Total indexed: {$totalIndexed} records");

        return 0;
    }

    private function indexDomain(string $key, array $domain, int $limit, bool $dryRun, float $startedAt, int $deadlineSeconds): int
    {
        $table = $domain['table'];
        $ragType = $domain['rag_type'] ?? $key;
        $connection = $domain['connection'] ?? 'mysql';

        // Check if table exists and has rag_indexed_at column
        try {
            $hasRagColumn = DB::connection($connection)
                ->getSchemaBuilder()
                ->hasColumn($table, 'rag_indexed_at');

            if (!$hasRagColumn) {
                $this->line("<comment>{$key}:</comment> No rag_indexed_at column, skipping");
                return 0;
            }
        } catch (\Exception $e) {
            $this->line("<comment>{$key}:</comment> Table not accessible ({$e->getMessage()})");
            return 0;
        }

        // Get pending records
        try {
            $pending = DB::connection($connection)->select(
                "SELECT * FROM {$table} WHERE rag_indexed_at IS NULL LIMIT ?",
                [$limit]
            );
        } catch (\Exception $e) {
            $this->warn("{$key}: Error querying - {$e->getMessage()}");
            return 0;
        }

        $count = count($pending);
        if ($count === 0) {
            $this->line("<info>{$key}:</info> No pending records");
            return 0;
        }

        if ($dryRun) {
            $this->line("<info>{$key}:</info> Would index {$count} records");
            return 0;
        }

        $this->line("<info>{$key}:</info> Indexing {$count} records...");

        $indexed = 0;
        $errors = 0;
        $consecutiveFailures = 0;
        $maxConsecutiveFailures = 5; // All providers likely down — stop wasting time
        $progressBar = $this->output->createProgressBar($count);
        $progressBar->start();
        $domainStartedAt = microtime(true);
        $processed = 0;

        foreach ($pending as $record) {
            if ($this->shouldStopBeforeStartingRecord($startedAt, $deadlineSeconds, $processed, $domainStartedAt, $key)) {
                Log::warning("RagBatchIndex: stopping {$key} early for runtime budget", [
                    'deadline_seconds' => $deadlineSeconds,
                    'processed' => $processed,
                    'indexed' => $indexed,
                    'errors' => $errors,
                ]);
                $this->warn("  Stopped early to stay within runtime budget ({$deadlineSeconds}s)");
                break;
            }

            $processed++;
            try {
                $content = $this->buildContent($key, $record);
                $title = $this->buildTitle($key, $record);
                $metadata = $this->buildMetadata($key, $record);

                if (empty($content)) {
                    $progressBar->advance();
                    continue;
                }

                $this->ragService->indexDocument(
                    documentType: $ragType,
                    content: $content,
                    title: $title,
                    metadata: $metadata,
                    sourceId: $record->id ?? null
                );

                // Mark as indexed
                DB::connection($connection)->update(
                    "UPDATE {$table} SET rag_indexed_at = NOW() WHERE id = ?",
                    [$record->id]
                );

                $indexed++;
                $consecutiveFailures = 0; // Reset on success
            } catch (\Exception $e) {
                $errors++;
                $consecutiveFailures++;
                Log::warning("RAG index failed for {$key}:{$record->id}", [
                    'error' => $e->getMessage(),
                ]);

                if ($consecutiveFailures >= $maxConsecutiveFailures) {
                    Log::error("RagBatchIndex: {$maxConsecutiveFailures} consecutive failures for {$key} — all providers likely down, stopping early", [
                        'indexed' => $indexed,
                        'errors' => $errors,
                    ]);
                    $this->error("  Stopped: {$maxConsecutiveFailures} consecutive failures (providers down?)");
                    break;
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        if ($errors > 0) {
            $this->warn("  Indexed: {$indexed}, Errors: {$errors}");
        }

        return $indexed;
    }

    private function resolveDeadlineSeconds(): int
    {
        try {
            $job = DB::selectOne(
                "SELECT timeout_minutes FROM scheduled_jobs WHERE name = 'rag_file_bulk_index' LIMIT 1"
            );
            $timeoutMinutes = max(1, (int) ($job->timeout_minutes ?? self::DEFAULT_TIMEOUT_MINUTES));
        } catch (\Throwable) {
            $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES;
        }

        return max(60, ($timeoutMinutes * 60) - self::TIMEOUT_SAFETY_BUFFER_SECONDS);
    }

    private function shouldStopBeforeStartingRecord(
        float $startedAt,
        int $deadlineSeconds,
        int $processedCount,
        float $domainStartedAt,
        string $domain
    ): bool {
        if ($deadlineSeconds <= 0) {
            return false;
        }

        $elapsedSeconds = microtime(true) - $startedAt;
        $estimatedNextRecordSeconds = $this->estimateNextRecordSeconds($processedCount, $domainStartedAt, $domain);

        return ($elapsedSeconds + $estimatedNextRecordSeconds) >= $deadlineSeconds;
    }

    private function estimateNextRecordSeconds(int $processedCount, float $domainStartedAt, string $domain): float
    {
        if ($processedCount > 0) {
            $avgSecondsPerRecord = (microtime(true) - $domainStartedAt) / $processedCount;
            return max($this->getFallbackSecondsPerRecord($domain), min(180.0, $avgSecondsPerRecord));
        }

        return $this->getFallbackSecondsPerRecord($domain);
    }

    private function getFallbackSecondsPerRecord(string $domain): float
    {
        return match ($domain) {
            'files' => 45.0,
            'research' => 30.0,
            default => self::FALLBACK_SECONDS_PER_RECORD,
        };
    }

    private function buildContent(string $domain, object $record): string
    {
        $parts = [];

        // Domain-specific content building
        switch ($domain) {
            case 'calendar':
                if (!empty($record->title)) $parts[] = "Event: {$record->title}";
                if (!empty($record->description)) $parts[] = $record->description;
                if (!empty($record->location)) $parts[] = "Location: {$record->location}";
                if (!empty($record->calendar_name)) $parts[] = "Calendar: {$record->calendar_name}";
                if (!empty($record->start_at)) $parts[] = "Date: {$record->start_at}";
                break;

            case 'contacts':
                if (!empty($record->full_name)) $parts[] = "Contact: {$record->full_name}";
                if (!empty($record->organization)) $parts[] = "Organization: {$record->organization}";
                if (!empty($record->title)) $parts[] = "Title: {$record->title}";
                if (!empty($record->notes)) $parts[] = $record->notes;
                if (!empty($record->emails)) {
                    $emails = json_decode($record->emails, true);
                    if ($emails) {
                        $parts[] = "Email: " . implode(', ', array_column($emails, 'email'));
                    }
                }
                if (!empty($record->phones)) {
                    $phones = json_decode($record->phones, true);
                    if ($phones) {
                        $parts[] = "Phone: " . implode(', ', array_column($phones, 'number'));
                    }
                }
                break;

            case 'news':
                if (!empty($record->title)) $parts[] = $record->title;
                if (!empty($record->description)) $parts[] = $record->description;
                if (!empty($record->content)) $parts[] = $record->content;
                if (!empty($record->author)) $parts[] = "Author: {$record->author}";
                if (!empty($record->feed_name)) $parts[] = "Source: {$record->feed_name}";
                break;

            case 'genealogy':
                // For genealogy_persons table
                $name = trim(($record->given_name ?? '') . ' ' . ($record->surname ?? ''));
                if (!empty($name)) $parts[] = "Person: {$name}";
                if (!empty($record->birth_date)) $parts[] = "Born: {$record->birth_date}";
                if (!empty($record->birth_place)) $parts[] = "Birth Place: {$record->birth_place}";
                if (!empty($record->death_date)) $parts[] = "Died: {$record->death_date}";
                if (!empty($record->death_place)) $parts[] = "Death Place: {$record->death_place}";
                if (!empty($record->notes)) $parts[] = $record->notes;
                break;

            case 'files':
                // file_registry uses 'filename' and 'current_path'
                if (!empty($record->filename)) $parts[] = "File: {$record->filename}";
                if (!empty($record->current_path)) $parts[] = "Path: {$record->current_path}";
                if (!empty($record->extension)) $parts[] = "Type: {$record->extension}";
                if (!empty($record->mime_type)) $parts[] = "MIME: {$record->mime_type}";
                if (!empty($record->title)) $parts[] = "Title: {$record->title}";
                if (!empty($record->description)) $parts[] = $record->description;
                if (!empty($record->ai_description)) $parts[] = $record->ai_description;
                if (!empty($record->ai_tags)) {
                    $tags = is_string($record->ai_tags) ? json_decode($record->ai_tags, true) : $record->ai_tags;
                    if ($tags && is_array($tags)) {
                        $parts[] = "Tags: " . implode(', ', $tags);
                    }
                }
                if (!empty($record->tags)) {
                    $tags = is_string($record->tags) ? json_decode($record->tags, true) : $record->tags;
                    if ($tags && is_array($tags)) {
                        $parts[] = "Tags: " . implode(', ', $tags);
                    }
                }
                if (!empty($record->search_keywords)) $parts[] = "Keywords: {$record->search_keywords}";

                // For code/text files, extract actual content
                $codeExtensions = ['php', 'js', 'ts', 'py', 'md', 'json', 'yaml', 'yml', 'sh', 'css', 'html', 'vue', 'txt', 'xml', 'sql'];
                if (!empty($record->extension) && in_array(strtolower($record->extension), $codeExtensions)) {
                    $filePath = $record->current_path ?? null;
                    if ($filePath && file_exists($filePath) && is_readable($filePath)) {
                        $content = @file_get_contents($filePath, false, null, 0, 50000); // Max 50KB
                        if ($content !== false && strlen(trim($content)) > 0) {
                            // Truncate to ~8000 chars for embedding limits
                            if (strlen($content) > 8000) {
                                $content = substr($content, 0, 8000) . "\n[truncated]";
                            }
                            $parts[] = "Content:\n" . $content;
                        }
                    }
                }
                break;

            case 'research':
                // research_results uses ai_output, not content/title
                if (!empty($record->ai_output)) $parts[] = $record->ai_output;
                if (!empty($record->ai_recommendation)) $parts[] = "Recommendation: {$record->ai_recommendation}";
                if (!empty($record->extracted_facts)) {
                    $facts = is_string($record->extracted_facts) ? json_decode($record->extracted_facts, true) : $record->extracted_facts;
                    if ($facts && is_array($facts)) {
                        // Facts are objects with type/value - extract values
                        $factStrings = array_map(function($f) {
                            if (is_array($f) && isset($f['value'])) {
                                return ($f['type'] ?? 'fact') . ': ' . $f['value'];
                            }
                            return is_string($f) ? $f : json_encode($f);
                        }, array_slice($facts, 0, 10));
                        $parts[] = "Key Facts: " . implode('; ', $factStrings);
                    }
                }
                break;

            default:
                // Generic fallback
                if (!empty($record->title)) $parts[] = $record->title;
                if (!empty($record->description)) $parts[] = $record->description;
                if (!empty($record->content)) $parts[] = $record->content;
                if (!empty($record->notes)) $parts[] = $record->notes;
        }

        return implode("\n\n", array_filter($parts));
    }

    private function buildTitle(string $domain, object $record): string
    {
        switch ($domain) {
            case 'calendar':
                return $record->title ?? 'Calendar Event';
            case 'contacts':
                return $record->full_name ?? 'Contact';
            case 'news':
                return $record->title ?? 'News Article';
            case 'genealogy':
                return trim(($record->given_name ?? '') . ' ' . ($record->surname ?? '')) ?: 'Person';
            case 'files':
                return $record->filename ?? $record->title ?? 'File';
            case 'research':
                // Build title from topic or first part of ai_output
                $title = 'Research';
                if (!empty($record->ai_output)) {
                    $title = substr(strip_tags($record->ai_output), 0, 80);
                    if (strlen($record->ai_output) > 80) $title .= '...';
                }
                return $title;
            default:
                return $record->title ?? $record->name ?? "Record #{$record->id}";
        }
    }

    private function buildMetadata(string $domain, object $record): array
    {
        $metadata = [
            'domain' => $domain,
            'source_id' => $record->id,
        ];

        // Add domain-specific metadata
        switch ($domain) {
            case 'calendar':
                $metadata['calendar'] = $record->calendar_name ?? null;
                $metadata['start_at'] = $record->start_at ?? null;
                $metadata['location'] = $record->location ?? null;
                break;

            case 'contacts':
                $metadata['organization'] = $record->organization ?? null;
                break;

            case 'news':
                $metadata['feed'] = $record->feed_name ?? null;
                $metadata['published_at'] = $record->published_at ?? null;
                $metadata['url'] = $record->article_url ?? null;
                break;

            case 'genealogy':
                $metadata['birth_date'] = $record->birth_date ?? null;
                $metadata['death_date'] = $record->death_date ?? null;
                break;

            case 'files':
                $metadata['file_path'] = $record->current_path ?? null;
                $metadata['filename'] = $record->filename ?? null;
                $metadata['mime_type'] = $record->mime_type ?? null;
                $metadata['extension'] = $record->extension ?? null;
                $metadata['asset_uuid'] = $record->asset_uuid ?? null;
                break;
        }

        // Add timestamps if available
        if (!empty($record->created_at)) {
            $metadata['created_at'] = $record->created_at;
        }

        return array_filter($metadata);
    }
}
