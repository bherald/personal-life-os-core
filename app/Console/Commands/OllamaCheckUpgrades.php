<?php

namespace App\Console\Commands;

use App\Controllers\NotificationController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OllamaCheckUpgrades - Monthly model advisor for Ollama LLMs
 *
 * Checks for newer/better models that fit the production VRAM constraint (6GB),
 * sends a Pushover notification with recommendations and the exact command
 * to execute changes with human approval.
 *
 * Scheduled: First Sunday of every month at 9:00 AM
 * Cron: 0 9 1-7 * 0
 */
class OllamaCheckUpgrades extends Command
{
    protected $signature = 'ollama:check-upgrades
                            {--force : Send notification even if no upgrades found}
                            {--dry-run : Show what would be sent without sending}
                            {--vram=6 : Maximum VRAM in GB for model selection}';

    protected $description = 'Check for Ollama model upgrades and notify human with recommendations';

    // Ollama library API for available models
    private const OLLAMA_LIBRARY_API = 'https://ollama.com/api/tags';

    // Known model categories with VRAM requirements (approximate GB)
    private const MODEL_VRAM_MAP = [
        // Instruct/Chat models
        'llama3.1:8b' => 5.0,
        'llama3.2:3b' => 2.5,
        'llama3.2:1b' => 1.2,
        'llama3:8b' => 5.0,
        'mistral:7b' => 4.5,
        'mixtral:8x7b' => 26.0, // Too large
        'phi3:3.8b' => 2.8,
        'phi3:14b' => 9.0,
        'gemma:7b' => 5.0,
        'gemma:2b' => 1.8,
        'gemma2:9b' => 6.0,
        'gemma2:2b' => 1.8,
        'qwen:7b' => 5.0,
        'qwen2:7b' => 5.0,
        'qwen2.5:7b' => 5.0,
        'qwen2.5:3b' => 2.5,
        'deepseek-coder:6.7b' => 4.5,
        'codellama:7b' => 4.5,
        'codellama:13b' => 8.5,
        'starcoder2:7b' => 5.0,

        // Vision models
        'llava:7b' => 4.5,
        'llava:13b' => 8.5,
        'llava-llama3:8b' => 5.5,
        'bakllava:7b' => 5.0,
        'moondream:1.8b' => 1.5,

        // Embedding models
        'nomic-embed-text' => 0.3,
        'mxbai-embed-large' => 0.7,
        'all-minilm' => 0.1,
        'snowflake-arctic-embed' => 0.6,
    ];

    // Model categories for framework use cases
    private const USE_CASE_CATEGORIES = [
        'instruct' => [
            'description' => 'General LLM for summaries, formatting, classification',
            'current_env' => 'OLLAMA_MODEL',
            'models' => ['llama3.1', 'llama3.2', 'llama3', 'mistral', 'phi3', 'gemma', 'gemma2', 'qwen', 'qwen2', 'qwen2.5'],
        ],
        'vision' => [
            'description' => 'Image analysis and OCR enhancement',
            'current_env' => 'OLLAMA_VISION_MODEL',
            'models' => ['llava', 'llava-llama3', 'bakllava', 'moondream'],
        ],
        'embedding' => [
            'description' => 'RAG vector embeddings (768-dim compatible)',
            'current_env' => 'OLLAMA_EMBEDDING_MODEL',
            'models' => ['nomic-embed-text', 'mxbai-embed-large', 'snowflake-arctic-embed'],
        ],
    ];

    public function handle(): int
    {
        $maxVram = (float) $this->option('vram');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        $this->info('Ollama Model Upgrade Advisor');
        $this->info('============================');
        $this->info("Max VRAM: {$maxVram}GB");

        // 1. Get currently installed models from Ollama
        $installed = $this->getInstalledModels();
        if (empty($installed)) {
            $this->error('Could not connect to Ollama or no models installed');

            return 1;
        }

        $this->info("\nCurrently Installed Models:");
        foreach ($installed as $model) {
            $size = $this->formatBytes($model['size'] ?? 0);
            $this->line("  - {$model['name']} ({$size})");
        }

        // 2. Get current configuration
        $currentConfig = $this->getCurrentConfig();
        $this->info("\nCurrent Configuration:");
        foreach ($currentConfig as $key => $value) {
            $this->line("  - {$key}: {$value}");
        }

        // 3. Fetch available models and find upgrades
        $recommendations = $this->findRecommendations($installed, $currentConfig, $maxVram);

        // 4. Build notification message
        $hasUpgrades = ! empty($recommendations['upgrades']);
        $hasAlternatives = ! empty($recommendations['alternatives']);

        if (! $hasUpgrades && ! $hasAlternatives && ! $force) {
            $this->info("\nNo upgrades or alternatives found. Your models are up to date!");

            return 0;
        }

        $message = $this->buildNotificationMessage($currentConfig, $recommendations, $maxVram);
        $command = $this->buildUpgradeCommand($recommendations);

        $this->info("\n".str_repeat('=', 60));
        $this->info('NOTIFICATION PREVIEW:');
        $this->info(str_repeat('=', 60));
        $this->line($message);
        $this->info(str_repeat('=', 60));

        if ($dryRun) {
            $this->warn("\n[DRY RUN] Notification NOT sent");

            return 0;
        }

        // 5. Send Pushover notification
        $sent = $this->sendNotification($message, $command);

        if ($sent) {
            $this->info("\n✓ Pushover notification sent successfully");

            // Log for audit
            Log::info('Ollama upgrade check completed', [
                'recommendations' => $recommendations,
                'notification_sent' => true,
            ]);
        } else {
            $this->error("\n✗ Failed to send Pushover notification");

            return 1;
        }

        return 0;
    }

    /**
     * Get installed models from local Ollama
     */
    private function getInstalledModels(): array
    {
        $ollamaUrl = config('services.ollama.api_url', 'http://127.0.0.1:11434');

        try {
            $response = Http::connectTimeout(5)->timeout(10)->get("{$ollamaUrl}/api/tags");

            if ($response->successful()) {
                return $response->json('models') ?? [];
            }
        } catch (\Exception $e) {
            $this->warn('Could not connect to Ollama: '.$e->getMessage());
        }

        return [];
    }

    /**
     * Get current model configuration
     */
    private function getCurrentConfig(): array
    {
        return [
            'OLLAMA_MODEL' => config('services.ollama.model'),
            'OLLAMA_EMBEDDING_MODEL' => config('services.ollama.embedding_model'),
            'OLLAMA_VISION_MODEL' => config('services.ollama.vision_model'),
        ];
    }

    /**
     * Find recommended upgrades and alternatives
     */
    private function findRecommendations(array $installed, array $currentConfig, float $maxVram): array
    {
        $recommendations = [
            'upgrades' => [],
            'alternatives' => [],
            'notes' => [],
        ];

        // Check each use case category
        foreach (self::USE_CASE_CATEGORIES as $category => $info) {
            $currentModel = $currentConfig[$info['current_env']] ?? '';
            $currentBase = $this->extractModelBase($currentModel);

            // Find newer versions of the same model family
            foreach ($info['models'] as $modelFamily) {
                if (strpos($currentBase, $modelFamily) !== false) {
                    $upgrade = $this->findNewerVersion($modelFamily, $currentModel, $maxVram);
                    if ($upgrade) {
                        $recommendations['upgrades'][$category] = [
                            'current' => $currentModel,
                            'recommended' => $upgrade['model'],
                            'reason' => $upgrade['reason'],
                            'vram' => $upgrade['vram'] ?? 'unknown',
                        ];
                    }
                }
            }

            // Find alternatives from different families
            $alternatives = $this->findAlternatives($category, $currentBase, $maxVram);
            if (! empty($alternatives)) {
                $recommendations['alternatives'][$category] = $alternatives;
            }
        }

        // Add general notes
        $recommendations['notes'] = $this->getContextualNotes($currentConfig, $maxVram);

        return $recommendations;
    }

    /**
     * Extract base model name (without quantization suffix)
     */
    private function extractModelBase(string $model): string
    {
        // Remove quantization suffixes like :8b-instruct-q5_K_M
        return preg_replace('/:.*$/', '', $model);
    }

    /**
     * Find newer version of a model family
     */
    private function findNewerVersion(string $family, string $current, float $maxVram): ?array
    {
        // Known version progression for common families with available sizes
        // Format: 'family' => ['version' => [available sizes]]
        $versionSizeMap = [
            'llama3' => [
                'llama3' => ['8b', '70b'],
                'llama3.1' => ['8b', '70b', '405b'],
                'llama3.2' => ['1b', '3b'],  // No 8B variant!
                // llama3.3 only has 70B - excluded
            ],
            'gemma' => [
                'gemma' => ['2b', '7b'],
                'gemma2' => ['2b', '9b', '27b'],
            ],
            'qwen' => [
                'qwen' => ['7b', '14b', '72b'],
                'qwen2' => ['0.5b', '1.5b', '7b', '72b'],
                'qwen2.5' => ['0.5b', '1.5b', '3b', '7b', '14b', '32b', '72b'],
            ],
            'phi' => [
                'phi' => ['2b'],
                'phi2' => ['2.7b'],
                'phi3' => ['3.8b', '14b'],
                'phi4' => ['14b'],
            ],
            'mistral' => [
                'mistral' => ['7b'],
                'mistral-nemo' => ['12b'],
            ],
        ];

        // Extract current model size
        $currentSize = $this->extractSize($current);

        foreach ($versionSizeMap as $baseFamily => $versions) {
            if (strpos($family, $baseFamily) !== false || $family === $baseFamily) {
                // Find current version in the map (match longest version first)
                $currentVersion = null;
                $versionKeys = array_keys($versions);

                // Sort by length descending to match more specific versions first
                // e.g., match "llama3.1" before "llama3"
                usort($versionKeys, fn ($a, $b) => strlen($b) - strlen($a));

                foreach ($versionKeys as $ver) {
                    if (strpos($current, $ver) !== false) {
                        $currentVersion = $ver;
                        break;
                    }
                }

                // Restore original order for index comparison
                $versionKeys = array_keys($versions);

                if ($currentVersion === null) {
                    continue;
                }

                $currentIdx = array_search($currentVersion, $versionKeys);

                // Check newer versions (from newest to current+1)
                for ($i = count($versionKeys) - 1; $i > $currentIdx; $i--) {
                    $newerVersion = $versionKeys[$i];
                    $availableSizes = $versions[$newerVersion];

                    // Check if current size exists in newer version
                    if (in_array($currentSize, $availableSizes)) {
                        $testModel = $newerVersion.':'.$currentSize;
                        $vram = $this->estimateVram($testModel);

                        if ($vram <= $maxVram) {
                            return [
                                'model' => $testModel,
                                'reason' => "Newer version available ({$newerVersion} vs {$currentVersion})",
                                'vram' => $vram,
                            ];
                        }
                    }

                    // If exact size not available, find closest smaller size that fits VRAM
                    foreach ($availableSizes as $size) {
                        $testModel = $newerVersion.':'.$size;
                        $vram = $this->estimateVram($testModel);

                        if ($vram <= $maxVram && $vram >= 3.0) { // At least 3GB for reasonable quality
                            return [
                                'model' => $testModel,
                                'reason' => "Newer version ({$newerVersion}:{$size}) - different size than current",
                                'vram' => $vram,
                            ];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract size from model name (e.g., "8b" from "llama3.1:8b-instruct")
     */
    private function extractSize(string $model): string
    {
        if (preg_match('/:(\d+b)/', $model, $matches)) {
            return $matches[1];
        }

        return '7b'; // Default assumption
    }

    /**
     * Estimate VRAM usage for a model
     */
    private function estimateVram(string $model): float
    {
        $base = strtolower($this->extractModelBase($model));

        foreach (self::MODEL_VRAM_MAP as $pattern => $vram) {
            if (strpos($model, $pattern) !== false || strpos($base, $pattern) !== false) {
                return $vram;
            }
        }

        // Estimate based on parameter count
        if (preg_match('/(\d+)b/', $model, $matches)) {
            $params = (int) $matches[1];

            // Rough estimate: 1B params ≈ 0.6GB VRAM for Q4 quantization
            return $params * 0.6;
        }

        return 5.0; // Conservative default
    }

    /**
     * Find alternative models for a use case
     */
    private function findAlternatives(string $category, string $currentBase, float $maxVram): array
    {
        $alternatives = [];
        $categoryInfo = self::USE_CASE_CATEGORIES[$category] ?? null;

        if (! $categoryInfo) {
            return [];
        }

        foreach ($categoryInfo['models'] as $family) {
            if (strpos($currentBase, $family) === false) {
                // Different family - could be an alternative
                foreach (self::MODEL_VRAM_MAP as $model => $vram) {
                    if (strpos($model, $family) !== false && $vram <= $maxVram) {
                        $alternatives[] = [
                            'model' => $model,
                            'vram' => $vram,
                            'note' => "Alternative family: {$family}",
                        ];
                        break; // One per family
                    }
                }
            }
        }

        return array_slice($alternatives, 0, 3); // Max 3 alternatives per category
    }

    /**
     * Get contextual notes based on configuration
     */
    private function getContextualNotes(array $config, float $maxVram): array
    {
        $notes = [];

        // Check embedding model compatibility
        if (strpos($config['OLLAMA_EMBEDDING_MODEL'], 'nomic-embed-text') !== false) {
            $notes[] = 'nomic-embed-text is the standard 768-dim model. Changing requires RAG re-indexing.';
        }

        // VRAM utilization note
        $totalVram = 0;
        foreach ($config as $model) {
            $totalVram += $this->estimateVram($model);
        }
        if ($totalVram > $maxVram * 0.8) {
            $notes[] = "Current models use ~{$totalVram}GB. Consider smaller quantizations if switching.";
        }

        // Quantization note
        foreach ($config as $model) {
            if (strpos($model, 'q5_K_M') !== false || strpos($model, 'q4_K_M') !== false) {
                $notes[] = 'Using quantized models is good for VRAM efficiency.';
                break;
            }
        }

        return $notes;
    }

    /**
     * Build the notification message
     */
    private function buildNotificationMessage(array $currentConfig, array $recommendations, float $maxVram): string
    {
        $lines = [];
        $lines[] = '🤖 OLLAMA MODEL ADVISOR';
        $lines[] = 'Monthly Check - '.date('F Y');
        $lines[] = "VRAM Limit: {$maxVram}GB";
        $lines[] = '';

        // Current models
        $lines[] = '📋 CURRENT:';
        $lines[] = '• LLM: '.($currentConfig['OLLAMA_MODEL'] ?? 'not set');
        $lines[] = '• Vision: '.($currentConfig['OLLAMA_VISION_MODEL'] ?? 'not set');
        $lines[] = '• Embed: '.($currentConfig['OLLAMA_EMBEDDING_MODEL'] ?? 'not set');
        $lines[] = '';

        // Recommended upgrades
        if (! empty($recommendations['upgrades'])) {
            $lines[] = '⬆️ RECOMMENDED UPGRADES:';
            foreach ($recommendations['upgrades'] as $category => $upgrade) {
                $lines[] = "• {$category}: {$upgrade['current']} → {$upgrade['recommended']}";
                $lines[] = "  Reason: {$upgrade['reason']}";
            }
            $lines[] = '';
        }

        // Alternatives
        if (! empty($recommendations['alternatives'])) {
            $lines[] = '🔄 ALTERNATIVES TO CONSIDER:';
            foreach ($recommendations['alternatives'] as $category => $alts) {
                $altNames = array_map(fn ($a) => $a['model'], $alts);
                $lines[] = "• {$category}: ".implode(', ', $altNames);
            }
            $lines[] = '';
        }

        // Notes
        if (! empty($recommendations['notes'])) {
            $lines[] = '📝 NOTES:';
            foreach ($recommendations['notes'] as $note) {
                $lines[] = "• {$note}";
            }
            $lines[] = '';
        }

        // No changes needed
        if (empty($recommendations['upgrades']) && empty($recommendations['alternatives'])) {
            $lines[] = '✅ All models are current. No upgrades recommended.';
            $lines[] = '';
        }

        // Action instructions
        $lines[] = '─────────────────────────';
        $lines[] = 'TO APPLY CHANGES:';
        $lines[] = 'Reply or ask AI assistant:';
        $lines[] = '';
        $lines[] = '"Apply Ollama model upgrades as recommended"';
        $lines[] = '';
        $lines[] = 'This will:';
        $lines[] = '1. Pull new models';
        $lines[] = '2. Update .env.production';
        $lines[] = '3. Remove old models';
        $lines[] = '4. Restart Ollama';

        return implode("\n", $lines);
    }

    /**
     * Build the upgrade command for reference
     */
    private function buildUpgradeCommand(array $recommendations): string
    {
        $commands = [];

        foreach ($recommendations['upgrades'] as $category => $upgrade) {
            $commands[] = "ollama pull {$upgrade['recommended']}";
        }

        if (empty($commands)) {
            return '# No upgrades to apply';
        }

        return implode("\n", $commands);
    }

    /**
     * Send Pushover notification
     */
    private function sendNotification(string $message, string $command): bool
    {
        try {
            $controller = new NotificationController;

            $result = $controller->send('pushover', [
                'source_group' => 'ops_maintenance',
                'title' => '🤖 Ollama Model Advisor',
                'message' => $message,
                'priority' => 0,
                'format_type' => 'monospace',
            ]);

            return $result['success'] ?? false;
        } catch (\Exception $e) {
            Log::error('Failed to send Ollama upgrade notification', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
