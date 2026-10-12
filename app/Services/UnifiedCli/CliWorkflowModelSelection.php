<?php

namespace App\Services\UnifiedCli;

use App\Services\LlmRoutePolicyService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Resolve an operator's exact supporting model against the target's existing tables. */
final class CliWorkflowModelSelection
{
    public static function validate(mixed $selection): void
    {
        if ($selection === 'auto') {
            return;
        }
        $parts = is_string($selection) ? explode('::', $selection, 2) : [];
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === ''
            || trim($selection) !== $selection || preg_match('/[\x00-\x1f\x7f]/', $selection)) {
            throw new RuntimeException('Choose auto or an exact instance::model entry from /models.');
        }
    }

    public function resolve(string $selection, string $selectedRole, array $request): array
    {
        self::validate($selection);
        if ($selection === 'auto') {
            return [];
        }
        [$id, $model] = explode('::', $selection, 2);
        $instance = DB::table('llm_instances')->where('instance_id', $id)->first();
        if ($instance === null || ! $instance->is_active || ! $instance->is_healthy || $instance->routability !== 'allowed') {
            throw new RuntimeException('The selected supporting provider is unavailable in the target tables. No alternate provider was selected.');
        }
        $supported = json_decode($instance->supported_models ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($supported) || ! in_array($model, $supported, true)) {
            throw new RuntimeException('The selected supporting model is not advertised by that target provider. Sync or choose another model.');
        }
        $config = json_decode($instance->config ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $policy = new LlmRoutePolicyService;
        $roles = array_keys(array_filter($config['models'] ?? [], fn ($name, $role) => $name === $model
            && $policy->allowsRole($config, $role), ARRAY_FILTER_USE_BOTH));
        $callerRole = $request['model_role'] ?? 'standard';
        $role = $selectedRole !== 'auto' ? $selectedRole
            : (in_array($callerRole, $roles, true) ? $callerRole : ($roles[0] ?? $callerRole));
        if (! $policy->allowsInstance($config, $id, true, $id) || ! $policy->allowsRole($config, $role)
            || (! $policy->allowsUnscoped($config) && ($config['models'][$role] ?? null) !== $model)) {
            throw new RuntimeException('The selected supporting model/role is not permitted by the target routing rules.');
        }
        $options = ['model' => $model, 'model_override' => $model, 'model_role' => $role,
            'auto_select_model' => false, 'disable_cascade' => true, 'use_cache' => false, 'dedup' => false,
            'prefer_codex' => false, 'ai_mode' => null];
        if ($instance->instance_type === 'ollama') {
            return $options + ['required_instance_id' => '', 'ollama_required_no_fallback' => true,
                'allowed_ollama_instance_ids' => [$id], 'preferred_ollama_instance_ids' => [$id]];
        }

        return $options + ['required_instance_id' => $id, 'ollama_required_no_fallback' => false];
    }
}
