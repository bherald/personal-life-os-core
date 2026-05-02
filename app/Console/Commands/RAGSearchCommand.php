<?php

namespace App\Console\Commands;

use App\Services\RAGService;
use App\Services\AIService;
use Illuminate\Console\Command;

class RAGSearchCommand extends Command
{
    protected $signature = 'rag:search {query} {--limit=5} {--type=}';
    protected $description = 'Search indexed documents using semantic search';

    public function handle(): int
    {
        $query = $this->argument('query');
        $limit = (int) $this->option('limit');
        $type = $this->option('type');

        $this->info("Searching for: {$query}");
        $this->newLine();

        try {
            $ragService = app(RAGService::class);
            $results = $ragService->search($query, $limit, $type);

            if (empty($results)) {
                $this->warn('No results found');
                return Command::SUCCESS;
            }

            $this->info("Found {count($results)} results:");
            $this->newLine();

            foreach ($results as $i => $result) {
                $doc = $result['document'];
                $similarity = round($result['similarity'] * 100, 2);

                $this->line(sprintf(
                    "[%d] %s (%.2f%% match)",
                    $i + 1,
                    $doc->title ?: 'Untitled',
                    $similarity
                ));
                $this->line("    Type: {$doc->document_type}");
                $this->line("    Date: {$doc->created_at->format('Y-m-d H:i:s')}");
                $this->line("    Content: " . substr($doc->content, 0, 100) . '...');
                $this->newLine();
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Search failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
