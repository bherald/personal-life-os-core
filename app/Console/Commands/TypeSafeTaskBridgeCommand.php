<?php

namespace App\Console\Commands;

use App\Services\SystemConfigService;
use App\Services\TypeSafeJevDecisionService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class TypeSafeTaskBridgeCommand extends Command
{
    protected $signature = 'typesafe:task-bridge {--describe : Return the current registered task schema without making a provider request}';

    protected $description = 'Serve the registered TypeSafe task contract to the local MCP bridge';

    public function handle(SystemConfigService $config, TypeSafeJevDecisionService $service): int
    {
        $reason = 'bridge_registry_unavailable';
        try {
            $tool = DB::table('agent_tool_registry')->where('name', 'typesafe_jev_decide')->first();
            if ($tool === null || ! $tool->enabled || $tool->service_class !== TypeSafeJevDecisionService::class || $tool->method !== 'evaluate') {
                return $this->unavailable('bridge_tool_unavailable');
            }
            $reason = 'bridge_policy_unavailable';
            $policy = $config->get(TypeSafeJevDecisionService::POLICY_KEY, []);
            if (is_string($policy)) {
                $policy = json_decode($policy, true, flags: JSON_THROW_ON_ERROR);
            }
            if (! is_array($policy) || ($policy['schema'] ?? null) !== 'plos.typesafe_jev_policy.v1'
                || ! is_array($policy['tasks'] ?? null) || ! is_array($policy['allowed_data_classifications'] ?? null)) {
                return $this->unavailable('bridge_policy_invalid');
            }
            if (! ($policy['enabled'] ?? false)) {
                return $this->unavailable('bridge_feature_disabled');
            }
            if ($this->option('describe')) {
                $reason = 'bridge_registry_schema_invalid';
                $schema = json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($schema) || ! is_array($schema['properties']['task_type'] ?? null)
                    || ! is_array($schema['properties']['data_classification'] ?? null)) {
                    return $this->unavailable($reason);
                }
                $schema['properties']['task_type']['enum'] = array_keys(array_filter($policy['tasks'], fn ($task) => is_array($task) && ($task['enabled'] ?? false) === true));
                $schema['properties']['data_classification']['enum'] = $policy['allowed_data_classifications'] ?? [];
                $this->line(json_encode(['name' => 'typesafe_evaluate', 'description' => $tool->description, 'inputSchema' => $schema], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            $reason = 'bridge_request_invalid';
            if ($tool->requires_confirmation) {
                $this->line(json_encode(['success' => true, 'status' => 'skipped', 'reason_code' => 'use_plos_approval_route', 'fallback' => 'continue_existing_route']));

                return self::SUCCESS;
            }
            $limit = min(131072, max(1024, (int) ($policy['max_request_bytes'] ?? 32768)));
            $input = stream_get_contents(STDIN, $limit + 1);
            if (strlen($input) > $limit) {
                throw new \RuntimeException;
            }
            $args = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($args) || array_diff(array_keys($args), ['task_type', 'data_classification', 'state', 'questions']) !== []
                || ! is_string($args['task_type'] ?? null) || ! is_string($args['data_classification'] ?? null)
                || ! is_array($args['state'] ?? null) || ! is_array($args['questions'] ?? null)) {
                throw new \RuntimeException;
            }
            $reason = 'bridge_service_unavailable';
            $this->line(json_encode($service->evaluate(...$args), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (QueryException) {
            return $this->unavailable('bridge_database_unavailable');
        } catch (Throwable) {
            return $this->unavailable($reason);
        }
    }

    private function unavailable(string $reason): int
    {
        $this->line(json_encode(['success' => false, 'status' => 'skipped', 'reason_code' => $reason,
            'fallback' => 'continue_existing_route'], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
