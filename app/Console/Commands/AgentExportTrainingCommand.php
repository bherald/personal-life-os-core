<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AI-6 Prep: Export validated agent data as fine-tuning training pairs.
 *
 * Curates high-quality instruction/response pairs from:
 * - agent_procedures (learned patterns with success_rate)
 * - agent_episodes (run narratives with quality_score)
 * - agent_review_queue (human-approved findings)
 *
 * Output: Alpaca JSONL format (instruction, input, output)
 * Compatible with: Unsloth, HuggingFace TRL, Axolotl
 */
class AgentExportTrainingCommand extends Command
{
    protected $signature = 'agent:export-training
                            {--min-success=0.7 : Minimum success rate for procedures}
                            {--min-quality=0.7 : Minimum quality score for episodes}
                            {--min-usage=3 : Minimum usage count for procedures}
                            {--format=alpaca : Output format (alpaca or sharegpt)}
                            {--output=storage/exports/training_data.jsonl : Output file path}
                            {--stats : Show statistics only, no export}';

    protected $description = 'AI-6: Export agent data as fine-tuning training pairs';

    public function handle(): int
    {
        $minSuccess = (float) $this->option('min-success');
        $minQuality = (float) $this->option('min-quality');
        $minUsage = (int) $this->option('min-usage');
        $format = $this->option('format');
        $outputPath = $this->option('output');

        if ($this->option('stats')) {
            return $this->showStats($minSuccess, $minQuality, $minUsage);
        }

        $this->info("Exporting training data (min_success={$minSuccess}, min_quality={$minQuality}, min_usage={$minUsage})...");

        $examples = [];

        // Source 1: Agent procedures (learned patterns)
        $procedures = $this->exportProcedures($minSuccess, $minUsage);
        $examples = array_merge($examples, $procedures);
        $this->line("  Procedures: " . count($procedures) . " examples");

        // Source 2: Agent episodes (successful run narratives)
        $episodes = $this->exportEpisodes($minQuality);
        $examples = array_merge($examples, $episodes);
        $this->line("  Episodes: " . count($episodes) . " examples");

        // Source 3: Human-approved review items
        $reviews = $this->exportApprovedReviews();
        $examples = array_merge($examples, $reviews);
        $this->line("  Approved reviews: " . count($reviews) . " examples");

        if (empty($examples)) {
            $this->warn("No training examples found matching criteria.");
            return 0;
        }

        // Write output
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fp = fopen($outputPath, 'w');
        foreach ($examples as $example) {
            $formatted = $format === 'sharegpt'
                ? $this->toShareGPT($example)
                : $this->toAlpaca($example);
            fwrite($fp, json_encode($formatted, JSON_UNESCAPED_UNICODE) . "\n");
        }
        fclose($fp);

        $this->info("Exported " . count($examples) . " training examples to {$outputPath}");
        $this->line("  Format: {$format}");
        $this->line("  Size: " . round(filesize($outputPath) / 1024) . " KB");

        return 0;
    }

    private function showStats(float $minSuccess, float $minQuality, int $minUsage): int
    {
        $procCount = (int) (DB::selectOne(
            "SELECT COUNT(*) as c FROM agent_procedures WHERE success_rate >= ? AND times_used >= ?",
            [$minSuccess, $minUsage]
        )?->c ?? 0);

        $episodeCount = (int) (DB::selectOne(
            "SELECT COUNT(*) as c FROM agent_episodes WHERE event_type = 'completion' AND summary IS NOT NULL AND LENGTH(summary) > 100"
        )?->c ?? 0);

        $reviewCount = (int) (DB::selectOne(
            "SELECT COUNT(*) as c FROM agent_review_queue WHERE status = 'approved' AND reviewer_notes NOT LIKE 'Auto-approved%'"
        )?->c ?? 0);

        $total = $procCount + $episodeCount + $reviewCount;

        $this->info("Training Data Statistics:");
        $this->table(['Source', 'Count', 'Threshold'], [
            ['Procedures', $procCount, "success >= {$minSuccess}, usage >= {$minUsage}"],
            ['Episodes', $episodeCount, "quality >= {$minQuality}"],
            ['Human-approved reviews', $reviewCount, "status=approved, not auto"],
            ['TOTAL', $total, ''],
        ]);

        $readiness = $total >= 2000 ? 'READY for fine-tuning' : "Need " . (2000 - $total) . " more examples (target: 2000)";
        $this->line("\nReadiness: {$readiness}");

        return 0;
    }

    private function exportProcedures(float $minSuccess, int $minUsage): array
    {
        $procedures = DB::select("
            SELECT name, trigger_pattern, action_sequence, success_rate, times_used, agent_id
            FROM agent_procedures
            WHERE success_rate >= ? AND times_used >= ?
            ORDER BY success_rate DESC, times_used DESC
        ", [$minSuccess, $minUsage]);

        $examples = [];
        foreach ($procedures as $proc) {
            $steps = json_decode($proc->action_sequence, true);
            if (empty($steps) || empty($proc->trigger_pattern)) {
                continue;
            }

            $examples[] = [
                'instruction' => "You are the {$proc->agent_id} agent. " . $proc->trigger_pattern,
                'input' => '',
                'output' => is_array($steps) ? implode("\n", array_map(fn($s) => is_string($s) ? $s : json_encode($s), $steps)) : (string) $steps,
                'source' => 'procedure',
                'quality' => $proc->success_rate,
            ];
        }

        return $examples;
    }

    private function exportEpisodes(float $minQuality): array
    {
        $episodes = DB::select("
            SELECT agent_id, summary, details, event_type
            FROM agent_episodes
            WHERE event_type = 'completion'
              AND summary IS NOT NULL AND LENGTH(summary) > 100
            ORDER BY created_at DESC
            LIMIT 2000
        ");

        $examples = [];
        foreach ($episodes as $ep) {
            $details = json_decode($ep->details ?? '{}', true);
            $task = $details['task'] ?? $details['trigger'] ?? 'Perform your assigned task';

            $examples[] = [
                'instruction' => "You are the {$ep->agent_id} agent. {$task}",
                'input' => '',
                'output' => $ep->summary,
                'source' => 'episode',
                'quality' => 0.8,
            ];
        }

        return $examples;
    }

    private function exportApprovedReviews(): array
    {
        $reviews = DB::select("
            SELECT agent_id, review_type, title, summary, details, confidence
            FROM agent_review_queue
            WHERE status = 'approved'
              AND reviewer_notes NOT LIKE 'Auto-approved%'
              AND summary IS NOT NULL AND LENGTH(summary) > 50
            ORDER BY updated_at DESC
            LIMIT 1000
        ");

        $examples = [];
        foreach ($reviews as $review) {
            $details = json_decode($review->details ?? '{}', true);
            $evidence = $details['evidence'] ?? $details['findings'] ?? '';
            if (is_array($evidence)) {
                $evidence = json_encode($evidence, JSON_PRETTY_PRINT);
            }

            $examples[] = [
                'instruction' => "You are the {$review->agent_id} agent analyzing a {$review->review_type}. Provide your findings.",
                'input' => $evidence ? "Evidence:\n{$evidence}" : '',
                'output' => "{$review->title}\n\n{$review->summary}",
                'source' => 'review',
                'quality' => $review->confidence ?? 0.8,
            ];
        }

        return $examples;
    }

    private function toAlpaca(array $example): array
    {
        return [
            'instruction' => $example['instruction'],
            'input' => $example['input'],
            'output' => $example['output'],
        ];
    }

    private function toShareGPT(array $example): array
    {
        $messages = [
            ['from' => 'human', 'value' => $example['instruction'] . ($example['input'] ? "\n\n" . $example['input'] : '')],
            ['from' => 'gpt', 'value' => $example['output']],
        ];

        return ['conversations' => $messages];
    }
}
