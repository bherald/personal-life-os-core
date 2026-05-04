<?php

namespace App\Console\Commands;

use App\Services\RAGService;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RAGSearchCommand extends Command
{
    protected $signature = 'rag:search {query} {--limit=5} {--type=} {--json : Emit machine-readable retrieval evidence}';

    protected $description = 'Search indexed documents using semantic search';

    public function handle(): int
    {
        $query = (string) $this->argument('query');
        $limit = (int) $this->option('limit');
        $type = $this->option('type') !== null && trim((string) $this->option('type')) !== ''
            ? trim((string) $this->option('type'))
            : null;
        $started = microtime(true);

        try {
            $ragService = app(RAGService::class);
            $results = $ragService->search($query, $limit, $type);
            $durationMs = (int) round((microtime(true) - $started) * 1000);

            if (empty($results)) {
                if ($this->option('json')) {
                    $this->line(json_encode([
                        'status' => 'observe_empty',
                        'query' => $query,
                        'limit' => $limit,
                        'type' => $type,
                        'duration_ms' => $durationMs,
                        'result_count' => 0,
                        'results' => [],
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                    return Command::SUCCESS;
                }

                $this->warn('No results found');

                return Command::SUCCESS;
            }

            if ($this->option('json')) {
                $this->line(json_encode([
                    'status' => 'observe_ok',
                    'query' => $query,
                    'limit' => $limit,
                    'type' => $type,
                    'duration_ms' => $durationMs,
                    'result_count' => count($results),
                    'results' => array_map(fn (array $result): array => $this->jsonResult($result), $results),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return Command::SUCCESS;
            }

            $this->info('Searching for: '.$query);
            $this->newLine();
            $this->info('Found '.count($results).' results:');
            $this->newLine();

            foreach ($results as $i => $result) {
                $doc = $result['document'];
                $similarity = round((float) ($result['similarity'] ?? 0) * 100, 2);

                $this->line(sprintf(
                    '[%d] %s (%.2f%% match)',
                    $i + 1,
                    $this->documentTitle($doc),
                    $similarity
                ));
                $this->line('    Type: '.($doc->document_type ?? 'unknown'));
                $this->line('    Date: '.$this->formatDate($doc->created_at ?? null));
                $this->line('    Content: '.$this->preview($doc->content ?? ''));
                $this->newLine();
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'status' => 'failed',
                    'query' => $query,
                    'limit' => $limit,
                    'type' => $type,
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'error' => $e->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return Command::FAILURE;
            }

            $this->error('Search failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function jsonResult(array $result): array
    {
        $doc = $result['document'] ?? null;

        return [
            'title' => is_object($doc) ? $this->documentTitle($doc) : 'Untitled',
            'document_type' => is_object($doc) ? (string) ($doc->document_type ?? 'unknown') : 'unknown',
            'created_at' => is_object($doc) ? $this->formatDate($doc->created_at ?? null) : null,
            'similarity' => round((float) ($result['similarity'] ?? 0), 4),
            'preview' => is_object($doc) ? $this->preview($doc->content ?? '') : '',
        ];
    }

    private function documentTitle(object $doc): string
    {
        $title = trim((string) ($doc->title ?? ''));

        return $title !== '' ? $title : 'Untitled';
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function preview(mixed $value): string
    {
        $content = trim((string) $value);
        if ($content === '') {
            return '';
        }

        return mb_strlen($content) > 100
            ? mb_substr($content, 0, 100).'...'
            : $content;
    }
}
