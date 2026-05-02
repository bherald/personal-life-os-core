<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OfflineConfigWritebackService
{
    public function apply(array $report): array
    {
        $profilesWritten = 0;
        $instanceRolesWritten = 0;
        $modelMetadataWritten = 0;

        DB::transaction(function () use (
            $report,
            &$profilesWritten,
            &$instanceRolesWritten,
            &$modelMetadataWritten
        ): void {
            foreach (($report['profiles'] ?? []) as $profile) {
                if (! isset($profile['recommended_model']) || $profile['recommended_model'] === null) {
                    continue;
                }

                $profilesWritten += $this->upsertProfile($profile);
            }

            foreach ($this->groupInstanceRoleUpdates($report['instance_models'] ?? []) as $instanceId => $updates) {
                $instanceRolesWritten += $this->updateInstanceRoles($instanceId, $updates);
            }

            foreach (($report['model_updates'] ?? []) as $modelUpdate) {
                $modelMetadataWritten += $this->updateModelMetadata($modelUpdate);
            }
        });

        $this->clearCaches();

        return [
            'summary' => [
                'profiles_written' => $profilesWritten,
                'instance_roles_written' => $instanceRolesWritten,
                'model_metadata_written' => $modelMetadataWritten,
            ],
        ];
    }

    private function upsertProfile(array $profile): int
    {
        $existing = DB::selectOne(
            'SELECT id FROM llm_model_profiles WHERE profile_name = ? LIMIT 1',
            [$profile['profile']]
        );

        $payload = [
            $profile['recommended_model'],
            $profile['description'] ?? null,
            json_encode($profile['use_cases'] ?? [], JSON_UNESCAPED_SLASHES),
            $profile['notes'] ?? null,
        ];

        if ($existing) {
            DB::update(
                'UPDATE llm_model_profiles
                 SET model_name = ?, description = ?, use_cases = ?, notes = ?, enabled = 1, updated_at = NOW()
                 WHERE id = ?',
                [...$payload, $existing->id]
            );

            return 1;
        }

        DB::insert(
            'INSERT INTO llm_model_profiles
             (profile_name, model_name, description, use_cases, enabled, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())',
            [$profile['profile'], ...$payload]
        );

        return 1;
    }

    private function groupInstanceRoleUpdates(array $instanceModels): array
    {
        $grouped = [];
        foreach ($instanceModels as $instanceModel) {
            if (($instanceModel['recommended_model'] ?? null) === null) {
                continue;
            }

            $grouped[$instanceModel['instance_id']][] = $instanceModel;
        }

        return $grouped;
    }

    private function updateInstanceRoles(string $instanceId, array $updates): int
    {
        $row = DB::selectOne(
            'SELECT id, config, routability, compat_status
             FROM llm_instances
             WHERE instance_id = ? LIMIT 1',
            [$instanceId]
        );

        if (! $row) {
            return 0;
        }

        // 3a authority: never let writeback promote a role on a blocked or stale
        // instance. The eval pass already filters on authority but belt-and-suspenders
        // here guards against hand-crafted reports.
        if (($row->routability ?? null) !== 'allowed'
            || ($row->compat_status ?? null) === 'stale'
        ) {
            Log::warning('OfflineConfigWriteback: refused role writeback on non-authoritative instance', [
                'instance_id' => $instanceId,
                'routability' => $row->routability ?? null,
                'compat_status' => $row->compat_status ?? null,
            ]);

            return 0;
        }

        $config = json_decode($row->config ?? '{}', true);
        if (! is_array($config)) {
            $config = [];
        }

        if (! isset($config['models']) || ! is_array($config['models'])) {
            $config['models'] = [];
        }

        $writes = 0;
        foreach ($updates as $update) {
            $role = (string) $update['role'];
            $config['models'][$role] = $update['recommended_model'];
            if ($role === 'standard') {
                $config['default_model'] = $update['recommended_model'];
            }
            $writes++;
        }

        DB::update(
            'UPDATE llm_instances SET config = ?, updated_at = NOW() WHERE id = ?',
            [json_encode($config, JSON_UNESCAPED_SLASHES), $row->id]
        );

        return $writes;
    }

    private function updateModelMetadata(array $modelUpdate): int
    {
        // 3a authority: do not upgrade a model's vetting status if its backing
        // instance is blocked or stale. We still allow metadata updates for
        // unchanged status transitions (e.g. descriptions) by passing the row
        // authority check first.
        $authority = DB::selectOne(
            'SELECT routability, compat_status
             FROM llm_instances
             WHERE id = ? LIMIT 1',
            [$modelUpdate['db_instance_id']]
        );

        if (
            $authority === null
            || ($authority->routability ?? null) !== 'allowed'
            || ($authority->compat_status ?? null) === 'stale'
        ) {
            Log::warning('OfflineConfigWriteback: refused model metadata writeback (non-authoritative host)', [
                'db_instance_id' => $modelUpdate['db_instance_id'] ?? null,
                'model' => $modelUpdate['model'] ?? null,
                'routability' => $authority->routability ?? null,
                'compat_status' => $authority->compat_status ?? null,
            ]);

            return 0;
        }

        DB::update(
            "UPDATE ollama_models
             SET profile = ?,
                 status = ?,
                 capabilities = ?,
                 use_cases = ?,
                 description = ?,
                 quality_rating = ?,
                 vetting_notes = ?,
                 vetted_at = CASE WHEN ? = 'vetted' THEN COALESCE(vetted_at, NOW()) ELSE vetted_at END,
                 vetted_by = CASE WHEN ? = 'vetted' THEN COALESCE(vetted_by, 'offline:eval-config') ELSE vetted_by END,
                 updated_at = NOW()
             WHERE instance_id = ? AND model_name = ?",
            [
                $modelUpdate['profile'] ?? null,
                $modelUpdate['status'] ?? 'discovered',
                json_encode($modelUpdate['capabilities'] ?? [], JSON_UNESCAPED_SLASHES),
                json_encode($modelUpdate['use_cases'] ?? [], JSON_UNESCAPED_SLASHES),
                $modelUpdate['description'] ?? null,
                $modelUpdate['quality_rating'] ?? null,
                $modelUpdate['vetting_notes'] ?? null,
                $modelUpdate['status'] ?? 'discovered',
                $modelUpdate['status'] ?? 'discovered',
                $modelUpdate['db_instance_id'],
                $modelUpdate['model'],
            ]
        );

        return 1;
    }

    private function clearCaches(): void
    {
        Cache::forget('llm_model_profiles');
        Cache::forget('ollama_available_models');
        Cache::forget('ollama_vetted_models');
        Cache::forget('llm_instances_all');
        Cache::forget('llm_instances_healthy');
    }
}
