<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->update(false);
    }

    public function down(): void
    {
        $this->update(true);
    }

    private function update(bool $rollback): void
    {
        DB::transaction(function () use ($rollback): void {
            $row = DB::table('llm_instances')->where('instance_id', 'colibri_87')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $config = json_decode($row->config ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($config)) {
                throw new RuntimeException('Colibri configuration must be a JSON object.');
            }
            $contract = $this->contract();
            if ($rollback) {
                $actual = $config['cli_agent_runtime'] ?? null;
                if (! is_array($actual) || json_encode(Arr::sortRecursive($actual)) !== json_encode(Arr::sortRecursive($contract))
                    || ($config['route_policy']['tools_enabled'] ?? null) !== true) {
                    return;
                }
                unset($config['cli_agent_runtime']);
                $config['route_policy']['tools_enabled'] = false;
            } else {
                // Preserve existing operator runtime definitions and unrelated
                // routing choices. This is the known tool-free predecessor.
                if (array_key_exists('cli_agent_runtime', $config)
                    || ($config['route_policy']['mode'] ?? null) !== 'operator_selected_deep_offline'
                    || ($config['route_policy']['tools_enabled'] ?? null) !== false) {
                    return;
                }
                $config['cli_agent_runtime'] = $contract;
                $config['route_policy']['tools_enabled'] = true;
            }
            DB::table('llm_instances')->where('id', $row->id)->update(['config' => json_encode($config,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        });
    }

    private function contract(): array
    {
        // Native file/tool acceptance 20260909e verified these exact served
        // identities. Current host/lease/health checks remain mandatory. Ordinary
        // turn/action limits follow the existing CLI defaults; operators can
        // override this table definition per session and optionally save it.
        return json_decode(<<<'JSON'
{
    "schema": "plos.cli.agent-runtime.v1",
    "model": "glm-5.2-colibri",
    "gateway_sha256": "e2137b40355b24acc4c4c7d07c6eb47754b2eadc9dd788e9d0895713aa9972d0",
    "engine_sha256": "9fdc41c1f2552e3d6cf04fde00392508f835378c6f634bc1e8514ac9b214c531",
    "controller_target": "glm52_87",
    "context_window": 8192,
    "max_output_tokens": 256,
    "max_iterations": 10,
    "max_tool_calls": 40,
    "enable_thinking": false,
    "temperature": 0.0,
    "absolute_timeout_seconds": 1800,
    "idle_timeout_seconds": 1800,
    "tokenizer": {
        "driver": "colibri_glm",
        "tokenizers_version": "0.22.2",
        "renderer_sha256": "e2137b40355b24acc4c4c7d07c6eb47754b2eadc9dd788e9d0895713aa9972d0",
        "tokenizer_sha256": "19e773648cb4e65de8660ea6365e10acca112d42a854923df93db4a6f333a82d",
        "family_registry_sha256": "feac98d9cafd236b0b2e8fb745ca2a6456e17726c2f72b061e33c9fe038ba8e3",
        "v4_dsml_sha256": "e89ba2935458802a3e869e547d0b5911e7aef0a84814a232d35272e8efbcf74d"
    }
}
JSON, true, flags: JSON_THROW_ON_ERROR);
    }
};
