<?php

use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use App\Services\SystemConfigService;
use App\Services\TypeSafeJevDecisionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function exclusions(): array
    {
        return [
            'key_pattern' => '(?:^|_)(?:auth|authentication|authorization|password|passwd|pwd|secret|private_key|api_key|apikey|credential|credentials|cookie|session_id|session_key|token|tokens|otp|totp|recovery_codes?)(?:$|_)',
            'text_patterns' => [
                '-----BEGIN [A-Z ]*PRIVATE KEY-----',
                '\\b(?:Bearer|Basic)\\s+[A-Za-z0-9._~+/=:-]{8,}',
                '\\b(?:apikey_[a-z0-9_-]{8,}|sk-[a-zA-Z0-9_-]{16,}|gh[pousr]_[a-zA-Z0-9]{20,})',
                '\\beyJ[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+',
                '\\b(?:auth(?:entication|orization)?|password|passwd|pwd|secret|(?:api|private)[_-]?key|(?:access|refresh|id|auth|session)[_-]?token|credentials?|cookies?|session[_-]?(?:id|key)|otp|totp|recovery[_-]?codes?)\\s*[=:]\\s*[^\\s,;]+',
                '[a-z][a-z0-9+.-]*://[^\\s/:]+:[^\\s/@]+@',
            ],
        ];
    }

    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'typesafe_jev_policy')->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Register the existing TypeSafe task policy before extending it.');
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (($policy['schema'] ?? null) !== 'plos.typesafe_jev_policy.v1' || ! is_array($policy['tasks'] ?? null)) {
                throw new RuntimeException('Invalid TypeSafe task policy; refusing to replace operator settings.');
            }
            // Seed once: subsequent runs preserve operator amendments, including revocation.
            if (! array_key_exists('private_task_policy_version', $policy)) {
                $approvedTasks = ['cli_task_skill_selection', 'todo_triage', 'social_state_classification', 'evidence_relevance', 'claim_support', 'genealogy_queue_triage', 'pipeline_issue_triage'];
                $policy['allowed_data_classifications'] = array_values(array_unique([...($policy['allowed_data_classifications'] ?? []), 'private_internal']));
                foreach ($approvedTasks as $name) {
                    if (isset($policy['tasks'][$name])) {
                        $policy['tasks'][$name]['allowed_data_classifications'] = array_values(array_unique([...($policy['tasks'][$name]['allowed_data_classifications'] ?? []), 'private_internal']));
                        $policy['tasks'][$name]['description'] = str_replace('sanitized ', '', $policy['tasks'][$name]['description'] ?? $name);
                    }
                }
                $policy['payload_exclusions'] ??= $this->exclusions();
                $policy['private_task_policy_version'] = 1;
                DB::table('system_configs')->where('id', $row->id)->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
            $tool = DB::table('agent_tool_registry')->where('name', 'typesafe_jev_decide')->lockForUpdate()->first();
            if ($tool === null || $tool->service_class !== TypeSafeJevDecisionService::class || $tool->method !== 'evaluate') {
                throw new RuntimeException('Unexpected TypeSafe tool binding.');
            }
            $parameters = json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR);
            $parameters['properties']['data_classification']['enum'] = $policy['allowed_data_classifications'];
            DB::table('agent_tool_registry')->where('id', $tool->id)->update([
                'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
                'description' => 'Use TypeSafe Jev for typed judgments within the enabled tasks in ai.typesafe_jev_policy. Full task-relevant private state and questions are allowed with private_internal where the task policy permits. Exclude authentication material, credentials and security/access tokens. Ask noul, choice or score questions with clear instructions and criteria; verify evidence and uncertainty. Results do not grant action or identity approval. On status=skipped continue the existing route.',
                'notes' => 'Operator-approved registered task scope; table-owned classifications and payload exclusions; preserve network, identity and action approvals.',
                'privacy_class' => 'online_registered_task_input',
                'allows_private_data' => in_array('private_internal', $policy['allowed_data_classifications'], true) ? 1 : 0,
                'updated_at' => now(),
            ]);
            foreach (['offline-assistant', 'ai-ops', 'research-analyst', 'genealogy-researcher', 'workflow-ops', 'plos-review-lookup'] as $name) {
                $skill = app(SkillLoaderService::class)->loadSkillSnapshot($name);
                if ($skill === null) {
                    throw new RuntimeException("Missing skill: {$name}");
                }
                app(SkillVersionService::class)->trackVersion($name, $skill['frontmatter'], $skill['body'], $skill['full_content']);
            }
        });
        app(SystemConfigService::class)->forget(TypeSafeJevDecisionService::POLICY_KEY);
    }

    public function down(): void
    {
        // Retain operator-owned settings. Revoke private_internal in the policy for rollback.
    }
};
