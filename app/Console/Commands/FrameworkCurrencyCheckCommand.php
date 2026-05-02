<?php

namespace App\Console\Commands;

use App\Controllers\NotificationController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SB-4: Framework Currency Strategy
 *
 * Monitors AI/tech advances relevant to PLOS:
 * - HuggingFace trending models (embedding, vision, NLP)
 * - GitHub releases for key dependencies
 * - Ollama library for new models
 *
 * Submits findings to review queue + optional Pushover digest.
 */
class FrameworkCurrencyCheckCommand extends Command
{
    protected $signature = 'framework:currency-check
        {--dry-run : Show findings without saving or notifying}
        {--notify : Send Pushover digest of findings}
        {--stats : Show cached findings from last run}';

    protected $description = 'Monitor AI/tech advances relevant to PLOS framework';

    private const CACHE_KEY = 'framework_currency_last_findings';

    private const CACHE_TTL = 604800; // 7 days

    private const GITHUB_REPOS = [
        'ollama/ollama' => 'Local LLM runtime',
        'laravel/framework' => 'Backend framework',
        'vuejs/core' => 'Frontend framework',
        'pgvector/pgvector' => 'Vector search',
        'tailwindlabs/tailwindcss' => 'CSS framework',
        'anthropics/claude-code' => 'Claude Code CLI',
    ];

    private const HF_CATEGORIES = [
        'feature-extraction' => 'Embedding models',
        'image-to-text' => 'Vision models',
        'text-generation' => 'Language models',
        'text-classification' => 'NLP classifiers',
        'token-classification' => 'NER models',
        'sentence-similarity' => 'Reranking models',
    ];

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $dryRun = $this->option('dry-run');
        $notify = $this->option('notify');
        $findings = [];

        $this->info('Checking framework currency...');

        // 1. HuggingFace trending models
        $hfFindings = $this->checkHuggingFace();
        if (! empty($hfFindings)) {
            $findings = array_merge($findings, $hfFindings);
            $this->info('  HuggingFace: '.count($hfFindings).' relevant models found');
        }

        // 2. GitHub releases
        $ghFindings = $this->checkGitHubReleases();
        if (! empty($ghFindings)) {
            $findings = array_merge($findings, $ghFindings);
            $this->info('  GitHub: '.count($ghFindings).' new releases found');
        }

        // 3. Ollama library
        $ollamaFindings = $this->checkOllamaNewModels();
        if (! empty($ollamaFindings)) {
            $findings = array_merge($findings, $ollamaFindings);
            $this->info('  Ollama: '.count($ollamaFindings).' new models found');
        }

        if (empty($findings)) {
            $this->info('No new findings.');

            return Command::SUCCESS;
        }

        // Deduplicate against last run
        $lastFindings = Cache::get(self::CACHE_KEY, []);
        $lastKeys = array_map(fn ($f) => $f['key'] ?? '', $lastFindings);
        $newFindings = array_filter($findings, fn ($f) => ! in_array($f['key'] ?? '', $lastKeys));

        if (empty($newFindings)) {
            $this->info('All findings already reported in previous run.');

            return Command::SUCCESS;
        }

        $this->info(count($newFindings).' new findings (after dedup).');

        if ($dryRun) {
            foreach ($newFindings as $f) {
                $this->line("  [{$f['source']}] {$f['title']}");
            }

            return Command::SUCCESS;
        }

        // Save to review queue
        $submitted = 0;
        $skipped = 0;
        foreach ($newFindings as $finding) {
            if ($this->submitToReviewQueue($finding)) {
                $submitted++;
            } else {
                $skipped++;
            }
        }
        $suffix = $skipped > 0 ? " ({$skipped} already pending — skipped)" : '';
        $this->info("Submitted {$submitted} findings to review queue{$suffix}.");

        // Cache findings for dedup
        Cache::put(self::CACHE_KEY, $findings, self::CACHE_TTL);

        // Optional Pushover
        if ($notify && ! empty($newFindings)) {
            $this->sendDigest($newFindings);
        }

        Log::info('FrameworkCurrencyCheck: Run complete', [
            'total_findings' => count($findings),
            'new_findings' => count($newFindings),
            'submitted' => $submitted,
        ]);

        return Command::SUCCESS;
    }

    private function checkHuggingFace(): array
    {
        $findings = [];

        foreach (self::HF_CATEGORIES as $pipeline => $label) {
            try {
                $response = Http::connectTimeout(5)->timeout(15)
                    ->get('https://huggingface.co/api/models', [
                        'pipeline_tag' => $pipeline,
                        'sort' => 'trending',
                        'direction' => -1,
                        'limit' => 5,
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $models = $response->json();
                if (! is_array($models)) {
                    continue;
                }

                foreach ($models as $model) {
                    $modelId = $model['modelId'] ?? $model['id'] ?? '';
                    $downloads = $model['downloads'] ?? 0;
                    $likes = $model['likes'] ?? 0;

                    // Filter: only notable models (>1K downloads or >50 likes)
                    if ($downloads < 1000 && $likes < 50) {
                        continue;
                    }

                    // Skip models we already know about
                    $knownPrefixes = ['bert-base', 'gpt2', 'distilbert', 'roberta-base', 'sentence-transformers/all-MiniLM'];
                    $skip = false;
                    foreach ($knownPrefixes as $prefix) {
                        if (str_starts_with(strtolower($modelId), strtolower($prefix))) {
                            $skip = true;
                            break;
                        }
                    }
                    if ($skip) {
                        continue;
                    }

                    $findings[] = [
                        'key' => "hf:{$modelId}",
                        'source' => 'HuggingFace',
                        'category' => $label,
                        'title' => "{$modelId} — trending in {$label}",
                        'details' => [
                            'model_id' => $modelId,
                            'pipeline' => $pipeline,
                            'downloads' => $downloads,
                            'likes' => $likes,
                            'url' => "https://huggingface.co/{$modelId}",
                            'tags' => $model['tags'] ?? [],
                        ],
                    ];
                }

                usleep(500000); // 0.5s between HF API calls
            } catch (\Exception $e) {
                Log::warning("FrameworkCurrency: HuggingFace {$pipeline} check failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $findings;
    }

    private function checkGitHubReleases(): array
    {
        $findings = [];

        foreach (self::GITHUB_REPOS as $repo => $desc) {
            try {
                $response = Http::connectTimeout(5)->timeout(15)
                    ->withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->get("https://api.github.com/repos/{$repo}/releases", [
                        'per_page' => 1,
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $releases = $response->json();
                if (empty($releases) || ! is_array($releases)) {
                    continue;
                }

                $latest = $releases[0];
                $tag = $latest['tag_name'] ?? '';
                $publishedAt = $latest['published_at'] ?? '';
                $isRecent = ! empty($publishedAt) && strtotime($publishedAt) > strtotime('-14 days');

                if (! $isRecent) {
                    continue;
                }

                $findings[] = [
                    'key' => "gh:{$repo}:{$tag}",
                    'source' => 'GitHub',
                    'category' => $desc,
                    'title' => "{$repo} {$tag} released",
                    'details' => [
                        'repo' => $repo,
                        'tag' => $tag,
                        'published_at' => $publishedAt,
                        'url' => $latest['html_url'] ?? '',
                        'body' => mb_substr($latest['body'] ?? '', 0, 500),
                        'prerelease' => $latest['prerelease'] ?? false,
                    ],
                ];

                usleep(300000); // 0.3s between GitHub API calls
            } catch (\Exception $e) {
                Log::warning("FrameworkCurrency: GitHub {$repo} check failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $findings;
    }

    private function checkOllamaNewModels(): array
    {
        $findings = [];

        try {
            // Get currently installed models
            $ollamaUrl = config('services.ollama.api_url', 'http://127.0.0.1:11434');
            $installed = Http::connectTimeout(5)->timeout(10)->get("{$ollamaUrl}/api/tags")->json('models', []);
            $installedNames = array_map(fn ($m) => strtolower($m['name'] ?? ''), $installed);

            // Check Ollama library for notable new models
            // Use the search endpoint for key categories
            $searchTerms = ['embed', 'vision', 'code', 'llama', 'qwen', 'gemma', 'phi'];

            foreach ($searchTerms as $term) {
                $response = Http::connectTimeout(5)->timeout(15)->get("https://ollama.com/api/tags/{$term}");
                if (! $response->successful()) {
                    // Ollama doesn't have a public search API — skip gracefully
                    break;
                }

                $models = $response->json('models', []);
                foreach ($models as $model) {
                    $name = strtolower($model['name'] ?? '');
                    if (! $name || in_array($name, $installedNames)) {
                        continue;
                    }

                    $findings[] = [
                        'key' => "ollama:{$name}",
                        'source' => 'Ollama',
                        'category' => 'New model',
                        'title' => "{$name} available in Ollama library",
                        'details' => [
                            'model' => $name,
                            'url' => "https://ollama.com/library/{$name}",
                            'size' => $model['size'] ?? null,
                        ],
                    ];
                }

                usleep(500000);
            }
        } catch (\Exception $e) {
            // Ollama library API may not be publicly documented — fail silently
            Log::info('FrameworkCurrency: Ollama library check skipped', [
                'error' => $e->getMessage(),
            ]);
        }

        return $findings;
    }

    private function submitToReviewQueue(array $finding): bool
    {
        $token = bin2hex(random_bytes(32));

        try {
            DB::insert("
                INSERT INTO agent_review_queue
                (agent_id, review_type, title, summary, details, confidence, priority, status, token, expires_at, created_at, updated_at)
                VALUES (?, 'finding', ?, ?, ?, 0.70, 0, 'pending', ?, DATE_ADD(NOW(), INTERVAL 14 DAY), NOW(), NOW())
            ", [
                'system',
                mb_substr($finding['title'], 0, 500),
                "[{$finding['source']}] {$finding['category']}: {$finding['title']}",
                json_encode($finding['details']),
                $token,
            ]);
            return true;
        } catch (\Illuminate\Database\QueryException $e) {
            // uk_arq_pending_dedup (migration 2026_04_17_175500) fires when the same
            // finding is already pending from a prior run and the in-memory dedup
            // cache was cleared between runs. Skip silently — the operator already
            // has a pending review item for this title.
            $isDupKey = $e->getCode() === '23000' || str_contains($e->getMessage(), '1062');
            if (! $isDupKey) {
                throw $e;
            }
            Log::info('FrameworkCurrencyCheck: duplicate finding skipped (already pending)', [
                'title' => $finding['title'] ?? '',
                'source' => $finding['source'] ?? '',
            ]);
            return false;
        }
    }

    private function sendDigest(array $findings): void
    {
        $lines = ['PLOS Framework Currency — '.now()->format('M d')];
        $lines[] = count($findings)." new findings:\n";

        $bySource = [];
        foreach ($findings as $f) {
            $bySource[$f['source']][] = $f;
        }

        foreach ($bySource as $source => $items) {
            $lines[] = "{$source} (".count($items).'):';
            foreach (array_slice($items, 0, 5) as $item) {
                $lines[] = "  • {$item['title']}";
            }
            if (count($items) > 5) {
                $lines[] = '  + '.(count($items) - 5).' more';
            }
        }

        try {
            $notifier = app(NotificationController::class);
            $notifier->send('pushover', [
                'source_group' => 'ops_maintenance',
                'title' => 'Framework Currency Update',
                'message' => implode("\n", $lines),
                'priority' => -1,
                'format_type' => 'monospace',
                'sound' => 'none',
            ]);
        } catch (\Exception $e) {
            Log::warning('FrameworkCurrency: Pushover failed', ['error' => $e->getMessage()]);
        }
    }

    private function showStats(): int
    {
        $cached = Cache::get(self::CACHE_KEY, []);

        if (empty($cached)) {
            $this->info('No cached findings from previous run.');

            return Command::SUCCESS;
        }

        $bySource = [];
        foreach ($cached as $f) {
            $bySource[$f['source']][] = $f;
        }

        $this->table(
            ['Source', 'Count', 'Latest'],
            array_map(fn ($source, $items) => [
                $source,
                count($items),
                $items[0]['title'] ?? '—',
            ], array_keys($bySource), array_values($bySource))
        );

        return Command::SUCCESS;
    }
}
