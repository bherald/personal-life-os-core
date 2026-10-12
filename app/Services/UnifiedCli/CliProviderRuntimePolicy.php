<?php

namespace App\Services\UnifiedCli;

use RuntimeException;

/** Server-managed Chat Completions settings from the selected PLOS table row. */
final class CliProviderRuntimePolicy
{
    public static function supports(array $instance): bool
    {
        $config = CliSettingValue::decode($instance['config'] ?? [], 'json');

        return ($instance['instance_type'] ?? null) === 'openai'
            || (in_array($instance['instance_type'] ?? null, ['custom', 'local_llm'], true)
                && ($config['adapter'] ?? null) === 'openai_compatible');
    }

    public function resolve(array $candidate, array $values): array
    {
        $instance = $candidate['instance'];
        $config = CliSettingValue::decode($instance['config'] ?? [], 'json');
        if (! self::supports($instance) || CliAgentRuntime::isColibriEngine($candidate['engine'] ?? null)
            || ($candidate['configuration_eligible'] ?? false) !== true) {
            throw new RuntimeException('Selected route is not a table-admitted server-managed Chat Completions provider.');
        }
        $settings = array_replace($config, $config['role_runtime'][$candidate['role'] ?? ''] ?? []);
        $defaults = $values['cli.runtime_policy'] ?? [];
        $correctionLimit = array_key_exists('max_argument_corrections', $defaults) ? $defaults['max_argument_corrections'] : 0;
        if (! is_int($correctionLimit) || $correctionLimit < 0 || $correctionLimit > 2) {
            throw new RuntimeException('Table-owned max_argument_corrections must be an integer from zero to two.');
        }
        $openai = ($instance['instance_type'] ?? null) === 'openai';
        $contract = [
            'model' => $candidate['model'], 'runtime_management' => 'provider', 'wire_api' => 'chat_completions',
            'native_tools_enabled' => ($candidate['native_tools_configured'] ?? false) === true,
            'context_handling' => $settings['context_handling'] ?? ($openai ? 'provider_reject' : null),
            'context_window' => $settings['context_window'] ?? $candidate['model_definition']['context_length'] ?? null,
            'max_output_tokens' => $settings['max_completion_tokens'] ?? $settings['max_tokens'] ?? $defaults['max_output_tokens'] ?? null,
            'max_iterations' => $defaults['max_iterations'] ?? null, 'max_tool_calls' => $defaults['max_tool_calls'] ?? null,
            'output_token_parameter' => $settings['output_token_parameter'] ?? ($openai ? 'max_completion_tokens' : 'max_tokens'),
            'accepted_response_models' => $settings['response_model_aliases'][$candidate['model']] ?? [],
        ];
        // Keep the existing route byte/hash contract unchanged while disabled.
        if ($correctionLimit > 0) {
            $contract['max_argument_corrections'] = $correctionLimit;
        }
        if ($contract['context_handling'] !== 'provider_reject') {
            throw new RuntimeException('Configure the selected compatible provider to reject context overflow in its table runtime settings; history cannot be shortened automatically.');
        }
        foreach (['context_window', 'max_output_tokens', 'max_iterations', 'max_tool_calls'] as $name) {
            if (! is_int($contract[$name]) || $contract[$name] < 1) {
                throw new RuntimeException('A positive table-owned '.$name.' is required for the selected provider.');
            }
        }
        if ($contract['max_output_tokens'] >= $contract['context_window']) {
            throw new RuntimeException('Output budget must leave room for input. Adjust the selected runtime settings.');
        }
        if (! in_array($contract['output_token_parameter'], ['max_tokens', 'max_completion_tokens'], true)
            || ! is_array($contract['accepted_response_models']) || ! array_is_list($contract['accepted_response_models'])) {
            throw new RuntimeException('Invalid selected provider token parameter or response model aliases.');
        }
        foreach ($contract['accepted_response_models'] as $alias) {
            if (! is_string($alias) || trim($alias) === '') {
                throw new RuntimeException('Response model aliases must be exact nonempty model identifiers.');
            }
        }
        foreach (['temperature', 'top_p', 'reasoning_effort', 'absolute_timeout_seconds', 'idle_timeout_seconds'] as $name) {
            if (array_key_exists($name, $settings)) {
                $contract[$name] = $settings[$name];
            }
        }
        $contract['input_modalities'] = ['text'];
        if (in_array('vision', $candidate['model_definition']['capabilities'] ?? [], true)) {
            $contract['input_modalities'][] = 'image';
        }

        if (array_key_exists('controller_catalog', $config)) {
            if (! in_array($instance['instance_type'] ?? null, ['custom', 'local_llm'], true)
                || ! is_string($candidate['engine'] ?? null) || $candidate['engine'] === '') {
                throw new RuntimeException('Only an explicitly bound local compatible provider can use the host controller.');
            }
            $bindings = $config['controller_catalog']['targets'] ?? [];
            if (! is_array($bindings)) {
                throw new RuntimeException('Compatible provider controller catalog is invalid.');
            }
            $matches = array_filter($bindings, fn ($binding) => is_array($binding)
                && ($binding['model'] ?? null) === $candidate['model']
                && ($binding['engine'] ?? null) === $candidate['engine']
                && ($binding['provider_instance_id'] ?? null) === $candidate['instance_id']);
            if (count($matches) !== 1) {
                throw new RuntimeException('Selected compatible model has no unique controller catalog binding.');
            }
            $target = array_key_first($matches);
            $binding = $matches[$target];
            if (! is_string($target) || ($binding['target_id'] ?? null) !== $target
                || ! is_string($binding['controller_host_id'] ?? null) || $binding['controller_host_id'] === ''
                || ! is_int($binding['port'] ?? null)
                || parse_url($instance['base_url'], PHP_URL_PORT) !== $binding['port']) {
                throw new RuntimeException('Selected compatible endpoint differs from its controller catalog binding.');
            }
            $contract['runtime_management'] = 'controller';
            $contract['controller_target'] = $target;
            $contract['controller_host_id'] = $binding['controller_host_id'];
            $contract['controller_engine'] = $binding['engine'];
        }

        return $contract;
    }
}
