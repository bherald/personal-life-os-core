<?php

namespace App\Services\UnifiedCli;

use App\Services\AgentToolRegistryService;
use App\Services\MCPToolResultValidatorService;
use App\Services\OfflinePolicyService;
use App\Services\SystemConfigService;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** One authenticated local/SSH invocation of the target PLOS registry dispatcher. */
final class CliFrameworkServiceWorker
{
    public const CALLER_CONTEXT = 'plos.cli.framework_caller';

    public function __construct(
        private readonly ?Closure $configuration = null,
        private readonly ?Closure $contractResolver = null,
        private readonly ?Closure $registryFactory = null,
        private readonly ?Closure $referenceMatches = null,
    ) {}

    public function execute(array $request): array
    {
        if (array_diff(array_keys($request), ['schema', 'tool', 'arguments', 'skill', 'definition_sha256',
            'root', 'production', 'target', 'tree_id', 'approved', 'rule_sha256', 'parameter_override', 'network', 'model_role', 'model_selection', 'vision_model_selection', 'embedding_model_selection', 'compute_selection', 'internet_access', 'offline_mode', 'caller_binding', 'operator_override', 'remote_operation', 'worker_journal_sha256', 'control_existing', 'supervised_process']) !== []
            || ($request['schema'] ?? null) !== 'plos.cli.framework-request.v1'
            || (array_key_exists('supervised_process', $request) && $request['supervised_process'] !== true)
            || ! is_string($request['tool'] ?? null) || ! is_string($request['skill'] ?? null)
            || ! is_array($request['arguments'] ?? null) || ! is_bool($request['approved'] ?? null)
            || (array_key_exists('operator_override', $request) && $request['operator_override'] !== true)
            || ! is_string($request['target'] ?? null)
            || ($request['root'] ?? null) !== realpath(base_path())
            || ! (($request['target'] === 'local' && ($request['production'] ?? null) === null)
                || ($request['production'] ?? null) === app()->isProduction())) {
            throw new RuntimeException('Framework request does not match this PLOS installation.');
        }
        $caller = $request['caller_binding'] ?? null;
        if (($request['operator_override'] ?? false) === true && ($request['approved'] !== true || ! is_array($caller))) {
            throw new RuntimeException('Framework override requires an approved operator caller binding.');
        }
        if (array_key_exists('caller_binding', $request)) {
            if (! is_array($caller) || count($caller) !== 5
                || array_diff(array_keys($caller), ['session_id', 'turn_id', 'action_id', 'arguments_sha256', 'approval_scope_sha256']) !== []) {
                throw new RuntimeException('Invalid trusted framework caller binding.');
            }
            foreach (['session_id', 'turn_id', 'action_id'] as $field) {
                if (! is_string($caller[$field] ?? null) || $caller[$field] === '' || strlen($caller[$field]) > 255
                    || preg_match('/[\\x00-\\x20\\x7f]/', $caller[$field])) {
                    throw new RuntimeException('Invalid trusted framework caller identity.');
                }
            }
            foreach (['arguments_sha256', 'approval_scope_sha256'] as $field) {
                if (! is_string($caller[$field] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $caller[$field])) {
                    throw new RuntimeException('Invalid trusted framework caller fingerprint.');
                }
            }
            if (! hash_equals($caller['arguments_sha256'], hash('sha256', json_encode($request['arguments'], JSON_THROW_ON_ERROR)))) {
                throw new RuntimeException('Trusted caller binding differs from service arguments.');
            }
        }
        $policy = $this->configuration === null ? app(SystemConfigService::class)->get('cli.framework_services', [])
            : ($this->configuration)();
        if (array_key_exists('control_existing', $request)) {
            // The authenticated worker only looks up an already claimed request.
            // Revoked execution rules/approvals must not disable its stop channel.
            if ($request['control_existing'] !== true || ! in_array($request['remote_operation'] ?? null, ['status', 'cancel'], true)
                || ! is_array($caller)) {
                throw new RuntimeException('Existing-action control cannot execute a framework tool.');
            }
            CliFrameworkServicePolicy::validate($policy);
            $journal = $policy['worker_receipts'] ?? throw new RuntimeException('Original worker journal is unavailable.');
            if (($request['worker_journal_sha256'] ?? null) !== hash('sha256', json_encode($journal, JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Original worker journal binding changed.');
            }
            CliLocalBindings::assertPrivateSqlite($journal['path']);

            return (new CliFrameworkRemoteAction(new CliStateStore($journal['path']), $journal))->dispatch($request,
                static fn () => throw new RuntimeException('Existing-action control cannot dispatch.'));
        }
        $executionScope = CliWorkflowExecutionScope::fromFrameworkRequest($request);
        CliFrameworkServicePolicy::validate($policy);
        $rule = $policy['tools'][$request['tool']] ?? throw new RuntimeException('The target has no execution rule for this service tool.');
        if (hash('sha256', json_encode($rule, JSON_THROW_ON_ERROR)) !== ($request['rule_sha256'] ?? null)) {
            throw new RuntimeException('The target execution rule changed. Sync and review it before retrying.');
        }
        if (($request['supervised_process'] ?? false) !== ($rule['supervised_process'] ?? false)) {
            throw new RuntimeException('Supervised process selection differs from the reviewed execution rule.');
        }
        if ($rule['target'] === 'local' && $request['target'] !== 'local') {
            throw new RuntimeException('This service tool must execute on the selected local host.');
        }
        $resolve = function () use ($request, $rule): array {
            $override = ($request['operator_override'] ?? false) === true;
            $contract = $this->contractResolver === null
                ? ($override
                    ? ['skill_found' => true, 'tools' => [$request['tool'] => (new AgentToolRegistryService)->resolveEnabledRegisteredTool($request['tool'])]]
                    : (new AgentToolRegistryService)->resolveToolContractForAgent($request['skill']))
                : ($this->contractResolver)($request['skill'], $override);
            $definition = $contract['tools'][$request['tool']] ?? null;
            if (($contract['skill_found'] ?? false) !== true || ! is_array($definition)) {
                throw new RuntimeException('The target registry does not permit the selected service tool.');
            }
            $definition = (new CliLegacyToolMapping)->adapt($request['tool'], $definition);
            if (array_key_exists('parameter_override', $request)) {
                $override = $request['parameter_override'];
                if (! is_array($override) || array_diff(array_keys($override), ['base_sha256', 'parameters']) !== []
                    || ! is_array($override['parameters'] ?? null)
                    || ($override['base_sha256'] ?? null) !== CliFrameworkServicePolicy::fingerprint($definition)) {
                    throw new RuntimeException('The parameter override baseline differs from the current target tool. Sync and review before retrying.');
                }
                // Authenticated local/SSH operator settings affect this call
                // only. The target service, skill, rules and guards remain live.
                $definition['parameters'] = $override['parameters'];
            }
            if (! empty($definition['mcp_server']) || ! empty($definition['mcp_tool'])
                || in_array($definition['availability_status'] ?? '', ['disabled', 'unavailable'], true)
                || CliFrameworkServicePolicy::fingerprint($definition) !== ($request['definition_sha256'] ?? null)) {
                throw new RuntimeException('The target tool definition differs from the reviewed CLI definition. Sync and review the changed tool before retrying.');
            }

            // The current target rule owns result interpretation. Model
            // arguments cannot turn a missing result into a successful tool call.
            if (isset($rule['null_result_error'])) {
                $definition['null_result_error'] = $rule['null_result_error'];
            }

            return [$request['tool'] => $definition];
        };
        $definition = $resolve()[$request['tool']];
        $schema = (new CliToolCatalog(new CliConfiguration(new CliStateStore(':memory:'))))->schema($definition['parameters']);
        $arguments = $request['arguments'];
        $object = json_decode(json_encode((object) $arguments, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        if (! (new MCPToolResultValidatorService)->validate(['structuredContent' => $object], $schema, $object)['valid']) {
            throw new RuntimeException('Service arguments do not match the current registered schema.');
        }
        foreach ($arguments as $name => $value) {
            $canonical = Str::snake($name);
            $authority = ['confirmed', 'operator_confirmed', 'ds_confirmed',
                'approval_ref', 'simulate', 'sidecar', 'ds_profile', 'session_id', 'agent_id', 'risk_level', 'mcp_server', 'mcp_tool'];
            $alias = $schema['properties'][$name]['x_context_alias'] ?? $canonical;
            if (str_starts_with($name, '_') || in_array($canonical, $authority, true) || in_array($alias, $authority, true)) {
                throw new RuntimeException('Service arguments cannot supply execution authority.');
            }
        }
        $context = ['agent_id' => $request['skill'], 'operation_target' => $request['target'],
            'session_id' => 'cli-framework-'.bin2hex(random_bytes(16)), '_audit' => false];
        if ($rule['tree_scoped']) {
            $tree = $request['tree_id'] ?? null;
            if (! is_int($tree) || $tree < 1) {
                throw new RuntimeException('Select a genealogy tree before executing this service.');
            }
            $context['tree_id'] = $tree;
            if (isset($rule['tree_argument'])) {
                $argument = $rule['tree_argument'];
                $parameters = $definition['parameters']['properties'] ?? $definition['parameters'];
                if (array_is_list($parameters)) {
                    $parameters = array_column($parameters, null, 'name');
                }
                if (! isset($parameters[$argument])) {
                    throw new RuntimeException('The registered service does not bind the selected tree argument.');
                }
                if (isset($arguments[$argument]) && $arguments[$argument] !== $tree) {
                    throw new RuntimeException('Service arguments cannot change the selected tree.');
                }
                $context[$argument] = $tree;
            }
            $resolvedArguments = (new AgentToolRegistryService(losslessResults: true))
                ->resolveNamedParameters($definition, $arguments, array_replace($arguments, $context));
            $references = [];
            foreach ($rule['references'] as $argument => $reference) {
                [$present, $value] = $this->referenceArgument($resolvedArguments, $argument);
                if ($present && $value !== null && $value !== []) {
                    $references[$argument] = $value;
                }
            }
            if (! isset($rule['tree_argument']) && $references === []) {
                throw new RuntimeException('This service requires a record reference within the selected tree.');
            }
            foreach ($rule['references'] as $argument => $reference) {
                if (! array_key_exists($argument, $references)) {
                    continue;
                }
                $ids = is_array($references[$argument]) ? $references[$argument] : [$references[$argument]];
                foreach ($ids as $id) {
                    if (! is_int($id) || $id < 1) {
                        throw new RuntimeException('Tree references must be positive record identifiers.');
                    }
                    $matches = $this->referenceMatches === null
                        ? DB::table($reference['table'])->where($reference['id_column'], $id)->where($reference['tree_column'], $tree)->exists()
                        : ($this->referenceMatches)($reference, $id, $tree);
                    if ($matches !== true) {
                        throw new RuntimeException('A service record is absent from the selected tree.');
                    }
                }
            }
        }
        if (! empty($rule['file_inputs'])) {
            $resolvedArguments = (new AgentToolRegistryService(losslessResults: true))
                ->resolveNamedParameters($definition, $arguments, array_replace($arguments, $context));
            foreach ($rule['file_inputs'] as $argument) {
                [$present, $path] = $this->referenceArgument($resolvedArguments, $argument);
                if (! $present || ! is_string($path) || ! str_starts_with($path, '/')
                    || str_contains($path, "\0") || ! is_file($path) || ! is_readable($path)) {
                    throw new RuntimeException('The selected file input must be an existing readable absolute file on this target.');
                }
                $decision = app(OfflinePolicyService::class)->evaluatePath($path, 'read');
                if (! $decision->allowed || ($decision->requiresConfirmation && ! $request['approved'])) {
                    throw new RuntimeException('The target PLOS path policy does not authorize this file input: '.$decision->reason);
                }
            }
        }
        $confirmation = fn () => $request['approved'];
        $registry = $this->registryFactory === null
            ? new AgentToolRegistryService(toolProvider: $resolve, losslessResults: true, confirmationVerifier: $confirmation)
            : ($this->registryFactory)($resolve, $confirmation);
        if (! $registry instanceof AgentToolRegistryService) {
            throw new RuntimeException('The target PLOS registry dispatcher is unavailable.');
        }

        $execute = function () use ($executionScope, $registry, $request, $arguments, $context, $caller): array {
            $computeExecutions = [];
            $retainCompute = function (array $execution) use (&$computeExecutions): void {
                $computeExecutions[] = $execution;
            };
            $codexExecutions = [];
            $retainCodex = function (array $execution) use (&$codexExecutions): void {
                $codexExecutions[] = $execution;
            };
            try {
                $result = Context::scope(fn () => (new CliWorkflowExecutionScope)->run($executionScope,
                    fn () => $registry->executeTool($request['tool'], $arguments, array_replace($arguments, $context)),
                    $retainCompute, $retainCodex), hidden: [self::CALLER_CONTEXT => $caller]);
            } catch (CliToolOutcomeUnknown $error) {
                if ($computeExecutions === [] && $codexExecutions === []) {
                    throw $error;
                }
                // Transfer retained streams before the caller restores the durable
                // unknown-outcome state. This does not authorize an automatic retry.
                $result = ['success' => false, 'outcome_unknown' => true, 'error' => $error->getMessage()];
            }

            return $result + ($computeExecutions === [] ? [] : ['compute_executions' => $computeExecutions])
                + ($codexExecutions === [] ? [] : ['codex_executions' => $codexExecutions]);
        };
        if (($rule['durable_receipts'] ?? false) === true) {
            $journal = $policy['worker_receipts'] ?? throw new RuntimeException('This worker has no configured durable receipt store.');
            if (($request['worker_journal_sha256'] ?? null) !== hash('sha256', json_encode($journal, JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Worker journal differs from the reviewed execution binding.');
            }
            CliLocalBindings::assertPrivateSqlite($journal['path']);

            return (new CliFrameworkRemoteAction(new CliStateStore($journal['path']), $journal))->dispatch($request, $execute);
        }
        if (array_key_exists('remote_operation', $request) || array_key_exists('worker_journal_sha256', $request)) {
            throw new RuntimeException('Remote control requires an explicitly enabled durable rule.');
        }

        return $execute();
    }

    /** Resolve table-declared nested record references without flattening input. */
    private function referenceArgument(array $arguments, string $path): array
    {
        $value = $arguments;
        foreach (explode('.', $path) as $part) {
            if (is_object($value)) {
                $value = get_object_vars($value);
            }
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return [false, null];
            }
            $value = $value[$part];
        }

        return [true, $value];
    }
}
