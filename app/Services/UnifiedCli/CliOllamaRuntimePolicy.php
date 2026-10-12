<?php

namespace App\Services\UnifiedCli;

use App\Support\RuntimeBoolean;
use RuntimeException;

/** Read existing role/runtime/controller tables; no new model qualification ledger. */
final class CliOllamaRuntimePolicy
{
    public function resolve(array $candidate, array $values): array
    {
        if (($candidate['configuration_eligible'] ?? false) !== true
            || ($candidate['instance']['instance_type'] ?? null) !== 'ollama') {
            throw new RuntimeException('Selected Ollama route is not admitted by current PLOS tables.');
        }
        $config = CliSettingValue::decode($candidate['instance']['config'] ?? [], 'json');
        $role = $config['role_runtime'][$candidate['role'] ?? ''] ?? [];
        $defaults = $values['cli.runtime_policy'] ?? [];
        $correctionLimit = array_key_exists('max_argument_corrections', $defaults) ? $defaults['max_argument_corrections'] : 0;
        if (! is_int($correctionLimit) || $correctionLimit < 0 || $correctionLimit > 2) {
            throw new RuntimeException('Table-owned max_argument_corrections must be an integer from zero to two.');
        }
        $options = array_replace($config['default_options'] ?? [], $role['options'] ?? []);
        $contract = [
            'model' => $candidate['model'],
            'native_tools_enabled' => ($candidate['native_tools_configured'] ?? false) === true,
            'context_window' => $options['num_ctx'] ?? $role['num_ctx'] ?? $candidate['model_definition']['context_length'] ?? null,
            'max_output_tokens' => $options['num_predict'] ?? $role['max_tokens'] ?? $config['max_tokens'] ?? $defaults['max_output_tokens'] ?? null,
            'max_iterations' => $defaults['max_iterations'] ?? null,
            'max_tool_calls' => $defaults['max_tool_calls'] ?? null,
            'wire_api' => 'ollama_chat', 'context_handling' => 'provider_reject',
        ];
        // Keep the existing route byte/hash contract unchanged while disabled.
        if ($correctionLimit > 0) {
            $contract['max_argument_corrections'] = $correctionLimit;
        }
        foreach (['context_window', 'max_output_tokens', 'max_iterations', 'max_tool_calls'] as $name) {
            if (! is_int($contract[$name]) || $contract[$name] < 1) {
                throw new RuntimeException('A positive table-owned '.$name.' is required for the selected Ollama route.');
            }
        }
        if ($contract['max_output_tokens'] >= $contract['context_window']) {
            throw new RuntimeException('Output budget must leave room for input in the selected context window. Adjust the session runtime settings.');
        }
        foreach (['temperature', 'top_p'] as $name) {
            if (array_key_exists($name, $options) || array_key_exists($name, $role) || array_key_exists($name, $config)) {
                $contract[$name] = $options[$name] ?? $role[$name] ?? $config[$name];
            }
        }
        if (array_key_exists('think', $role)) {
            $contract['enable_thinking'] = RuntimeBoolean::isTrue($role['think']);
        }
        $contract['ollama_options'] = $options;
        $contract['input_modalities'] = ['text'];
        if (in_array('vision', $candidate['model_definition']['capabilities'] ?? [], true)) {
            $contract['input_modalities'][] = 'image';
        }
        if (array_key_exists('input_modalities', $role)) {
            $allowed = $role['input_modalities'];
            if (! is_array($allowed) || $allowed === [] || ! array_is_list($allowed)
                || array_diff($allowed, $contract['input_modalities']) !== []) {
                throw new RuntimeException('Role input modalities exceed the model capability declaration.');
            }
            $contract['input_modalities'] = array_values(array_unique($allowed));
        }
        if (! array_key_exists('controller_catalog', $config)) {
            // Existing PLOS Ollama endpoints may manage their own models (for
            // example a remote server). No local controller is implied.
            return $contract + ['runtime_management' => 'provider'];
        }
        $bindings = $config['controller_catalog']['targets'] ?? [];
        $matches = array_filter($bindings, fn ($binding) => ($binding['model'] ?? null) === $candidate['model']
            && ($binding['engine'] ?? null) === 'ollama'
            && ($binding['provider_instance_id'] ?? null) === $candidate['instance_id']);
        if (count($matches) !== 1) {
            throw new RuntimeException('Selected Ollama model does not match its configured controller catalog. Check the selected model or its table binding.');
        }
        $target = array_key_first($matches);
        $binding = $matches[$target];
        if (($binding['target_id'] ?? null) !== $target || ! is_int($binding['port'] ?? null)
            || (int) parse_url($candidate['instance']['base_url'], PHP_URL_PORT) !== $binding['port']) {
            throw new RuntimeException('Selected Ollama endpoint differs from its controller catalog binding.');
        }
        $contract['controller_target'] = $target;
        $contract['controller_host_id'] = $binding['controller_host_id'] ?? null;

        return $contract;
    }
}
