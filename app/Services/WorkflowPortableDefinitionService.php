<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class WorkflowPortableDefinitionService
{
    public const SCHEMA = 'plos.portable_workflow.v2';

    public const DSL_VERSION = '2.0';

    private const MAX_FILE_BYTES = 2_097_152;

    private const MAX_NODES = 500;

    public function __construct(
        private readonly ?WorkflowExecutionRedactionService $redactor = null,
    ) {}

    /** @return array<string,mixed> */
    public function export(string $workflowName): array
    {
        $workflowName = trim($workflowName);
        if ($workflowName === '') {
            throw new InvalidArgumentException('Workflow name is required.');
        }

        $workflow = DB::selectOne('SELECT * FROM workflows WHERE name = ? LIMIT 1', [$workflowName]);
        if ($workflow === null) {
            throw new RuntimeException("Workflow not found: {$workflowName}");
        }

        $warnings = [];
        $definition = [
            'name' => (string) $workflow->name,
            'description' => $workflow->description !== null ? (string) $workflow->description : null,
            'schedule' => $workflow->schedule !== null ? (string) $workflow->schedule : null,
            'active' => (bool) $workflow->active,
            'current_version' => max(1, (int) ($workflow->current_version ?? 1)),
            'error_handling' => (string) ($workflow->error_handling ?? 'stop'),
            'retry' => $this->exportRetry((int) $workflow->id),
            'defaults' => $this->portableEntries(
                DB::select(
                    'SELECT config_key, config_value FROM workflow_defaults WHERE workflow_id = ? ORDER BY config_key',
                    [$workflow->id]
                ),
                'defaults',
                $warnings
            ),
            'nodes' => $this->exportNodes((int) $workflow->id, $warnings),
        ];

        $manifest = $this->manifest($definition, $warnings);
        $validation = $this->validate($manifest);
        if ($validation['errors'] !== []) {
            throw new RuntimeException('Exported workflow failed portable-definition validation.');
        }

        return $manifest;
    }

    /** @return array<string,mixed> */
    public function read(string $path): array
    {
        $path = trim($path);
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Workflow definition is not a readable file: {$path}");
        }

        $size = filesize($path);
        if ($size === false || $size < 2 || $size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('Workflow definition must be between 2 bytes and 2 MiB.');
        }

        try {
            $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Workflow definition is not valid JSON.', previous: $e);
        }

        if (! is_array($manifest)) {
            throw new InvalidArgumentException('Workflow definition root must be an object.');
        }

        return $manifest;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array{status:string,manifest:array<string,mixed>,warnings:list<array<string,mixed>>,errors:list<array<string,mixed>>,summary:array<string,mixed>,integrity:array<string,mixed>}
     */
    public function validate(array $manifest): array
    {
        $warnings = [];
        $errors = [];
        $legacy = ($manifest['schema'] ?? null) !== self::SCHEMA;
        if ($legacy) {
            $manifest = $this->normalizeLegacyManifest($manifest, $warnings, $errors);
        }

        $definition = $manifest['workflow'] ?? null;
        if (! is_array($definition)) {
            $errors[] = $this->finding('workflow_missing', 'workflow', 'Workflow definition must be an object.');
            $definition = [];
        }

        $this->validateWorkflow($definition, $warnings, $errors);
        $expectedHash = $this->workflowHash($definition);
        $recordedHash = $manifest['integrity']['workflow_sha256'] ?? null;
        $integrityStatus = $legacy ? 'unverified_legacy' : 'verified';
        if (! $legacy && (! is_string($recordedHash) || ! hash_equals($expectedHash, strtolower($recordedHash)))) {
            $errors[] = $this->finding(
                'integrity_mismatch',
                'integrity.workflow_sha256',
                'Workflow definition hash does not match its normalized content.'
            );
            $integrityStatus = 'mismatch';
        }

        $manifest['workflow'] = $definition;
        $manifest['warnings'] = $this->dedupeFindings(array_values(array_merge(
            is_array($manifest['warnings'] ?? null) ? $manifest['warnings'] : [],
            $warnings
        )));

        return [
            'status' => $errors !== [] ? 'blocked' : ($manifest['warnings'] !== [] ? 'ready_with_warnings' : 'ready'),
            'manifest' => $manifest,
            'warnings' => $manifest['warnings'],
            'errors' => $errors,
            'summary' => [
                'name' => is_string($definition['name'] ?? null) ? $definition['name'] : null,
                'nodes' => is_array($definition['nodes'] ?? null) ? count($definition['nodes']) : 0,
                'defaults' => is_array($definition['defaults'] ?? null) ? count($definition['defaults']) : 0,
                'imported_active' => false,
            ],
            'integrity' => [
                'status' => $integrityStatus,
                'expected_workflow_sha256' => $expectedHash,
                'recorded_workflow_sha256' => is_string($recordedHash) ? $recordedHash : null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    public function planImport(array $manifest, bool $overwrite = false): array
    {
        $validation = $this->validate($manifest);
        $name = $validation['summary']['name'];
        $existing = is_string($name) && $name !== ''
            ? DB::selectOne('SELECT id, active FROM workflows WHERE name = ? LIMIT 1', [$name])
            : null;
        $historyCount = $existing !== null
            ? (int) DB::scalar('SELECT COUNT(*) FROM workflow_runs WHERE workflow_id = ?', [$existing->id])
            : 0;

        if ($existing !== null && ! $overwrite) {
            $validation['errors'][] = $this->finding(
                'workflow_exists',
                'workflow.name',
                'Workflow already exists; use --overwrite only after reviewing this preview.'
            );
        }
        if ($existing !== null && $overwrite && $historyCount > 0) {
            $validation['errors'][] = $this->finding(
                'overwrite_history_blocked',
                'workflow.name',
                'Overwrite is blocked because this workflow has execution history.'
            );
        }

        $validation['status'] = $validation['errors'] !== []
            ? 'blocked'
            : ($validation['warnings'] !== [] ? 'ready_with_warnings' : 'ready');
        $validation['existing'] = [
            'found' => $existing !== null,
            'active' => $existing !== null ? (bool) $existing->active : null,
            'execution_history_count' => $historyCount,
            'overwrite_requested' => $overwrite,
        ];
        $validation['posture'] = [
            'read_only' => true,
            'requires_execute_and_confirm' => true,
            'imported_active' => false,
            'secrets_included' => false,
            'history_deletion_allowed' => false,
        ];

        return $validation;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    public function import(array $manifest, bool $overwrite = false): array
    {
        $plan = $this->planImport($manifest, $overwrite);
        if ($plan['status'] === 'blocked') {
            throw new InvalidArgumentException('Workflow import is blocked by validation.');
        }

        /** @var array<string,mixed> $definition */
        $definition = $plan['manifest']['workflow'];

        return DB::transaction(function () use ($definition, $overwrite, $plan): array {
            $existing = DB::table('workflows')
                ->select('id')
                ->where('name', $definition['name'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null && ! $overwrite) {
                throw new RuntimeException('Workflow was created after preview; rerun the import preview.');
            }

            if ($existing !== null) {
                $historyCount = (int) DB::scalar(
                    'SELECT COUNT(*) FROM workflow_runs WHERE workflow_id = ?',
                    [$existing->id]
                );
                if ($historyCount > 0) {
                    throw new RuntimeException('Workflow gained execution history after preview; overwrite aborted.');
                }
                $workflowId = (int) $existing->id;
                DB::delete('DELETE FROM retry_configs WHERE workflow_id = ?', [$workflowId]);
                DB::delete('DELETE FROM workflow_defaults WHERE workflow_id = ?', [$workflowId]);
                DB::delete('DELETE FROM workflow_nodes WHERE workflow_id = ?', [$workflowId]);
                DB::update(
                    'UPDATE workflows SET description = ?, schedule = ?, active = 0, current_version = ?, error_handling = ?, updated_at = ? WHERE id = ?',
                    [
                        $definition['description'],
                        $definition['schedule'],
                        $definition['current_version'],
                        $definition['error_handling'],
                        now(),
                        $workflowId,
                    ]
                );
            } else {
                DB::insert(
                    'INSERT INTO workflows (name, description, schedule, active, current_version, error_handling, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?, ?, ?)',
                    [
                        $definition['name'],
                        $definition['description'],
                        $definition['schedule'],
                        $definition['current_version'],
                        $definition['error_handling'],
                        now(),
                        now(),
                    ]
                );
                $workflowId = (int) DB::getPdo()->lastInsertId();
            }

            $this->insertRetry($workflowId, $definition['retry']);
            $this->insertEntries('workflow_defaults', 'workflow_id', $workflowId, $definition['defaults']);
            foreach ($definition['nodes'] as $node) {
                DB::insert(
                    'INSERT INTO workflow_nodes (workflow_id, node_type, node_order, timeout_seconds, compensation_handler, compensation_config, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $workflowId,
                        $node['node_type'],
                        $node['node_order'],
                        $node['timeout_seconds'],
                        $node['compensation_handler'],
                        $node['compensation_config'],
                        now(),
                    ]
                );
                $nodeId = (int) DB::getPdo()->lastInsertId();
                $this->insertEntries('workflow_node_configs', 'workflow_node_id', $nodeId, $node['configs']);
            }

            return [
                'status' => 'imported_inactive',
                'workflow_id' => $workflowId,
                'name' => $definition['name'],
                'nodes' => count($definition['nodes']),
                'warnings' => $plan['warnings'],
                'active' => false,
                'overwritten' => $existing !== null,
            ];
        });
    }

    /** @return array<string,mixed>|null */
    private function exportRetry(int $workflowId): ?array
    {
        $retry = DB::selectOne(
            'SELECT id, max_attempts, notify_on_failure, backoff_strategy FROM retry_configs WHERE workflow_id = ? LIMIT 1',
            [$workflowId]
        );
        if ($retry === null) {
            return null;
        }

        return [
            'max_attempts' => (int) $retry->max_attempts,
            'notify_on_failure' => (string) $retry->notify_on_failure,
            'backoff_strategy' => (string) $retry->backoff_strategy,
            'backoff_intervals' => array_map(
                static fn (object $row): array => [
                    'attempt_number' => (int) $row->attempt_number,
                    'backoff_seconds' => (int) $row->backoff_seconds,
                ],
                DB::select(
                    'SELECT attempt_number, backoff_seconds FROM retry_backoff_intervals WHERE retry_config_id = ? ORDER BY attempt_number',
                    [$retry->id]
                )
            ),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $warnings
     * @return list<array<string,mixed>>
     */
    private function exportNodes(int $workflowId, array &$warnings): array
    {
        return array_map(function (object $node) use (&$warnings): array {
            $compensationConfig = $node->compensation_config !== null
                ? (string) $node->compensation_config
                : null;
            if ($compensationConfig !== null) {
                $redacted = ($this->redactor ?? new WorkflowExecutionRedactionService)->forDisplay([
                    'compensation_config' => $compensationConfig,
                ]);
                if ((int) ($redacted['redaction']['redacted_fields'] ?? 0) > 0) {
                    $warnings[] = $this->finding(
                        'secret_value_omitted',
                        'nodes.'.(int) $node->node_order.'.compensation_config',
                        'Sensitive compensation configuration was omitted and must be restored before activation.'
                    );
                    $compensationConfig = null;
                }
            }

            return [
                'node_type' => (string) $node->node_type,
                'node_order' => (int) $node->node_order,
                'timeout_seconds' => $node->timeout_seconds !== null ? (int) $node->timeout_seconds : null,
                'compensation_handler' => $node->compensation_handler !== null
                    ? (string) $node->compensation_handler
                    : null,
                'compensation_config' => $compensationConfig,
                'configs' => $this->portableEntries(
                    DB::select(
                        'SELECT config_key, config_value FROM workflow_node_configs WHERE workflow_node_id = ? ORDER BY config_key',
                        [$node->id]
                    ),
                    'nodes.'.(int) $node->node_order.'.configs',
                    $warnings
                ),
            ];
        }, DB::select(
            'SELECT id, node_type, node_order, timeout_seconds, compensation_handler, compensation_config FROM workflow_nodes WHERE workflow_id = ? ORDER BY node_order',
            [$workflowId]
        ));
    }

    /**
     * @param  list<object>  $rows
     * @param  list<array<string,mixed>>  $warnings
     * @return list<array<string,mixed>>
     */
    private function portableEntries(array $rows, string $path, array &$warnings): array
    {
        $entries = [];
        foreach ($rows as $index => $row) {
            $key = (string) $row->config_key;
            $value = $row->config_value !== null ? (string) $row->config_value : null;
            $redacted = ($this->redactor ?? new WorkflowExecutionRedactionService)->forDisplay([
                'key' => $key,
                'value' => $value,
            ]);
            $omitted = (int) ($redacted['redaction']['redacted_fields'] ?? 0) > 0;
            if ($omitted) {
                $warnings[] = $this->finding(
                    'secret_value_omitted',
                    "{$path}.{$index}.value",
                    'Sensitive configuration value was omitted and must be restored before activation.'
                );
            }
            $entries[] = [
                'key' => $key,
                'value' => $omitted ? null : $value,
                'requires_runtime_value' => $omitted,
            ];
        }

        return $entries;
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string,mixed>
     */
    private function manifest(array $definition, array $warnings): array
    {
        return [
            'schema' => self::SCHEMA,
            'dsl_version' => self::DSL_VERSION,
            'source' => [
                'product' => 'PLOS',
                'format' => 'native-portable-workflow',
            ],
            'workflow' => $definition,
            'integrity' => [
                'algorithm' => 'sha256',
                'workflow_sha256' => $this->workflowHash($definition),
            ],
            'warnings' => $warnings,
            'exported_at' => now()->toIso8601String(),
            'posture' => [
                'secrets_included' => false,
                'imported_active' => false,
                'import_requires_execute_and_confirm' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  list<array<string,mixed>>  $warnings
     * @param  list<array<string,mixed>>  $errors
     * @return array<string,mixed>
     */
    private function normalizeLegacyManifest(array $manifest, array &$warnings, array &$errors): array
    {
        if (! isset($manifest['workflow'], $manifest['nodes'])
            || ! is_array($manifest['workflow'])
            || ! is_array($manifest['nodes'])) {
            $errors[] = $this->finding('schema_unsupported', 'schema', 'Unsupported workflow definition schema.');

            return $manifest;
        }

        $warnings[] = $this->finding(
            'legacy_v1_normalized',
            'schema',
            'Legacy v1 workflow export was normalized; it has no source integrity receipt.'
        );
        $definition = [
            'name' => $manifest['workflow']['name'] ?? null,
            'description' => $manifest['workflow']['description'] ?? null,
            'schedule' => $manifest['workflow']['schedule'] ?? null,
            'active' => (bool) ($manifest['workflow']['active'] ?? false),
            'current_version' => max(1, (int) ($manifest['workflow']['current_version'] ?? 1)),
            'error_handling' => $manifest['workflow']['error_handling'] ?? 'stop',
            'retry' => $this->normalizeLegacyRetry($manifest['retry_config'] ?? null),
            'defaults' => $this->normalizeLegacyEntries($manifest['defaults'] ?? [], 'defaults', $warnings),
            'nodes' => [],
        ];
        foreach ($manifest['nodes'] as $index => $node) {
            if (! is_array($node)) {
                $errors[] = $this->finding('node_invalid', "nodes.{$index}", 'Legacy node must be an object.');

                continue;
            }
            $definition['nodes'][] = [
                'node_type' => $node['node_type'] ?? null,
                'node_order' => (int) ($node['node_order'] ?? $index),
                'timeout_seconds' => isset($node['timeout_seconds']) ? (int) $node['timeout_seconds'] : null,
                'compensation_handler' => $node['compensation_handler'] ?? null,
                'compensation_config' => $node['compensation_config'] ?? null,
                'configs' => $this->normalizeLegacyEntries(
                    $node['configs'] ?? [],
                    "nodes.{$index}.configs",
                    $warnings
                ),
            ];
        }

        return $this->manifest($definition, $warnings);
    }

    /** @return array<string,mixed>|null */
    private function normalizeLegacyRetry(mixed $retry): ?array
    {
        if (! is_array($retry)) {
            return null;
        }

        return [
            'max_attempts' => max(1, (int) ($retry['max_attempts'] ?? 1)),
            'notify_on_failure' => $retry['notify_on_failure'] ?? 'pushover',
            'backoff_strategy' => $retry['backoff_strategy'] ?? 'exponential',
            'backoff_intervals' => array_values(array_map(
                static fn (mixed $row): array => is_array($row) ? [
                    'attempt_number' => (int) ($row['attempt_number'] ?? 0),
                    'backoff_seconds' => (int) ($row['backoff_seconds'] ?? 0),
                ] : [],
                is_array($retry['backoff_intervals'] ?? null) ? $retry['backoff_intervals'] : []
            )),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $warnings
     * @return list<array<string,mixed>>
     */
    private function normalizeLegacyEntries(mixed $rows, string $path, array &$warnings): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $objects = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $objects[] = (object) [
                    'config_key' => $row['config_key'] ?? $row['key'] ?? '',
                    'config_value' => $row['config_value'] ?? $row['value'] ?? null,
                ];
            }
        }

        return $this->portableEntries($objects, $path, $warnings);
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  list<array<string,mixed>>  $warnings
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateWorkflow(array &$definition, array &$warnings, array &$errors): void
    {
        $name = $definition['name'] ?? null;
        if (! is_string($name)
            || trim($name) === ''
            || trim($name) !== $name
            || mb_strlen($name) > 255) {
            $errors[] = $this->finding('name_invalid', 'workflow.name', 'Name must contain 1-255 characters.');
        }

        foreach (['description' => 65_535, 'schedule' => 255] as $field => $maxLength) {
            $value = $definition[$field] ?? null;
            if ($value !== null && (! is_string($value) || mb_strlen($value) > $maxLength)) {
                $errors[] = $this->finding("{$field}_invalid", "workflow.{$field}", "{$field} is invalid or too long.");
            }
        }
        if (! in_array($definition['error_handling'] ?? null, ['stop', 'continue'], true)) {
            $errors[] = $this->finding('error_handling_invalid', 'workflow.error_handling', 'Use stop or continue.');
        }
        if (! is_int($definition['current_version'] ?? null) || $definition['current_version'] < 1) {
            $errors[] = $this->finding('current_version_invalid', 'workflow.current_version', 'Version must be a positive integer.');
        }
        if (! is_bool($definition['active'] ?? null)) {
            $errors[] = $this->finding('active_state_invalid', 'workflow.active', 'Active state must be a boolean.');
        }
        if (($definition['active'] ?? false) === true) {
            $warnings[] = $this->finding(
                'active_state_not_imported',
                'workflow.active',
                'Imported workflows are always inactive until separately reviewed and activated.'
            );
        }

        $definition['retry'] = $this->validateRetry($definition['retry'] ?? null, $errors);
        $definition['defaults'] = $this->validateEntries($definition['defaults'] ?? null, 'workflow.defaults', $warnings, $errors);
        $nodes = $definition['nodes'] ?? null;
        if (! is_array($nodes) || ! array_is_list($nodes) || count($nodes) > self::MAX_NODES) {
            $errors[] = $this->finding('nodes_invalid', 'workflow.nodes', 'Nodes must be a list of at most 500 items.');
            $definition['nodes'] = [];

            return;
        }

        $orders = [];
        foreach ($nodes as $index => &$node) {
            if (! is_array($node)) {
                $errors[] = $this->finding('node_invalid', "workflow.nodes.{$index}", 'Node must be an object.');

                continue;
            }
            $type = $node['node_type'] ?? null;
            if (! is_string($type) || preg_match('/^[A-Za-z0-9_.\\\\-]{1,255}$/', $type) !== 1) {
                $errors[] = $this->finding('node_type_invalid', "workflow.nodes.{$index}.node_type", 'Node type is invalid.');
            }
            $order = $node['node_order'] ?? null;
            if (! is_int($order) || $order < 0 || $order > 100_000 || isset($orders[$order])) {
                $errors[] = $this->finding('node_order_invalid', "workflow.nodes.{$index}.node_order", 'Node order must be unique and non-negative.');
            } else {
                $orders[$order] = true;
            }
            $timeout = $node['timeout_seconds'] ?? null;
            if ($timeout !== null && (! is_int($timeout) || $timeout < 1 || $timeout > 86_400)) {
                $errors[] = $this->finding('node_timeout_invalid', "workflow.nodes.{$index}.timeout_seconds", 'Timeout must be 1-86400 seconds or null.');
            }
            foreach (['compensation_handler' => 255, 'compensation_config' => 65_535] as $field => $maxLength) {
                $value = $node[$field] ?? null;
                if ($value !== null && (! is_string($value) || mb_strlen($value) > $maxLength)) {
                    $errors[] = $this->finding("node_{$field}_invalid", "workflow.nodes.{$index}.{$field}", 'Node compensation field is invalid.');
                }
            }
            if (is_string($node['compensation_config'] ?? null)) {
                $redacted = ($this->redactor ?? new WorkflowExecutionRedactionService)->forDisplay([
                    'compensation_config' => $node['compensation_config'],
                ]);
                if ((int) ($redacted['redaction']['redacted_fields'] ?? 0) > 0) {
                    $errors[] = $this->finding(
                        'secret_value_present',
                        "workflow.nodes.{$index}.compensation_config",
                        'Portable definitions cannot contain secret compensation values.'
                    );
                }
            }
            $node['configs'] = $this->validateEntries(
                $node['configs'] ?? null,
                "workflow.nodes.{$index}.configs",
                $warnings,
                $errors
            );
        }
        unset($node);
        $definition['nodes'] = $nodes;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @return array<string,mixed>|null
     */
    private function validateRetry(mixed $retry, array &$errors): ?array
    {
        if ($retry === null) {
            return null;
        }
        if (! is_array($retry)) {
            $errors[] = $this->finding('retry_invalid', 'workflow.retry', 'Retry must be an object or null.');

            return null;
        }
        $attempts = $retry['max_attempts'] ?? null;
        if (! is_int($attempts) || $attempts < 1 || $attempts > 100) {
            $errors[] = $this->finding('retry_attempts_invalid', 'workflow.retry.max_attempts', 'Max attempts must be 1-100.');
        }
        foreach (['notify_on_failure' => 255, 'backoff_strategy' => 50] as $field => $maxLength) {
            $value = $retry[$field] ?? null;
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength) {
                $errors[] = $this->finding("retry_{$field}_invalid", "workflow.retry.{$field}", 'Retry field is invalid.');
            }
        }
        $intervals = $retry['backoff_intervals'] ?? [];
        if (! is_array($intervals) || ! array_is_list($intervals) || count($intervals) > 100) {
            $errors[] = $this->finding('retry_intervals_invalid', 'workflow.retry.backoff_intervals', 'Backoff intervals must be a list of at most 100 items.');
            $intervals = [];
        }
        foreach ($intervals as $index => &$interval) {
            if (! is_array($interval)) {
                $errors[] = $this->finding('retry_interval_invalid', "workflow.retry.backoff_intervals.{$index}", 'Interval must be an object.');

                continue;
            }
            foreach (['attempt_number' => 100, 'backoff_seconds' => 86_400] as $field => $max) {
                $value = $interval[$field] ?? null;
                $min = $field === 'attempt_number' ? 1 : 0;
                if (! is_int($value) || $value < $min || $value > $max) {
                    $errors[] = $this->finding('retry_interval_invalid', "workflow.retry.backoff_intervals.{$index}.{$field}", 'Retry interval value is invalid.');
                }
            }
        }
        unset($interval);

        return [
            'max_attempts' => is_int($attempts) ? $attempts : 1,
            'notify_on_failure' => $retry['notify_on_failure'] ?? '',
            'backoff_strategy' => $retry['backoff_strategy'] ?? '',
            'backoff_intervals' => $intervals,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $warnings
     * @param  list<array<string,mixed>>  $errors
     * @return list<array<string,mixed>>
     */
    private function validateEntries(mixed $entries, string $path, array &$warnings, array &$errors): array
    {
        if (! is_array($entries) || ! array_is_list($entries) || count($entries) > 1000) {
            $errors[] = $this->finding('config_entries_invalid', $path, 'Configuration must be a list of at most 1000 entries.');

            return [];
        }

        $seen = [];
        foreach ($entries as $index => &$entry) {
            if (! is_array($entry)) {
                $errors[] = $this->finding('config_entry_invalid', "{$path}.{$index}", 'Configuration entry must be an object.');

                continue;
            }
            $key = $entry['key'] ?? null;
            if (! is_string($key)
                || trim($key) === ''
                || trim($key) !== $key
                || mb_strlen($key) > 255
                || isset($seen[$key])) {
                $errors[] = $this->finding('config_key_invalid', "{$path}.{$index}.key", 'Configuration key must be unique and contain 1-255 characters.');
            } else {
                $seen[$key] = true;
            }
            $value = $entry['value'] ?? null;
            if ($value !== null && (! is_string($value) || strlen($value) > 1_000_000)) {
                $errors[] = $this->finding('config_value_invalid', "{$path}.{$index}.value", 'Configuration value must be a bounded string or null.');
            }
            $requiresRuntime = ($entry['requires_runtime_value'] ?? false) === true;
            if ($requiresRuntime && $value !== null) {
                $errors[] = $this->finding('runtime_value_not_omitted', "{$path}.{$index}.value", 'Runtime-only value must be omitted from a portable definition.');
            }
            if (! $requiresRuntime && is_string($key) && is_string($value)) {
                $redacted = ($this->redactor ?? new WorkflowExecutionRedactionService)->forDisplay([
                    'key' => $key,
                    'value' => $value,
                ]);
                if ((int) ($redacted['redaction']['redacted_fields'] ?? 0) > 0) {
                    $errors[] = $this->finding(
                        'secret_value_present',
                        "{$path}.{$index}.value",
                        'Portable definitions cannot contain secret configuration values.'
                    );
                }
            }
            if ($requiresRuntime) {
                $warnings[] = $this->finding(
                    'runtime_value_required',
                    "{$path}.{$index}.value",
                    'Restore this runtime value before activating the imported workflow.'
                );
            }
            $entry['requires_runtime_value'] = $requiresRuntime;
        }
        unset($entry);

        return array_values(array_filter($entries, 'is_array'));
    }

    /** @param array<string,mixed>|null $retry */
    private function insertRetry(int $workflowId, ?array $retry): void
    {
        if ($retry === null) {
            return;
        }
        DB::insert(
            'INSERT INTO retry_configs (workflow_id, max_attempts, notify_on_failure, backoff_strategy) VALUES (?, ?, ?, ?)',
            [$workflowId, $retry['max_attempts'], $retry['notify_on_failure'], $retry['backoff_strategy']]
        );
        $retryId = (int) DB::getPdo()->lastInsertId();
        foreach ($retry['backoff_intervals'] as $interval) {
            DB::insert(
                'INSERT INTO retry_backoff_intervals (retry_config_id, attempt_number, backoff_seconds) VALUES (?, ?, ?)',
                [$retryId, $interval['attempt_number'], $interval['backoff_seconds']]
            );
        }
    }

    /** @param list<array<string,mixed>> $entries */
    private function insertEntries(string $table, string $ownerColumn, int $ownerId, array $entries): void
    {
        $allowed = [
            'workflow_defaults' => 'workflow_id',
            'workflow_node_configs' => 'workflow_node_id',
        ];
        if (($allowed[$table] ?? null) !== $ownerColumn) {
            throw new InvalidArgumentException('Unsupported portable workflow configuration table.');
        }
        foreach ($entries as $entry) {
            DB::table($table)->insert([
                $ownerColumn => $ownerId,
                'config_key' => $entry['key'],
                'config_value' => $entry['value'],
            ]);
        }
    }

    /** @param array<string,mixed> $definition */
    private function workflowHash(array $definition): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($definition),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Workflow definition cannot be encoded canonically.', previous: $e);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    /** @return array{code:string,path:string,message:string} */
    private function finding(string $code, string $path, string $message): array
    {
        return compact('code', 'path', 'message');
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return list<array<string,mixed>>
     */
    private function dedupeFindings(array $findings): array
    {
        $unique = [];
        foreach ($findings as $finding) {
            $key = implode('|', [
                (string) ($finding['code'] ?? ''),
                (string) ($finding['path'] ?? ''),
                (string) ($finding['message'] ?? ''),
            ]);
            $unique[$key] = $finding;
        }

        return array_values($unique);
    }
}
