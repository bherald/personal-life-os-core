<?php

use App\Services\Genealogy\GenealogyMediaIntakeReportService;
use App\Services\Genealogy\GenealogyMediaIntakeRunService;
use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function definitions(): array
    {
        $common = [
            'tree_id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
            'root' => ['type' => 'string', 'required' => false, 'description' => 'Existing media/file-registry root on the selected workflow target; omitted uses the existing tree-root resolver.'],
            'limit' => ['type' => 'integer', 'required' => false, 'minimum' => 1, 'maximum' => 200, 'default' => 50],
        ];
        $run = $common + ['dry_run' => ['type' => 'boolean', 'default' => true],
            'retain_output' => ['type' => 'boolean', 'default' => true, 'x_context_only' => true]];
        foreach (['stage', 'save_run', 'confirm_noncanonical_write', 'transcribe_dry_run', 'enrich_dry_run'] as $option) {
            $run[$option] = ['type' => 'boolean', 'default' => false];
        }
        $definitions = [];
        foreach ([
            ['media_intake_report', GenealogyMediaIntakeReportService::class, 'collect',
                'Read the existing tree-scoped Genea media intake report, including source paths, samples and pipeline actions.',
                $common + ['dry_run' => ['type' => 'boolean', 'default' => false]], false],
            ['media_intake_run', GenealogyMediaIntakeRunService::class, 'run',
                'Run existing tree-scoped intake staging, optional confirmed intake-snapshot saving, and transcription/enrichment dry runs. Defaults to planning only; retains full output. Capture, indexing and canonical writes use their separately registered tools.',
                $run, true],
        ] as [$name, $class, $method, $description, $parameters, $write]) {
            $definitions[] = ['name' => $name, 'service_class' => $class, 'method' => $method,
                'description' => $description, 'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'returns_description' => 'Existing intake result. Run results retain complete report and output bytes, hashes, exit codes and logical failure status.',
                'permissions' => json_encode($write ? ['genealogy:read', 'genealogy:write'] : ['genealogy:read'], JSON_THROW_ON_ERROR),
                'risk_level' => $write ? 'write' : 'read', 'requires_confirmation' => $write,
                'category' => 'genealogy', 'enabled' => true, 'source' => 'manual',
                'notes' => 'CLI Genea intake registration v1; existing framework service with selected-tree execution rule.'];
        }

        return $definitions;
    }

    private function rule(): array
    {
        return ['target' => 'workflow', 'tree_scoped' => true, 'tree_argument' => 'tree_id', 'references' => []];
    }

    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Install the framework service configuration before registering intake tools.');
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach ($this->definitions() as $definition) {
                DB::table('agent_tool_registry')->insertOrIgnore($definition + ['created_at' => now(), 'updated_at' => now()]);
                $registered = DB::table('agent_tool_registry')->where('name', $definition['name'])
                    ->where('service_class', $definition['service_class'])->where('method', $definition['method'])->exists();
                if ($registered && ! array_key_exists($definition['name'], $policy['tools'])) {
                    $policy['tools'][$definition['name']] = $this->rule();
                }
            }
            $this->savePolicy($row, $policy);
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('genealogy-researcher');
            if ($skill === null) {
                throw new RuntimeException('The deployed genealogy-researcher skill source is unavailable.');
            }
            app(SkillVersionService::class)->trackVersion('genealogy-researcher', $skill['frontmatter'], $skill['body'], $skill['full_content']);
        });
        app(SystemConfigService::class)->forget('cli.framework_services');
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            $policy = $row === null ? null : json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach ($this->definitions() as $definition) {
                $tool = DB::table('agent_tool_registry')->where('name', $definition['name'])->lockForUpdate()->first();
                if ($tool === null) {
                    continue;
                }
                foreach ($definition as $field => $value) {
                    $actual = $tool->{$field};
                    $equal = in_array($field, ['parameters', 'permissions'], true)
                        ? json_decode($actual, true, flags: JSON_THROW_ON_ERROR) == json_decode($value, true, flags: JSON_THROW_ON_ERROR)
                        : (string) $actual === (string) (is_bool($value) ? (int) $value : $value);
                    if (! $equal) {
                        continue 2;
                    }
                }
                if ($policy !== null && ($policy['tools'][$definition['name']] ?? null) !== $this->rule()) {
                    continue;
                }
                DB::table('agent_tool_registry')->where('id', $tool->id)->delete();
                if ($policy !== null) {
                    unset($policy['tools'][$definition['name']]);
                }
            }
            if ($row !== null) {
                $this->savePolicy($row, $policy);
            }
            // Retain skill version history; restoring source uses normal framework version tracking.
        });
        app(SystemConfigService::class)->forget('cli.framework_services');
    }

    private function savePolicy(object $row, array $policy): void
    {
        $encoded = json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if ($encoded !== $row->config_value && DB::table('system_configs')->where('id', $row->id)
            ->where('config_value', $row->config_value)->update(['config_value' => $encoded, 'updated_at' => now()]) !== 1) {
            throw new RuntimeException('Framework configuration changed while registering intake tools.');
        }
    }
};
