<?php

use App\Services\ModelHostControllerPolicyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $id = 'native_qwen3_coder_87';
            if (! isset($policy['targets'][$id])) {
                return;
            }
            if (($policy['targets'][$id]['lifecycle_adapter']['adapter_type'] ?? null) !== 'scheduled_llama') {
                throw new RuntimeException('Native verification budget requires the registered scheduled launcher.');
            }
            if (array_key_exists('verification_timeout_seconds', $policy['targets'][$id])) {
                (new ModelHostControllerPolicyService)->normalize($policy);

                return; // Preserve subsequent operator tuning, including on replay.
            }
            // Previous managed cold activation took 276.725 seconds. Ten minutes
            // bounds cold verification/scheduling with margin; engine readiness
            // retains its separately registered budget. Not a speed forecast.
            $policy['targets'][$id]['verification_timeout_seconds'] = 600;
            (new ModelHostControllerPolicyService)->normalize($policy);
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Preserve operator tuning. Runtime rollback restores exact recorded
        // table and host-manifest preimages together, under empty-custody gates.
    }
};
