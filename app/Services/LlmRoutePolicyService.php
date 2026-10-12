<?php

namespace App\Services;

use App\Support\RuntimeBoolean;

/** Shared table policy used by the PLOS pool and its local CLI adapter. */
final class LlmRoutePolicyService
{
    public function requiresCachedHealth(array $config): bool
    {
        return RuntimeBoolean::isTrue($config['route_policy']['require_cached_health'] ?? true);
    }

    public function allowsInstance(array $config, string $instanceId, bool $explicit, ?string $preferred): bool
    {
        return ! RuntimeBoolean::isTrue($config['route_policy']['require_explicit_instance'] ?? false)
            || ($explicit && $preferred === $instanceId);
    }

    public function allowedRoles(array $config): ?array
    {
        $roles = $config['route_policy']['allowed_model_roles'] ?? $config['allowed_model_roles'] ?? null;

        return is_array($roles) && $roles !== [] ? array_values(array_filter($roles, 'is_string')) : null;
    }

    public function allowsRole(array $config, string $role): bool
    {
        $allowed = $this->allowedRoles($config);
        if ($allowed !== null && ! in_array($role, $allowed, true)) {
            return false;
        }
        $model = $config['models'][$role] ?? null;

        return $this->allowsUnscoped($config) || (is_string($model) && trim($model) !== '');
    }

    public function allowsUnscoped(array $config): bool
    {
        $policy = is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [];

        return ! RuntimeBoolean::isTrue($policy['require_model_role'] ?? false)
            && RuntimeBoolean::isTrue($policy['allow_generic_fallback'] ?? true);
    }

    public function matchesCapabilities(array $capabilities, array $groups): bool
    {
        foreach ($groups as $group) {
            $alternatives = is_array($group) ? array_values(array_filter($group, 'is_string')) : [];
            if ($alternatives === [] || empty(array_intersect($alternatives, $capabilities))) {
                return false;
            }
        }

        return true;
    }

    public function nativeToolsConfigured(array $config, array $modelCapabilities, ?string $role = null): bool
    {
        $rolePolicy = $role === null ? [] : ($config['role_runtime'][$role] ?? []);
        if (array_key_exists('native_tools_enabled', $rolePolicy)) {
            return $rolePolicy['native_tools_enabled'] === true
                && $this->matchesCapabilities($modelCapabilities, [['tool_calling', 'tool_use', 'tools']]);
        }
        $policy = is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [];
        if (array_key_exists('tools_enabled', $policy)) {
            return RuntimeBoolean::isTrue($policy['tools_enabled']);
        }

        // Same exact model capability group used by AIService::processWithTools.
        // Candidate labels or a coding model name do not prove tool support.
        return $this->matchesCapabilities($modelCapabilities, [['tool_calling', 'tool_use', 'tools']]);
    }
}
