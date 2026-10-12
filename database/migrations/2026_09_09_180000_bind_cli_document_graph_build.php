<?php

use App\Services\KnowledgeGraphService;
use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const METHODS = ['graph_build_document' => 'buildFromDocument'];

    public function up(): void
    {
        $this->change(true);
    }

    public function down(): void
    {
        // Retain the corrected shared contracts and all graph data.
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
            // The knowledge graph is shared RAG data, not a Genea tree write.
            $rule = ['target' => 'workflow', 'tree_scoped' => false, 'references' => []];
            foreach (self::METHODS as $name => $method) {
                if (! $install) {
                    if (($policy['tools'][$name] ?? null) === $rule) {
                        unset($policy['tools'][$name]);
                    }

                    continue;
                }
                $tool = DB::table('agent_tool_registry')->where('name', $name)
                    ->where('service_class', KnowledgeGraphService::class)->where('method', $method)->lockForUpdate()->first();
                if ($tool === null) {
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
                throw new RuntimeException('Document graph bindings changed during migration.');
            }

            return true;
        });
        if ($changed) {
            app(SystemConfigService::class)->forget('cli.framework_services');
        }
    }
};
