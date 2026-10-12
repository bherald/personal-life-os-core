<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Server-owned qualified artifact; paths and hashes never come from model arguments. */
final class CyberSuiteArtifactService
{
    private const SUITE_COVERAGE = [
        'original-three-v1' => ['billing_scope' => 5, 'reference_lookup' => 5, 'profile_update' => 6],
        'laravel-four-v1' => ['billing_scope' => 5, 'reference_lookup' => 5, 'profile_update' => 6, 'note_render' => 9],
    ];

    public function current(): array
    {
        $policy = app(SystemConfigService::class)->get('cyber.workflow_jobs', []);
        if (! is_array($policy) || ($policy['enabled'] ?? false) !== true || ! is_array($policy['artifact'] ?? null)) {
            throw new RuntimeException('Qualified CYBER suite artifact is unavailable.');
        }

        $binding = $policy['artifact'];
        if ($this->workflowName() === 'cyber_managed_model_suite') {
            $binding['managed_model'] = $policy['managed_model'] ?? null;
        }

        if ($this->workflowName() === 'cyber_qualified_native_tools') {
            $binding['native_tool'] = $policy['native_tool'] ?? null;
        }

        // Only the server-owned policy selects a version. Seal the contract before
        // CyberWorkflowJobService captures this exact binding in its existing job row.
        if (array_key_exists('suite_version', $binding)) {
            $binding['suite_contract_sha256'] = $this->suiteContract($binding)['sha256'];
        }

        return $this->verify($binding);
    }

    public function verify(array $binding): array
    {
        if (array_diff(array_keys($binding), ['archive_path', 'archive_sha256', 'runtime_root', 'manifest_sha256', 'managed_model', 'suite_version', 'suite_contract_sha256', 'native_tool']) !== []) {
            throw new RuntimeException('Unexpected suite artifact binding.');
        }
        foreach (['archive_sha256', 'manifest_sha256'] as $key) {
            if (! is_string($binding[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $binding[$key]) !== 1) {
                throw new RuntimeException('Exact artifact fingerprints are required.');
            }
        }
        $root = $binding['runtime_root'] ?? null;
        if (! is_string($root) || realpath($root) !== $root || is_link($root)
            || ! is_string($binding['archive_path'] ?? null) || is_link($binding['archive_path'])
            || ! is_file($binding['archive_path']) || ! hash_equals($binding['archive_sha256'], hash_file('sha256', $binding['archive_path']))) {
            throw new RuntimeException('Qualified suite archive identity changed.');
        }
        $native = array_key_exists('native_tool', $binding);
        if ($native && (array_key_exists('managed_model', $binding)
            || array_key_exists('suite_version', $binding) || array_key_exists('suite_contract_sha256', $binding))) {
            throw new RuntimeException('Native and model suite contracts cannot be combined.');
        }
        $manifestPath = $root.($native ? '/worker-manifest.json' : '/runtime-manifest.json');
        if (! is_file($manifestPath) || is_link($manifestPath) || ! hash_equals($binding['manifest_sha256'], hash_file('sha256', $manifestPath))) {
            throw new RuntimeException('Qualified suite manifest identity changed.');
        }
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['files'] ?? [] as $path => $hash) {
            $file = realpath($root.'/'.$path);
            if ($file === false || ! str_starts_with($file, $root.'/') || is_link($root.'/'.$path)
                || ! hash_equals($hash, hash_file('sha256', $file))) {
                throw new RuntimeException('Qualified runtime file changed.');
            }
        }
        foreach (($native ? ['scripts/plos-cli/framework-service.php', 'bootstrap/app.php', 'integrity.php'] : ['lab/bin/suite-probe.php', 'lab/bin/bootstrap.php', 'lab/manifest/qualified-suite.json']) as $file) {
            if (! isset($manifest['files'][$file])) {
                throw new RuntimeException('Qualified suite entrypoint is not in the runtime manifest.');
            }
        }

        if ($native) {
            if (! is_array($binding['native_tool'])) {
                throw new RuntimeException('Exact native action plan required.');
            }
            (new CyberNativeToolSuiteService)->validatePlan($binding['native_tool']);
        }

        if (array_key_exists('suite_version', $binding) || array_key_exists('managed_model', $binding)) {
            $contract = $this->suiteContract($binding);
            if (array_key_exists('suite_version', $binding)
                && (! is_string($binding['suite_contract_sha256'] ?? null)
                    || ! hash_equals($contract['sha256'], $binding['suite_contract_sha256']))) {
                throw new RuntimeException('Reviewed suite version contract changed.');
            }
        } elseif (array_key_exists('suite_contract_sha256', $binding)) {
            throw new RuntimeException('A suite contract requires its explicit reviewed version.');
        }

        if (array_key_exists('managed_model', $binding)) {
            $managed = $binding['managed_model'];
            if (! is_array($managed) || array_diff(array_keys($managed), ['connection_directory', 'connection_sha256']) !== []
                || ! is_string($managed['connection_directory'] ?? null) || ! is_string($managed['connection_sha256'] ?? null)
                || ! hash_equals($managed['connection_sha256'], CyberManagedModelSuiteService::connectionFingerprint($managed['connection_directory']))) {
                throw new RuntimeException('Managed model connection differs from approved binding.');
            }
            foreach (['lab/bin/managed-model.php', 'lab/src/SuiteHarness.php'] as $file) {
                if (! isset($manifest['files'][$file])) {
                    throw new RuntimeException('Managed model entrypoint is not qualified.');
                }
            }
        }

        return $binding;
    }

    /** Fixed coverage comes from validated server authority, never report metadata. */
    public function suiteCoverage(array $binding): array
    {
        $contract = $this->suiteContract($binding);
        if (array_key_exists('suite_version', $binding)
            && (! is_string($binding['suite_contract_sha256'] ?? null)
                || ! hash_equals($contract['sha256'], $binding['suite_contract_sha256']))) {
            throw new RuntimeException('Reviewed suite version contract changed.');
        }

        return $contract['coverage'];
    }

    private function suiteContract(array $binding): array
    {
        if (array_key_exists('suite_contract_sha256', $binding) && ! array_key_exists('suite_version', $binding)) {
            throw new RuntimeException('A suite contract requires its explicit reviewed version.');
        }
        $version = array_key_exists('suite_version', $binding) ? $binding['suite_version'] : 'original-three-v1';
        if (! is_string($version) || ! isset(self::SUITE_COVERAGE[$version])) {
            throw new RuntimeException('Unsupported reviewed synthetic suite version.');
        }
        if ($version === 'laravel-four-v1'
            && ($this->workflowName() !== 'cyber_managed_model_suite' || ! is_array($binding['managed_model'] ?? null))) {
            throw new RuntimeException('The reviewed four-case suite requires the managed workflow profile.');
        }
        $coverage = self::SUITE_COVERAGE[$version];
        $root = $binding['runtime_root'] ?? null;
        if (! is_string($root) || realpath($root) !== $root || is_link($root)) {
            throw new RuntimeException('Exact reviewed suite root required.');
        }
        $manifestPath = $root.'/runtime-manifest.json';
        if (! is_file($manifestPath) || is_link($manifestPath)
            || hash_file('sha256', $manifestPath) !== ($binding['manifest_sha256'] ?? null)) {
            throw new RuntimeException('Reviewed suite runtime manifest changed.');
        }
        $runtime = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $suitePath = $root.'/lab/manifest/qualified-suite.json';
        if (! is_file($suitePath) || is_link($suitePath)
            || hash_file('sha256', $suitePath) !== ($runtime['files']['lab/manifest/qualified-suite.json'] ?? null)) {
            throw new RuntimeException('Reviewed suite definition changed.');
        }
        $suite = json_decode(file_get_contents($suitePath), true, flags: JSON_THROW_ON_ERROR);
        if (($suite['schema'] ?? null) !== 'plos.security.synthetic-suite.v1' || ! is_array($suite['files'] ?? null)) {
            throw new RuntimeException('Reviewed suite definition is unavailable.');
        }
        // The original qualified-suite manifest predates its explicit SuiteTools
        // entry; that class must still be exact in the outer runtime manifest.
        $toolsPath = $root.'/lab/src/SuiteTools.php';
        if (! is_file($toolsPath) || is_link($toolsPath)
            || hash_file('sha256', $toolsPath) !== ($runtime['files']['lab/src/SuiteTools.php'] ?? null)) {
            throw new RuntimeException('Reviewed suite adapter identity changed.');
        }
        $required = ['bin/suite-probe.php', 'src/SuiteHarness.php'];
        if ($version === 'laravel-four-v1') {
            $required[] = 'src/SuiteTools.php';
        }
        foreach (array_keys($coverage) as $case) {
            foreach (['source.php', 'clean.php', 'schema.sql', 'USE-CASE.md'] as $name) {
                $required[] = 'fixtures/suite/'.$case.'/'.$name;
            }
        }
        $actual = array_keys($suite['files']);
        sort($required);
        sort($actual);
        if ($actual !== $required) {
            throw new RuntimeException('Artifact cases differ from the reviewed suite version.');
        }
        foreach ($required as $relative) {
            $hash = $suite['files'][$relative];
            $path = realpath($root.'/lab/'.$relative);
            if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
                || $path === false || ! str_starts_with($path, $root.'/lab/') || is_link($root.'/lab/'.$relative)
                || ($runtime['files']['lab/'.$relative] ?? null) !== $hash || hash_file('sha256', $path) !== $hash) {
                throw new RuntimeException('Reviewed suite source, evaluator or holdout identity changed.');
            }
        }

        return ['coverage' => $coverage, 'sha256' => hash('sha256', json_encode([
            'version' => $version, 'coverage' => $coverage, 'archive_sha256' => $binding['archive_sha256'] ?? null,
            'runtime_manifest_sha256' => $binding['manifest_sha256'], 'suite_manifest_sha256' => hash_file('sha256', $suitePath),
        ], JSON_THROW_ON_ERROR))];
    }

    public function workflowName(): string
    {
        $policy = app(SystemConfigService::class)->get('cyber.workflow_jobs', []);
        $name = $policy['workflow_name'] ?? 'cyber_qualified_suite_checks';
        if (! in_array($name, ['cyber_qualified_suite_checks', 'cyber_managed_model_suite', 'cyber_qualified_native_tools'], true)) {
            throw new RuntimeException('Unsupported qualified CYBER workflow profile.');
        }

        return $name;
    }

    public function expectedActions(): array
    {
        if ($this->workflowName() === 'cyber_qualified_native_tools') {
            return ['native-framework-action'];
        }

        return $this->workflowName() === 'cyber_managed_model_suite' ? ['managed-model-suite']
            : ['suite-check-billing_scope', 'suite-check-reference_lookup', 'suite-check-profile_update'];
    }

    public function workflowFingerprint(): string
    {
        $workflow = DB::table('workflows')->where('name', $this->workflowName())->first();
        if ($workflow === null || ! $workflow->active) {
            throw new RuntimeException('Qualified CYBER workflow registration is unavailable.');
        }
        $nodes = DB::table('workflow_nodes')->where('workflow_id', $workflow->id)->orderBy('node_order')->get()->all();
        $nodeIds = array_map(fn ($node) => $node->id, $nodes);
        $configs = DB::table('workflow_node_configs')->whereIn('workflow_node_id', $nodeIds)->orderBy('workflow_node_id')->orderBy('config_key')->get()->all();
        $defaults = DB::table('workflow_defaults')->where('workflow_id', $workflow->id)->orderBy('config_key')->get()->all();

        return hash('sha256', json_encode([$workflow, $nodes, $configs, $defaults], JSON_THROW_ON_ERROR));
    }

    public function assertJobBinding(object $row): array
    {
        $binding = json_decode($row->artifact_binding, true, flags: JSON_THROW_ON_ERROR);
        $this->verify($binding);
        if (! hash_equals($row->workflow_sha256, $this->workflowFingerprint())) {
            throw new RuntimeException('Approved workflow definition changed; no task execution.');
        }

        return $binding;
    }
}
