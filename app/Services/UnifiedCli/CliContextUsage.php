<?php

namespace App\Services\UnifiedCli;

/** Measured context information; never estimates a tokenizer or modifies a prompt. */
final class CliContextUsage
{
    public static function measured(array $route, string $turn, int $input, int $output, string $basis): ?array
    {
        $window = $route['context_window'] ?? null;
        if (! is_int($window) || $window < 1 || $input < 0 || $output < 0
            || $input > PHP_INT_MAX - $output) {
            return null;
        }

        return ['turn_id' => $turn, 'instance_id' => $route['instance_id'], 'model' => $route['model'],
            'input_tokens' => $input, 'output_tokens' => $output, 'context_window' => $window, 'basis' => $basis];
    }

    public static function provider(array $route, string $turn, mixed $usage): ?array
    {
        if (! is_array($usage)) {
            return null;
        }
        $native = ($route['wire_api'] ?? null) === 'ollama_chat';
        $input = $usage[$native ? 'prompt_eval_count' : 'prompt_tokens'] ?? null;
        $output = $usage[$native ? 'eval_count' : 'completion_tokens'] ?? null;
        if (! is_int($input) || ! is_int($output)) {
            return null;
        }

        return self::measured($route, $turn, $input, $output, 'provider_reported');
    }

    public static function notice(CliConfiguration $configuration, array $session, string $turn): ?string
    {
        $usage = $session['context_usage'] ?? null;
        $policy = $configuration->effective($session)['values']['cli.compaction_policy'] ?? null;
        $threshold = is_array($policy) ? ($policy['warning_percent'] ?? null) : null;
        if (! is_int($threshold) || $threshold < 1 || $threshold > 100 || ! is_array($usage)
            || ($usage['turn_id'] ?? null) !== $turn
            || ! is_int($usage['context_window'] ?? null) || $usage['context_window'] < 1
            || ! is_int($usage['input_tokens'] ?? null) || ! is_int($usage['output_tokens'] ?? null)) {
            return null;
        }
        if ($usage['input_tokens'] < 0 || $usage['output_tokens'] < 0
            || $usage['input_tokens'] > PHP_INT_MAX - $usage['output_tokens']) {
            return null;
        }
        $total = $usage['input_tokens'] + $usage['output_tokens'];
        if ($total < 0 || $total / $usage['context_window'] * 100 < $threshold) {
            return null;
        }
        $basis = ($usage['basis'] ?? null) === 'provider_reported' ? 'provider-reported input and output' : 'measured input plus reserved output';
        $compact = ($policy['enabled'] ?? false) === true ? '/compact to review and confirm a summary, ' : '';

        return 'PLOS context notice: '.$total.' / '.$usage['context_window'].' tokens ('.$basis.'). '
            .'Choose '.$compact.'/model to select another model, or /clear to start a new session. '
            .'Full originals remain saved. Nothing is compacted automatically.';
    }
}
