<?php

use App\Services\ModelHostControllerResourcePolicyService as Resources;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PROFILE = 'candidate87.bitnet_87.cf91efb43de7.4096.serial';

    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('BitNet qualification requires the existing resource policy.');
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $profile = $this->profile();
            $binding = $policy['bindings']['.87']['bitnet_87'] ?? null;
            if ($binding !== null && $binding !== self::PROFILE) {
                throw new RuntimeException('BitNet resource binding differs from measured qualification.');
            }
            $existing = $policy['profiles'][self::PROFILE] ?? null;
            if ($existing !== null) {
                $existing['qualified'] = true;
                if (Resources::requestContractIdentity($existing) !== Resources::requestContractIdentity($profile)) {
                    throw new RuntimeException('BitNet resource profile differs from measured qualification.');
                }
            }
            $next = $policy;
            $next['profiles'][self::PROFILE] = $profile;
            $next['bindings']['.87']['bitnet_87'] = self::PROFILE;
            (new Resources(fn () => $next))->required();
            if ($next === $policy) {
                return;
            }
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($next, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Retain measured evidence; rollback does not change model/provider admission.
    }

    private function profile(): array
    {
        // DEV readiness-dev-bitnet-20260926-02: actual four-token privacy allow,
        // loaded capacity renewed, exact release idle and provider config restored.
        // Keep the installed candidate identity; no generic text/tool role is qualified.
        return [
            'qualified' => true,
            'host_id' => '.87',
            'target' => 'bitnet_87',
            'model_identity' => 'sha256:4221b252fdd5fd25e15847adfeb5ee88886506ba50b8a34548374492884c2162',
            'runtime_identity' => 'sha256:df6bc1c3905e3b3d112f31d90a7ccb5348fcc8c6d1cd02eaa9e161892f94955e',
            'settings' => [
                'context_tokens' => 4096,
                'parallel_requests' => 1,
                'mtp_enabled' => false,
            ],
            'requirements' => [
                'cold' => [
                    'free_physical_bytes' => [
                        'minimum' => 4294967296,
                    ],
                    'commit_headroom_bytes' => [
                        'minimum' => 8589934592,
                    ],
                    'drive_c_free_bytes' => [
                        'minimum' => 42949672960,
                    ],
                    'drive_d_free_bytes' => [
                        'minimum' => 42949672960,
                    ],
                    'pagefile_current_bytes' => [
                        'maximum' => 12884901888,
                    ],
                ],
                'loaded' => [
                    'free_physical_bytes' => [
                        'minimum' => 2147483648,
                    ],
                    'commit_headroom_bytes' => [
                        'minimum' => 8589934592,
                    ],
                    'drive_c_free_bytes' => [
                        'minimum' => 42949672960,
                    ],
                    'drive_d_free_bytes' => [
                        'minimum' => 42949672960,
                    ],
                    'pagefile_current_bytes' => [
                        'maximum' => 12884901888,
                    ],
                    'pagefile_growth_bytes' => [
                        'maximum' => 3221225472,
                    ],
                ],
            ],
            'request_contracts' => [
                'plos.privacy_deny_prefilter.candidate' => 'f56275ddb976219167f551e392077be0c25cbe67a9b1ed55083f4e220b47a960',
            ],
        ];
    }
};
