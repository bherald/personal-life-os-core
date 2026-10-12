<?php

namespace App\Services\UnifiedCli;

use InvalidArgumentException;

/** Human model discovery from the existing registry; never probes or starts engines. */
final class CliOperatorPresentation
{
    public function __construct(private readonly CliConfiguration $configuration) {}

    public function catalog(array $session, string $query = ''): array
    {
        $rows = [];
        $candidates = array_column((new CliRouteResolver($this->configuration))->inspect($session, modelChoices: true)['candidates'], null, 'id');
        foreach ($this->configuration->modelsForSession($session) as $model) {
            $candidate = $candidates[$model['id']] ?? null;
            $reasons = $candidate['reasons'] ?? ['route_not_registered'];
            $state = $reasons === [] ? 'eligible' : 'blocked';
            if (in_array('runtime_compatibility_stale', $reasons, true)) {
                $state = 'stale';
            } elseif (! $model['available']) {
                $state = 'unavailable';
            }
            $rows[] = $model + ['configuration_eligible' => $reasons === [], 'state' => $state,
                'reasons' => $reasons, 'runtime_readiness' => 'unchecked',
                'next_action' => $reasons === [] ? '/model '.$model['id'] : '/model-info '.$model['id']];
        }
        $tokens = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($rows, fn ($row) => $this->matches($row, $tokens)));
    }

    public function models(array $session, string $query = ''): string
    {
        $rows = $this->catalog($session, $query);
        $lines = [count($rows).' models'.($query === '' ? '' : ' matching '.$this->text($query)).'. Registry evidence only; runtime readiness is checked before use.'];
        foreach ($rows as $row) {
            $capabilities = $this->capabilities($row);
            $lines[] = $this->text($row['id']).' | '.$this->text($row['engine'] ?? 'unknown engine')
                .' | '.$this->text($row['short_host'] ?? $row['instance'] ?? 'catalog').' | '.$row['state']
                .($capabilities === [] ? '' : ' | '.$this->text(implode(', ', $capabilities)));
        }
        if ($rows === []) {
            $lines[] = 'No matches. /models lists every registered entry; state:eligible filters configuration eligibility, not live readiness.';
        }
        $lines[] = 'Choose: /model INSTANCE::MODEL. Details: /model-info INSTANCE::MODEL. Raw: /models --json.';
        $lines[] = 'Search: /models qwen | /models host:.87 | /models capability:vision | /models state:eligible';

        return implode("\n", $lines);
    }

    public function modelDetails(array $session, string $id): string
    {
        $models = array_column($this->catalog($session), null, 'id');
        $row = $models[$id] ?? null;
        if ($row === null) {
            throw new InvalidArgumentException('Unknown exact model identity. Use /models to find INSTANCE::MODEL.');
        }
        $lines = [$this->text($row['id']), 'Engine: '.$this->text($row['engine'] ?? 'unknown')
            .' | Host: '.$this->text($row['host'] ?? 'not registered'),
            'Capabilities: '.$this->text(implode(', ', $this->capabilities($row)) ?: 'not registered'),
            'Configuration: '.$row['state'].' | Runtime: unchecked',
            'Registry status: '.$this->text($row['status'])];
        foreach ($row['reasons'] as $reason) {
            $lines[] = 'Reason: '.$this->text(str_replace('_', ' ', $reason));
        }
        if (array_intersect($row['reasons'], ['manual_engine_model_conflict', 'manual_profile_model_conflict', 'role_model_not_advertised'])) {
            $lines[] = 'Inspect /engine, /profile and /role; set conflicting selections to auto if intended. Nothing was reset.';
        }
        if (in_array('embedding_only_workflow', $row['reasons'], true)) {
            $lines[] = 'Use this model through the embedding/RAG workflow; it cannot answer a chat turn.';
        }
        $lines[] = 'Select: /model '.$this->text($row['id']);
        $lines[] = 'Then inspect /route. For controller state and activation options, use /help host. Selection does not activate a model.';

        return implode("\n", $lines);
    }

    /** Native picker labels share the same evidence vocabulary as the text catalog. */
    public function pickerModel(array $model): array
    {
        $capabilities = $this->capabilities($model);
        $description = trim((string) ($model['description'] ?? ''));
        $details = array_filter([
            $model['state'] ?? ($model['available'] ? 'registry available' : 'unavailable'),
            'runtime unchecked',
            $description,
            $capabilities === [] ? null : '['.implode(', ', $capabilities).']',
            isset($model['context_length']) ? 'context '.$model['context_length'] : null,
            ($model['is_censored'] ?? null) === false ? 'uncensored' : null,
            $model['id'],
        ]);

        return ['id' => $model['id'], 'model' => $model['id'],
            'displayName' => $this->text(($model['display_name'] ?: $model['model']).' @ '
                .($model['short_host'] ?? $model['instance'] ?? 'catalog').' ['.($model['engine'] ?? 'unknown').']'),
            'description' => $this->text(implode(' | ', $details)),
            'hidden' => false, 'supportedReasoningEfforts' => [], 'defaultReasoningEffort' => 'none', 'isDefault' => false];
    }

    private function matches(array $row, array $tokens): bool
    {
        foreach ($tokens as $token) {
            $field = null;
            $value = $token;
            if (preg_match('/^(host|engine|capability|state):(.*)$/', $token, $match)) {
                [, $field, $value] = $match;
            }
            if ($field === 'state' && $value !== $row['state']) {
                return false;
            }
            $search = match ($field) {
                'host' => [$row['host'], $row['short_host'], $row['instance']],
                'engine' => [$row['engine']],
                'capability' => $this->capabilities($row),
                'state' => [$row['state']],
                default => array_intersect_key($row, array_flip(['id', 'display_name', 'description', 'engine', 'host', 'short_host', 'capabilities', 'use_cases'])),
            };
            if ($value === '' || mb_stripos(json_encode($search, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $value) === false) {
                return false;
            }
        }

        return true;
    }

    private function capabilities(array $model): array
    {
        $result = [];
        foreach ($model['capabilities'] ?? [] as $key => $value) {
            if (is_string($value)) {
                $result[] = $value;
            } elseif (is_string($key) && in_array($value, [true, 1, '1'], true)) {
                $result[] = $key;
            }
        }

        return array_values(array_unique($result));
    }

    private function text(string $value): string
    {
        // Registry text must not inject terminal controls or additional rows.
        return preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
    }
}
