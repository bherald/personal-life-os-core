<?php

namespace App\Services\UnifiedCli;

use InvalidArgumentException;

final class RegistrySnapshotValidator
{
    public const SCHEMA = 'plos.cli.registry.v1';

    public const REQUIRED_TABLES = [
        'llm_instances', 'ollama_models', 'llm_model_profiles', 'system_configs',
        'ollama_eval_runs', 'compute_instances', 'guardrail_rules',
        'agent_tool_registry', 'skill_versions', 'review_type_registry', 'recursion_config',
    ];

    public static function assertDefinitionTable(string $name): void
    {
        if (in_array($name, ['jobs', 'failed_jobs', 'workflow_approval_gates', 'workflow_runs', 'workflow_node_executions', 'agent_handoff_contexts', 'agent_handoff_logs'], true)
            || preg_match('/(?:sessions|messages|confirmations|leases|scheduled_jobs|queue_jobs|request_logs|tool_calls|approval_requests|scheduled_job_runs)$/D', $name)) {
            throw new InvalidArgumentException('Live sessions, approvals, jobs and leases cannot become configuration.');
        }
    }

    public function validate(array $snapshot): void
    {
        if (($snapshot['schema'] ?? null) !== self::SCHEMA || ! is_string($snapshot['source'] ?? null) || $snapshot['source'] === '') {
            throw new InvalidArgumentException('Missing registry schema or source identity.');
        }
        if (! is_string($snapshot['exported_at'] ?? null) || strtotime($snapshot['exported_at']) === false) {
            throw new InvalidArgumentException('Missing registry export timestamp.');
        }
        $tables = $snapshot['tables'] ?? null;
        if (! is_array($tables)) {
            throw new InvalidArgumentException('Registry tables are required.');
        }
        foreach (self::REQUIRED_TABLES as $name) {
            if (! isset($tables[$name]) || ! is_array($tables[$name]) || ! array_is_list($tables[$name])) {
                throw new InvalidArgumentException("Incomplete registry generation: {$name}.");
            }
        }
        $relatedTables = $snapshot['related_tables'] ?? [];
        if (! is_array($relatedTables) || ! array_is_list($relatedTables)) {
            throw new InvalidArgumentException('Related definition tables must be an explicit list.');
        }
        foreach ($relatedTables as $name) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_]*$/D', $name)) {
                throw new InvalidArgumentException('Invalid related definition table name.');
            }
            if (! array_key_exists($name, $tables)) {
                throw new InvalidArgumentException('Missing declared related definition table: '.$name);
            }
        }
        foreach ($tables as $name => $rows) {
            if (! in_array($name, [...self::REQUIRED_TABLES, ...$relatedTables], true)) {
                throw new InvalidArgumentException("Undeclared registry table: {$name}.");
            }
            self::assertDefinitionTable($name);
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new InvalidArgumentException("Invalid records for {$name}.");
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new InvalidArgumentException("Invalid record for {$name}.");
                }
                $this->rejectCredentials($row);
            }
        }
        $ids = [];
        $columnTypes = $snapshot['column_types'] ?? [];
        if (! is_array($columnTypes)) {
            throw new InvalidArgumentException('Registry column types must be a table map.');
        }
        foreach ($columnTypes as $table => $columns) {
            if (! isset($tables[$table]) || ! is_array($columns)) {
                throw new InvalidArgumentException('Column types require a declared definition table.');
            }
            foreach ($columns as $column => $definition) {
                if (! is_string($column) || ! preg_match('/^[a-z][a-z0-9_]*$/D', $column)
                    || ! is_array($definition) || array_diff(array_keys($definition), ['type', 'nullable']) !== []
                    || ! is_string($definition['type'] ?? null) || ! preg_match('/^[a-z][a-z0-9_ ]*$/D', $definition['type'])
                    || ! is_bool($definition['nullable'] ?? null)) {
                    throw new InvalidArgumentException('Invalid source column type or nullability.');
                }
                foreach ($tables[$table] as $row) {
                    if (! array_key_exists($column, $row)) {
                        throw new InvalidArgumentException('Column type has no projected source field.');
                    }
                }
            }
        }
        if (isset($tables[CliMcpServerDefinitions::TABLE])) {
            (new CliMcpServerDefinitions)->validate($tables[CliMcpServerDefinitions::TABLE]);
        }
        $stableIds = [];
        foreach ($tables['llm_instances'] as $row) {
            $id = $row['id'] ?? null;
            $stable = $row['instance_id'] ?? null;
            if ((! is_int($id) && ! (is_string($id) && ctype_digit($id))) || (int) $id < 1 || ! is_string($stable) || $stable === '') {
                throw new InvalidArgumentException('An instance requires a source numeric ID and stable instance_id.');
            }
            if (isset($ids[(string) $id]) || isset($stableIds[$stable])) {
                throw new InvalidArgumentException('Duplicate registry instance identity.');
            }
            $ids[(string) $id] = true;
            $stableIds[$stable] = true;
        }
        $modelKeys = [];
        foreach ($tables['ollama_models'] as $row) {
            if (! array_key_exists('instance_id', $row) || ($row['instance_id'] !== null && ! isset($ids[(string) $row['instance_id']])) || ! is_string($row['model_name'] ?? null) || $row['model_name'] === '') {
                throw new InvalidArgumentException('Model has a missing instance dependency or exact name.');
            }
            $key = $row['instance_id']."\0".$row['model_name'];
            if (isset($modelKeys[$key])) {
                throw new InvalidArgumentException('Duplicate instance/model binding.');
            }
            $modelKeys[$key] = true;
        }
        foreach ($tables['skill_versions'] as $skill) {
            if (isset($skill['full_content'], $skill['content_hash']) && ! hash_equals($skill['content_hash'], hash('sha256', $skill['full_content']))) {
                throw new InvalidArgumentException('Skill content hash does not match its complete content.');
            }
        }
        if (array_key_exists('skill_bundles', $snapshot)) {
            if (! is_array($snapshot['skill_bundles']) || ! array_is_list($snapshot['skill_bundles'])) {
                throw new InvalidArgumentException('Skill bundles must be a complete list.');
            }
            $activeSkills = [];
            foreach ($tables['skill_versions'] as $skill) {
                if (in_array($skill['is_active'] ?? false, [true, 1, '1'], true)) {
                    $activeSkills[$skill['skill_name']] = $skill;
                }
            }
            $seenSkills = [];
            foreach ($snapshot['skill_bundles'] as $bundle) {
                $name = $bundle['skill_name'] ?? '';
                // A session may disable its current table version. Retain that
                // exact saved bundle for inspection/re-enabling; execution still
                // requires an active skill. An active version always takes precedence.
                $versions = is_string($name) ? array_values(array_filter($tables['skill_versions'],
                    fn ($row) => ($row['skill_name'] ?? null) === $name
                        && ($row['version'] ?? null) === ($bundle['version'] ?? null)
                        && ($row['content_hash'] ?? null) === ($bundle['content_hash'] ?? null))) : [];
                $version = is_string($name) ? ($activeSkills[$name] ?? ($versions[0] ?? null)) : null;
                if (! is_string($name) || $version === null || isset($seenSkills[$name])
                    || ($bundle['content_hash'] ?? null) !== $version['content_hash']
                    || ($bundle['version'] ?? null) !== $version['version'] || ! is_array($bundle['files'] ?? null)) {
                    throw new InvalidArgumentException('Skill bundle has no exact current registry version.');
                }
                $seenSkills[$name] = true;
                foreach ($bundle['files'] as $path => $file) {
                    if (! is_string($path) || str_contains($path, '\\') || str_contains($path, ':') || str_contains($path, "\0")
                        || array_intersect(explode('/', $path), ['', '.', '..']) !== [] || ! is_array($file)
                        || ! is_string($file['base64'] ?? null)) {
                        throw new InvalidArgumentException('Invalid bundled skill file path or data.');
                    }
                    $bytes = base64_decode($file['base64'], true);
                    if ($bytes === false || ($file['bytes'] ?? null) !== strlen($bytes)
                        || ($file['sha256'] ?? null) !== hash('sha256', $bytes)) {
                        throw new InvalidArgumentException('Skill asset bytes or hash mismatch.');
                    }
                }
                if (! isset($bundle['files']['SKILL.md']['sha256'])
                    || $bundle['files']['SKILL.md']['sha256'] !== ($bundle['source_content_hash'] ?? null)
                    || ($bundle['version_matches'] ?? null) !== ($bundle['source_content_hash'] === $bundle['content_hash'])) {
                    throw new InvalidArgumentException('Complete skill instructions are missing from the bundle.');
                }
            }
            if (array_diff_key($activeSkills, $seenSkills) !== []) {
                throw new InvalidArgumentException('Current skill bundle set is incomplete.');
            }
        }
        $configKeys = [];
        foreach ($tables['system_configs'] as $config) {
            if (! is_string($config['section'] ?? null) || ! is_string($config['config_key'] ?? null) || ! is_string($config['data_type'] ?? null) || ! array_key_exists('config_value', $config)) {
                throw new InvalidArgumentException('Incomplete typed system configuration record.');
            }
            $key = $config['section'].'.'.$config['config_key'];
            if (isset($configKeys[$key])) {
                throw new InvalidArgumentException('Duplicate system configuration key.');
            }
            if (preg_match('/(?:^|[._])(?:api_key|password|secret|access_token|refresh_token|authorization|private_key)(?:$|[._])/i', $key)
                && ! preg_match('/(?:_env|_ref|_reference)$/D', $key)) {
                throw new InvalidArgumentException('Raw credential configuration cannot be synchronized.');
            }
            $configKeys[$key] = true;
            CliSettingValue::decode($config['config_value'], $config['data_type']);
        }
        $relationships = $snapshot['relationships'] ?? [];
        if (! is_array($relationships) || ! array_is_list($relationships)) {
            throw new InvalidArgumentException('Registry relationships must be an explicit list.');
        }
        foreach ($relationships as $relationship) {
            if (! is_array($relationship)) {
                throw new InvalidArgumentException('Invalid registry relationship.');
            }
            $child = $relationship['table'] ?? '';
            $parent = $relationship['references_table'] ?? '';
            $column = $relationship['column'] ?? '';
            $parentColumn = $relationship['references_column'] ?? '';
            if (! isset($tables[$child], $tables[$parent]) || ! is_string($column) || $column === '' || ! is_string($parentColumn) || $parentColumn === '') {
                throw new InvalidArgumentException('Incomplete declared registry relationship.');
            }
            $parentIds = [];
            foreach ($tables[$parent] as $row) {
                if (! isset($row[$parentColumn]) || ! is_scalar($row[$parentColumn])) {
                    throw new InvalidArgumentException('Missing parent key in declared registry relationship.');
                }
                $parentIds[(string) $row[$parentColumn]] = true;
            }
            foreach ($tables[$child] as $row) {
                if (! array_key_exists($column, $row)) {
                    throw new InvalidArgumentException('Missing child key in declared registry relationship.');
                }
                if ($row[$column] === null && ($relationship['nullable'] ?? false) === true) {
                    continue;
                }
                if (! is_scalar($row[$column]) || ! isset($parentIds[(string) $row[$column]])) {
                    throw new InvalidArgumentException('Missing related definition dependency for '.$child.'.'.$column);
                }
            }
        }
        json_encode($snapshot, JSON_THROW_ON_ERROR);
    }

    private function rejectCredentials(array $record): void
    {
        foreach ($record as $key => $value) {
            if (in_array(strtolower((string) $key), ['api_key', 'password', 'secret', 'access_token', 'refresh_token', 'authorization', 'cookie', 'private_key'], true) && $value !== null && $value !== '') {
                throw new InvalidArgumentException('Registry snapshots accept credential references, never raw credentials.');
            }
            if (is_array($value)) {
                $this->rejectCredentials($value);
            } elseif (is_string($value) && (str_starts_with(ltrim($value), '{') || str_starts_with(ltrim($value), '['))) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $this->rejectCredentials($decoded);
                }
            }
        }
    }
}
