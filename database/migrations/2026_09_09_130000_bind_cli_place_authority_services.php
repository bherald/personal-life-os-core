<?php

use App\Services\Genealogy\PlaceAuthorityService;
use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const METHODS = ['resolve_place' => 'findOrCreatePlace', 'search_places' => 'searchPlaces'];

    public function up(): void
    {
        $this->change(true);
    }

    public function down(): void
    {
        // Retain the corrected shared contracts and all place/alias data.
        $this->change(false);
    }

    private function change(bool $install): void
    {
        $changed = DB::transaction(function () use ($install): bool {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row === null) {
                return false;
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $before = $policy;
            // Place authority is shared reference data, not a person/tree write.
            $rule = ['target' => 'workflow', 'tree_scoped' => false, 'references' => []];
            foreach (self::METHODS as $name => $method) {
                if (! $install) {
                    if (($policy['tools'][$name] ?? null) === $rule) {
                        unset($policy['tools'][$name]);
                    }

                    continue;
                }
                $tool = DB::table('agent_tool_registry')->where('name', $name)
                    ->where('service_class', PlaceAuthorityService::class)->where('method', $method)->lockForUpdate()->first();
                if ($tool === null || ! $this->align($tool)) {
                    continue;
                }
                if (! array_key_exists($name, $policy['tools'])) {
                    $policy['tools'][$name] = $rule;
                }
            }
            if ($policy === $before) {
                return false;
            }
            if (DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)
                ->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('Place authority bindings changed during migration.');
            }

            return true;
        });
        if ($changed) {
            app(SystemConfigService::class)->forget('cli.framework_services');
        }
    }

    private function align(object $tool): bool
    {
        $changes = [];
        if ($tool->name === 'resolve_place' && $tool->risk_level === 'read') {
            $permissions = json_decode($tool->permissions ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            $changes = ['risk_level' => 'write', 'requires_confirmation' => true,
                'permissions' => json_encode(array_values(array_unique([...$permissions, 'genealogy:write'])), JSON_THROW_ON_ERROR)];
        }
        if ($tool->name === 'search_places') {
            $parameters = json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR);
            $originalParameters = $parameters;
            // Only align the existing flat contract; preserve custom contracts.
            if (isset($parameters['type']) || ! isset($parameters['query'], $parameters['limit'], $parameters['country'])) {
                return false;
            }
            if (! array_key_exists('options', $parameters)) {
                $parameters['options'] = ['type' => 'object', 'x_context_only' => true,
                    'x_parameter_bundle' => ['limit', 'country']];
            }
            if (($parameters['country']['description'] ?? null) === 'Filter by country code (US, DE, IE, etc.)') {
                $parameters['country']['description'] = 'Filter by country name or an alias registered for that country in the place authority.';
            }
            if ($parameters !== $originalParameters) {
                $changes['parameters'] = json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
            if (($tool->returns_description ?? null) === 'Array of place objects: id, name, county, state, country, latitude, longitude.') {
                $changes['returns_description'] = 'Array of authority place records including id, name, parent_id, place_type, latitude, longitude and usage_count. Country membership is resolved through the shared hierarchy.';
            }
        }
        if ($changes !== [] && DB::table('agent_tool_registry')->where('id', $tool->id)
            ->where('service_class', $tool->service_class)->where('method', $tool->method)
            ->where('risk_level', $tool->risk_level)->where('requires_confirmation', $tool->requires_confirmation)
            ->when($tool->parameters === null, fn ($query) => $query->whereNull('parameters'),
                fn ($query) => $query->whereRaw('CAST(parameters AS CHAR) = ?', [$tool->parameters]))
            ->update($changes + ['updated_at' => now()]) !== 1) {
            throw new RuntimeException('Place authority definition changed during migration.');
        }

        return true;
    }
};
