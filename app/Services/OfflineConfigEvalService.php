<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OfflineConfigEvalService
{
    private const PROFILE_ORDER = [
        'default',
        'quality',
        'fast',
        'coding',
        'vision',
        'embedding',
        'creative',
    ];

    private const PROFILE_ALIASES = [
        'general' => 'default',
        'standard' => 'default',
    ];

    public function evaluate(?array $profiles = null): array
    {
        $requestedProfiles = $profiles !== null ? $this->normalizeProfiles($profiles) : null;
        $instances = $this->loadInstances();

        if ($instances === []) {
            throw new InvalidArgumentException('No active Ollama instances available for offline config evaluation.');
        }

        return $this->buildReport(
            $instances,
            $this->loadInventory(),
            $this->loadCurrentProfiles(),
            $requestedProfiles
        );
    }

    public function buildReport(
        array $instances,
        array $inventory,
        array $currentProfiles,
        ?array $profiles = null
    ): array {
        $requestedProfiles = $profiles !== null ? $this->normalizeProfiles($profiles) : null;

        $normalizedInstances = $this->normalizeInstances($instances);
        $normalizedInventory = $this->normalizeInventory($inventory);
        $normalizedProfiles = $this->normalizeCurrentProfiles($currentProfiles);
        $orderedProfiles = $requestedProfiles ?? $this->inferProfiles(
            $normalizedProfiles,
            $normalizedInstances,
            $normalizedInventory
        );

        $profileResults = [];
        $instanceRoleResults = [];
        $modelUpdates = [];

        foreach ($orderedProfiles as $profileName) {
            $currentProfile = $normalizedProfiles[$profileName] ?? null;
            $instanceRole = $this->profileToInstanceRole($profileName);
            $selected = $this->selectProfileCandidate(
                $profileName,
                $instanceRole,
                $currentProfile,
                $normalizedInventory,
                $normalizedInstances
            );
            $currentModel = $currentProfile['model_name'] ?? null;
            $recommendedModel = $selected['model_name'] ?? null;

            $profileResults[] = [
                'profile' => $profileName,
                'instance_role' => $instanceRole,
                'current_model' => $currentModel,
                'recommended_model' => $recommendedModel,
                'recommended_instance' => $selected['instance_id'] ?? null,
                'status' => $selected['status'] ?? null,
                'action' => $this->determineAction($currentModel, $recommendedModel),
                'description' => $currentProfile['description'] ?? ($selected['description'] ?? null),
                'use_cases' => $this->buildUseCases($currentProfile, $selected),
                'expertise' => $this->buildExpertise($currentProfile, $selected),
                'notes' => $this->buildProfileNotes($currentProfile, $selected),
            ];

            if ($selected !== null) {
                $modelUpdates[$this->modelUpdateKey($selected['instance_id'], $selected['model_name'])] =
                    $this->buildModelUpdate($profileName, $selected);
            }

            foreach ($normalizedInstances as $instance) {
                $instanceSelection = $this->selectInstanceRoleCandidate(
                    $profileName,
                    $instanceRole,
                    $currentProfile,
                    $instance,
                    $normalizedInventory
                );

                if ($instanceSelection === null) {
                    continue;
                }

                $currentInstanceModel = $instance['models'][$instanceRole]
                    ?? ($instanceRole === 'standard' ? $instance['default_model'] : null);

                $instanceRoleResults[] = [
                    'instance_id' => $instance['instance_id'],
                    'instance_name' => $instance['instance_name'],
                    'role' => $instanceRole,
                    'profile' => $profileName,
                    'current_model' => $currentInstanceModel,
                    'recommended_model' => $instanceSelection['model_name'],
                    'status' => $instanceSelection['status'] ?? null,
                    'action' => $this->determineAction($currentInstanceModel, $instanceSelection['model_name']),
                ];

                $modelUpdates[$this->modelUpdateKey($instanceSelection['instance_id'], $instanceSelection['model_name'])] =
                    $this->buildModelUpdate($profileName, $instanceSelection);
            }
        }

        $profileActionCounts = $this->countActions($profileResults);
        $instanceActionCounts = $this->countActions($instanceRoleResults);

        return [
            'generated_at' => now()->toIso8601String(),
            'profiles' => $profileResults,
            'instance_models' => $instanceRoleResults,
            'model_updates' => array_values($modelUpdates),
            'summary' => [
                'profiles_evaluated' => count($profileResults),
                'profile_changes' => $profileActionCounts['changes'],
                'missing_profiles' => $profileActionCounts['missing'],
                'instance_role_changes' => $instanceActionCounts['changes'],
                'model_metadata_updates' => count($modelUpdates),
            ],
        ];
    }

    private function normalizeProfiles(?array $profiles): array
    {
        if ($profiles === null || $profiles === []) {
            return [];
        }

        $normalized = [];
        foreach ($profiles as $profile) {
            $candidate = strtolower(trim((string) $profile));
            if ($candidate === '') {
                continue;
            }

            $candidate = self::PROFILE_ALIASES[$candidate] ?? $candidate;

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    private function loadInstances(): array
    {
        // 3a authority: eval must never recommend a role mapping for a blocked
        // or stale instance. Only routable + non-stale rows participate.
        return array_map(fn (object $row): array => [
            'id' => (int) $row->id,
            'instance_id' => (string) $row->instance_id,
            'instance_name' => (string) $row->instance_name,
            'capabilities' => $row->capabilities,
            'config' => $row->config,
            'priority' => (int) $row->priority,
        ], DB::select(
            "SELECT id, instance_id, instance_name, capabilities, config, priority
             FROM llm_instances
             WHERE instance_type = 'ollama'
               AND is_active = 1
               AND routability = 'allowed'
               AND (compat_status IS NULL OR compat_status <> 'stale')
             ORDER BY priority ASC, id ASC"
        ));
    }

    private function loadInventory(): array
    {
        return array_map(fn (object $row): array => [
            'db_instance_id' => (int) $row->db_instance_id,
            'instance_id' => (string) $row->instance_id,
            'model_name' => (string) $row->model_name,
            'profile' => $row->profile,
            'status' => (string) $row->status,
            'capabilities' => $row->capabilities,
            'use_cases' => $row->use_cases,
            'description' => $row->description,
            'quality_rating' => $row->quality_rating !== null ? (int) $row->quality_rating : null,
            'vetting_notes' => $row->vetting_notes,
        ], DB::select(
            "SELECT
                li.id AS db_instance_id,
                li.instance_id,
                om.model_name,
                om.profile,
                om.status,
                om.capabilities,
                om.use_cases,
                om.description,
                om.quality_rating,
                om.vetting_notes
             FROM ollama_models om
             JOIN llm_instances li ON li.id = om.instance_id
             WHERE li.instance_type = 'ollama'
               AND li.is_active = 1
               AND li.routability = 'allowed'
               AND (li.compat_status IS NULL OR li.compat_status <> 'stale')
               AND om.is_available = 1
             ORDER BY li.priority ASC, om.model_name ASC"
        ));
    }

    private function loadCurrentProfiles(): array
    {
        return array_map(fn (object $row): array => [
            'profile_name' => (string) $row->profile_name,
            'model_name' => (string) $row->model_name,
            'description' => $row->description,
            'use_cases' => $row->use_cases,
            'notes' => $row->notes,
            'enabled' => (bool) $row->enabled,
        ], DB::select(
            'SELECT profile_name, model_name, description, use_cases, notes, enabled FROM llm_model_profiles'
        ));
    }

    private function normalizeInstances(array $instances): array
    {
        return array_map(function (array $instance): array {
            $config = $this->decodeJsonArray($instance['config'] ?? []);
            $models = $this->decodeJsonArray($config['models'] ?? []);

            return [
                'id' => (int) ($instance['id'] ?? 0),
                'instance_id' => (string) ($instance['instance_id'] ?? ''),
                'instance_name' => (string) ($instance['instance_name'] ?? ($instance['instance_id'] ?? '')),
                'priority' => (int) ($instance['priority'] ?? 0),
                'capabilities' => $this->decodeJsonArray($instance['capabilities'] ?? []),
                'config' => $config,
                'models' => $models,
                'default_model' => isset($config['default_model']) ? (string) $config['default_model'] : null,
            ];
        }, $instances);
    }

    private function normalizeInventory(array $inventory): array
    {
        return array_map(fn (array $row): array => [
            'db_instance_id' => (int) ($row['db_instance_id'] ?? 0),
            'instance_id' => (string) ($row['instance_id'] ?? ''),
            'model_name' => (string) ($row['model_name'] ?? ''),
            'profile' => $row['profile'] !== null ? $this->canonicalizeProfile((string) $row['profile']) : null,
            'status' => (string) ($row['status'] ?? 'discovered'),
            'capabilities' => $this->decodeJsonArray($row['capabilities'] ?? []),
            'use_cases' => $this->decodeJsonArray($row['use_cases'] ?? []),
            'description' => $row['description'] ?? null,
            'quality_rating' => $row['quality_rating'] ?? null,
            'vetting_notes' => $row['vetting_notes'] ?? null,
        ], $inventory);
    }

    private function normalizeCurrentProfiles(array $profiles): array
    {
        $normalized = [];
        foreach ($profiles as $profile) {
            $profileName = $this->canonicalizeProfile((string) $profile['profile_name']);
            $normalized[$profileName] = [
                'model_name' => (string) $profile['model_name'],
                'description' => $profile['description'] ?? null,
                'use_cases' => $this->decodeJsonArray($profile['use_cases'] ?? []),
                'notes' => $profile['notes'] ?? null,
                'enabled' => (bool) ($profile['enabled'] ?? true),
            ];
        }

        return $normalized;
    }

    private function inferProfiles(array $currentProfiles, array $instances, array $inventory): array
    {
        $profiles = [];

        foreach (array_keys($currentProfiles) as $profileName) {
            $profiles[] = $this->canonicalizeProfile($profileName);
        }

        foreach ($instances as $instance) {
            foreach (array_keys($instance['models']) as $role) {
                $profileName = $this->canonicalizeProfile($role);
                if ($profileName !== 'uncensored') {
                    $profiles[] = $profileName;
                }
            }
        }

        foreach ($inventory as $candidate) {
            if (($candidate['profile'] ?? null) !== null) {
                $profiles[] = $this->canonicalizeProfile((string) $candidate['profile']);
            }
        }

        $profiles = array_values(array_unique(array_filter($profiles)));

        usort($profiles, function (string $left, string $right): int {
            $leftIndex = array_search($left, self::PROFILE_ORDER, true);
            $rightIndex = array_search($right, self::PROFILE_ORDER, true);

            if ($leftIndex !== false && $rightIndex !== false) {
                return $leftIndex <=> $rightIndex;
            }

            if ($leftIndex !== false) {
                return -1;
            }

            if ($rightIndex !== false) {
                return 1;
            }

            return strcmp($left, $right);
        });

        return $profiles;
    }

    private function selectProfileCandidate(
        string $profileName,
        string $instanceRole,
        ?array $currentProfile,
        array $inventory,
        array $instances
    ): ?array {
        $currentModel = $currentProfile['model_name'] ?? null;
        if (is_string($currentModel) && $currentModel !== '') {
            $candidate = $this->selectCandidateForModel($currentModel, $instanceRole, $inventory, $instances);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        $candidate = $this->selectCurrentlyMappedRoleCandidate($instanceRole, $inventory, $instances);
        if ($candidate !== null) {
            return $candidate;
        }

        return $this->selectFallbackCandidate($profileName, $inventory);
    }

    private function selectInstanceRoleCandidate(
        string $profileName,
        string $instanceRole,
        ?array $currentProfile,
        array $instance,
        array $inventory
    ): ?array {
        $instanceInventory = array_values(array_filter(
            $inventory,
            static fn (array $candidate): bool => $candidate['instance_id'] === $instance['instance_id']
        ));

        if ($instanceInventory === []) {
            return null;
        }

        $currentModel = $instance['models'][$instanceRole]
            ?? ($instanceRole === 'standard' ? $instance['default_model'] : null);

        if (is_string($currentModel) && $currentModel !== '') {
            $currentCandidate = $this->findExactModelCandidate($currentModel, $instanceInventory);
            if ($currentCandidate !== null) {
                return $currentCandidate;
            }
        }

        $profileModel = $currentProfile['model_name'] ?? null;
        if (is_string($profileModel) && $profileModel !== '') {
            $profileCandidate = $this->findExactModelCandidate($profileModel, $instanceInventory);
            if ($profileCandidate !== null) {
                return $profileCandidate;
            }
        }

        return $this->selectFallbackCandidate($profileName, $instanceInventory);
    }

    private function selectCandidateForModel(
        string $modelName,
        string $instanceRole,
        array $inventory,
        array $instances
    ): ?array {
        $candidates = array_values(array_filter(
            $inventory,
            static fn (array $candidate): bool => $candidate['model_name'] === $modelName
        ));

        if ($candidates === []) {
            return null;
        }

        $instanceMap = [];
        foreach ($instances as $instance) {
            $instanceMap[$instance['instance_id']] = $instance;
        }

        foreach ($candidates as &$candidate) {
            $instance = $instanceMap[$candidate['instance_id']] ?? null;
            $candidate['selection_score'] = $this->statusScore($candidate['status'] ?? null);

            if ($instance !== null) {
                $mappedModel = $instance['models'][$instanceRole]
                    ?? ($instanceRole === 'standard' ? $instance['default_model'] : null);
                if ($mappedModel === $modelName) {
                    $candidate['selection_score'] += 300;
                }

                $candidate['selection_score'] += max(0, 100 - ((int) $instance['priority']));
            }

            $candidate['selection_score'] += (int) ($candidate['quality_rating'] ?? 0);
        }
        unset($candidate);

        return $this->sortAndPickCandidate($candidates);
    }

    private function selectCurrentlyMappedRoleCandidate(string $instanceRole, array $inventory, array $instances): ?array
    {
        $mappedPairs = [];

        foreach ($instances as $instance) {
            $mappedModel = $instance['models'][$instanceRole]
                ?? ($instanceRole === 'standard' ? $instance['default_model'] : null);

            if (! is_string($mappedModel) || $mappedModel === '') {
                continue;
            }

            $mappedPairs[$instance['instance_id'].'::'.$mappedModel] = true;
        }

        if ($mappedPairs === []) {
            return null;
        }

        $candidates = array_values(array_filter($inventory, static function (array $candidate) use ($mappedPairs): bool {
            return isset($mappedPairs[$candidate['instance_id'].'::'.$candidate['model_name']]);
        }));

        foreach ($candidates as &$candidate) {
            $candidate['selection_score'] = 500 + $this->statusScore($candidate['status'] ?? null) + (int) ($candidate['quality_rating'] ?? 0);
        }
        unset($candidate);

        return $this->sortAndPickCandidate($candidates);
    }

    private function selectFallbackCandidate(string $profileName, array $inventory): ?array
    {
        $candidates = [];
        foreach ($inventory as $candidate) {
            if ($candidate['model_name'] === '') {
                continue;
            }

            $score = $this->statusScore($candidate['status'] ?? null) + (int) ($candidate['quality_rating'] ?? 0);

            if (($candidate['profile'] ?? null) === $profileName) {
                $score += 300;
            }

            $candidate['selection_score'] = $score;
            $candidates[] = $candidate;
        }

        return $this->sortAndPickCandidate($candidates);
    }

    private function findExactModelCandidate(string $modelName, array $inventory): ?array
    {
        foreach ($inventory as $candidate) {
            if ($candidate['model_name'] === $modelName) {
                return $candidate;
            }
        }

        return null;
    }

    private function sortAndPickCandidate(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $left, array $right): int {
            $byScore = ($right['selection_score'] ?? 0) <=> ($left['selection_score'] ?? 0);
            if ($byScore !== 0) {
                return $byScore;
            }

            return strcmp($left['model_name'], $right['model_name']);
        });

        return $candidates[0];
    }

    private function statusScore(?string $status): int
    {
        return match ($status ?? 'discovered') {
            'vetted' => 120,
            'testing' => 60,
            'discovered' => 20,
            default => 0,
        };
    }

    private function determineAction(?string $currentModel, ?string $recommendedModel): string
    {
        if ($recommendedModel === null) {
            return 'missing';
        }

        if ($currentModel === null || trim($currentModel) === '') {
            return 'add';
        }

        return $currentModel === $recommendedModel ? 'keep' : 'update';
    }

    private function countActions(array $rows): array
    {
        $changes = 0;
        $missing = 0;

        foreach ($rows as $row) {
            $action = $row['action'] ?? 'keep';
            if (in_array($action, ['add', 'update'], true)) {
                $changes++;
            }
            if ($action === 'missing') {
                $missing++;
            }
        }

        return [
            'changes' => $changes,
            'missing' => $missing,
        ];
    }

    private function buildUseCases(?array $currentProfile, ?array $selected): array
    {
        $useCases = $currentProfile['use_cases'] ?? [];
        if ($useCases !== []) {
            return $useCases;
        }

        return $selected['use_cases'] ?? [];
    }

    private function buildExpertise(?array $currentProfile, ?array $selected): array
    {
        return $this->buildUseCases($currentProfile, $selected);
    }

    private function buildProfileNotes(?array $currentProfile, ?array $selected): ?string
    {
        return $currentProfile['notes']
            ?? $selected['vetting_notes']
            ?? null;
    }

    private function buildModelUpdate(string $profileName, array $selected): array
    {
        $expertise = $selected['use_cases'] ?? [];
        $notes = $selected['vetting_notes']
            ?? sprintf(
                'Offline local guidance for %s. Expertise: %s.',
                $profileName,
                $expertise === [] ? 'n/a' : implode(', ', $expertise)
            );

        return [
            'instance_id' => $selected['instance_id'],
            'db_instance_id' => $selected['db_instance_id'],
            'model' => $selected['model_name'],
            'profile' => $selected['profile'] ?? $profileName,
            'status' => $selected['status'] ?? 'discovered',
            'capabilities' => $selected['capabilities'] ?? [],
            'use_cases' => $selected['use_cases'] ?? [],
            'description' => $selected['description'] ?? null,
            'expertise' => $expertise,
            'quality_rating' => $selected['quality_rating'] ?? null,
            'vetting_notes' => $notes,
        ];
    }

    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function modelUpdateKey(string $instanceId, string $modelName): string
    {
        return $instanceId.'::'.$modelName;
    }

    private function canonicalizeProfile(string $profileName): string
    {
        $normalized = strtolower(trim($profileName));

        return self::PROFILE_ALIASES[$normalized] ?? $normalized;
    }

    private function profileToInstanceRole(string $profileName): string
    {
        return $profileName === 'default' ? 'standard' : $profileName;
    }
}
