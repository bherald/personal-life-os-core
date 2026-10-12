<?php

namespace App\Support;

final class PromptIntegrityPolicy
{
    public const SCHEMA = 'lossless_ai_prompts.v1';

    public const DISABLED_REASON = 'lossy_prompt_transform_disabled_by_operator';

    public static function allowsLossyTransforms(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function inventory(): array
    {
        return [
            'schema' => self::SCHEMA,
            'mode' => 'enforced_off',
            'lossy_prompt_transforms_allowed' => false,
            'agent_log_precompaction_allowed' => false,
            'rlm_auto_decompose_allowed' => false,
            'ollama_pipeline_compression_allowed' => false,
            'contextual_rag_compression_allowed' => false,
            'prompt_compressor_mcp_allowed' => false,
            'environment_override_allowed' => false,
            'reason' => 'Preserve complete prompt, tool-result, and evidence detail for every AI provider.',
            'policy_source' => 'App\\Support\\PromptIntegrityPolicy + system_configs.ai.prompt_integrity_policy',
        ];
    }
}
