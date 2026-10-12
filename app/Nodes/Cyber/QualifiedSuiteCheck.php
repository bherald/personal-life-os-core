<?php

namespace App\Nodes\Cyber;

use App\Services\CyberSuiteArtifactService;
use App\Services\SystemConfigService;
use App\Services\UnifiedCli\CliStateStore;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Execute approved, finite local security probes. Never selects a model, target or source edit. */
final class QualifiedSuiteCheck
{
    public function __construct(private array $config) {}

    public function execute(array $input): array
    {
        $row = DB::table('cyber_workflow_jobs')->where('handle', $input['task_id'] ?? '')->first();
        if ($row === null || $row->session_id !== ($input['session_id'] ?? null)) {
            throw new RuntimeException('Exact task/session binding required.');
        }
        $binding = (new CyberSuiteArtifactService)->assertJobBinding($row);
        $policy = app(SystemConfigService::class)->get('cyber.workflow_jobs', []);
        $store = new CliStateStore($policy['state_path']);
        $store->assertTurnNotCancelled($row->session_id, $row->handle);
        $case = $this->config['case_id'] ?? null;
        if (! in_array($case, ['billing_scope', 'reference_lookup', 'profile_update'], true)) {
            throw new RuntimeException('Unqualified synthetic case.');
        }
        $action = 'suite-check-'.$case;
        $old = $store->actionReceipts($row->session_id, $row->handle)[$action] ?? null;
        if ($old !== null) {
            if ($old['state'] === 'completed' && $old['document']['result']['success'] === true) {
                return $input;
            }
            throw new RuntimeException('Prior suite action outcome is unknown; automatic replay refused.');
        }
        $store->startAction($row->session_id, $row->handle, ['id' => $action, 'tool' => 'suite_check', 'arguments' => ['case_id' => $case]], ['instance_id' => 'qualified-local-probe', 'model' => 'no-llm']);
        $root = $binding['runtime_root'];
        $source = $root.'/lab/fixtures/suite/'.$case.'/source.php';
        $process = new Process([PHP_BINARY, $root.'/lab/bin/suite-probe.php', $case, $source, hash_file('sha256', $source)], $root,
            ['SECURITY_LAB_RUNTIME' => $root], null, 20);
        try {
            $exit = $process->run();
        } catch (\Throwable) {
            $exit = -1;
        }
        $result = json_decode($process->getOutput(), true);
        if ($exit !== 0 || ! is_array($result) || ($result['schema'] ?? null) !== 'plos.security.suite-observation.v1'
            || ($result['case_id'] ?? null) !== $case || ($result['complete'] ?? null) !== true || ! is_array($result['checks'] ?? null)
            || ($result['source_sha256'] ?? null) !== hash_file('sha256', $source)) {
            throw new RuntimeException('Suite probe has no complete verified result; preserve unknown action.');
        }
        $store->completeAction($row->session_id, $row->handle, $action, ['success' => true, 'output' => json_encode($result, JSON_THROW_ON_ERROR)]);

        return $input + [$case => ['status' => 'checked_findings_need_review', 'failed_checks' => array_keys(array_filter($result['checks'], fn ($passed) => $passed !== true))]];
    }
}
