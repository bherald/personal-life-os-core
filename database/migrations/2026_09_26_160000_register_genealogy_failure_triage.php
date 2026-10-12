<?php

use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use App\Services\SystemConfigService;
use App\Services\TypeSafeJevDecisionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function diagnosisPolicy(): array
    {
        return [
            'enabled' => true,
            'cooldown_seconds' => 21600,
            'deterministic_errors' => [
                'Inference request admission failed before send.' => [
                    'category' => 'inference_admission_rejected', 'diagnostic_path' => 'managed_inference_contract_review',
                ],
                'This registered endpoint requires managed controller custody before inference.' => [
                    'category' => 'managed_custody_missing', 'diagnostic_path' => 'managed_inference_contract_review',
                ],
            ],
            'signal_patterns' => [
                'timeout' => '/timeout|timed out/i',
                'rate_limited' => '/rate.limit|too many requests|\b429\b/i',
                'authentication' => '/unauthori[sz]ed|authentication|\b401\b/i',
                'connection' => '/connection|dns|unreachable|refused/i',
                'capacity' => '/capacity|out of memory|resource|busy/i',
                'admission' => '/admission|custody|lease|request contract/i',
                'invalid_result' => '/schema|invalid.*response|malformed|parse/i',
            ],
            'questions' => [
                'diagnostic_path' => [
                    'type' => 'choice',
                    'instructions' => 'Select the existing diagnostic area from these operational signal flags. Do not infer missing facts or authorize retries, fallback, configuration changes, or data writes. Choose insufficient_evidence when signals do not support a path.',
                    'criteria' => [
                        'managed_inference_contract_review' => 'Admission, custody, lease or request-contract mismatch.',
                        'provider_connectivity_review' => 'Network connection or reachability symptoms.',
                        'provider_authentication_review' => 'Authentication rejected; inspect configured credential binding without disclosing credentials.',
                        'provider_capacity_review' => 'Capacity, rate limiting, or timeout symptoms requiring existing runtime diagnostics.',
                        'response_contract_review' => 'Invalid response or schema symptoms.',
                        'insufficient_evidence' => 'No supported diagnostic area; inspect local task evidence.',
                    ],
                ],
            ],
        ];
    }

    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'typesafe_jev_policy')->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Existing Jev task policy must be registered first.');
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (($policy['schema'] ?? null) !== 'plos.typesafe_jev_policy.v1'
                || ! is_array($policy['tasks']['pipeline_issue_triage'] ?? null)) {
                throw new RuntimeException('Existing pipeline issue triage task is unavailable.');
            }
            if (! array_key_exists('failure_diagnosis', $policy['tasks']['pipeline_issue_triage'])) {
                $policy['tasks']['pipeline_issue_triage']['failure_diagnosis'] = $this->diagnosisPolicy();
                DB::table('system_configs')->where('id', $row->id)->update([
                    'config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }

            $tool = DB::table('agent_tool_registry')->where('name', 'typesafe_jev_decide')->first();
            if ($tool === null || $tool->service_class !== TypeSafeJevDecisionService::class || $tool->method !== 'evaluate') {
                throw new RuntimeException('Unexpected Jev tool binding; refusing skill registration.');
            }
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('genealogy-records');
            if ($skill === null || ! in_array('typesafe_jev_decide', $skill['frontmatter']['tools'] ?? [], true)) {
                throw new RuntimeException('Updated genealogy-records source skill is required.');
            }
            app(SkillVersionService::class)->trackVersion('genealogy-records', $skill['frontmatter'], $skill['body'], $skill['full_content']);
        });
        app(SystemConfigService::class)->forget(TypeSafeJevDecisionService::POLICY_KEY);
    }

    public function down(): void
    {
        // Preserve operator amendments and skill history. Set failure_diagnosis.enabled=false to disable.
    }
};
