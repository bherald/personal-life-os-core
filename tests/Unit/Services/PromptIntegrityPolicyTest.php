<?php

namespace Tests\Unit\Services;

use App\Services\ContextualCompressionService;
use App\Support\PromptIntegrityPolicy;
use Tests\TestCase;

class PromptIntegrityPolicyTest extends TestCase
{
    public function test_lossy_prompt_transforms_are_source_disabled(): void
    {
        $this->assertFalse(PromptIntegrityPolicy::allowsLossyTransforms());
        $this->assertSame('enforced_off', PromptIntegrityPolicy::inventory()['mode']);
        $this->assertFalse(config('ai.lossy_prompt_transforms_enabled'));
        $this->assertFalse(config('agents.enable_log_precompaction'));
        $this->assertFalse(config('recursion.auto_decompose_enabled'));
        $this->assertFalse(config('ollama_pipeline.compression_enabled'));
        $this->assertNull(config('mcp.servers.prompt-compressor'));
    }

    public function test_contextual_compression_returns_original_text_unchanged(): void
    {
        $text = "First material detail.\nSecond contradictory detail.\nThird source citation.";
        $service = app(ContextualCompressionService::class);

        $result = $service->compress($text, 'First detail');

        $this->assertSame($text, $result['compressed']);
        $this->assertSame(1.0, $result['compression_ratio']);
        $this->assertSame(0, $result['tokens_saved_estimate']);
        $this->assertTrue($result['compression_disabled']);
    }

    public function test_contextual_batch_does_not_drop_chunks_for_a_token_budget(): void
    {
        $chunks = ['one full chunk', 'two full chunk', 'three full chunk'];
        $service = app(ContextualCompressionService::class);

        $result = $service->batchCompress($chunks, 'query', 1);

        $this->assertSame(3, $result['total_chunks']);
        $this->assertSame($chunks, array_column($result['chunks'], 'compressed'));
        $this->assertTrue($result['compression_disabled']);
    }
}
