<?php

namespace App\Console\Commands;

use App\Services\BitLlmPrivacyGateService;
use Illuminate\Console\Command;

class BitLlmPrivacyGateCommand extends Command
{
    protected $signature = 'bitllm:privacy-gate
        {--text= : Redacted request description to classify}
        {--eval : Run the built-in redacted privacy eval pack}
        {--limit=0 : Limit eval cases, 0 means all}
        {--json : Output JSON}';

    protected $description = 'Run the vetted bit-LLM privacy deny/allow gate through table-routed local_llm providers';

    public function handle(BitLlmPrivacyGateService $gate): int
    {
        $text = trim((string) $this->option('text'));
        $eval = (bool) $this->option('eval');
        $limit = max(0, (int) $this->option('limit'));

        if (! $eval && $text === '') {
            $this->error('Pass --text or --eval.');

            return self::INVALID;
        }

        $payload = $eval
            ? ['mode' => 'eval'] + $gate->evaluate($limit)
            : ['mode' => 'single'] + $gate->decide($text, ['redacted_input' => true]);

        $payload['eligible_providers'] = $gate->eligibleProviders();

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($eval) {
            $summary = $payload['summary'] ?? [];
            $this->info(sprintf(
                'BitLLM privacy eval: %d/%d correct, accuracy=%s, dangerous_false_allows=%d, safe_false_denies=%d',
                (int) ($summary['correct'] ?? 0),
                (int) ($summary['total'] ?? 0),
                (string) ($summary['accuracy'] ?? '0'),
                (int) ($summary['dangerous_false_allows'] ?? 0),
                (int) ($summary['safe_false_denies'] ?? 0),
            ));
        } else {
            $this->info(sprintf(
                'BitLLM decision: %s provider=%s model=%s',
                (string) ($payload['decision'] ?? 'error'),
                (string) ($payload['provider'] ?? '-'),
                (string) ($payload['model'] ?? '-'),
            ));
        }

        if ($eval) {
            return ((int) data_get($payload, 'summary.dangerous_false_allows', 1)) === 0
                ? self::SUCCESS
                : self::FAILURE;
        }

        return ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
