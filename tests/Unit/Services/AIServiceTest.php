<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\AIExceptionFactory;
use App\Exceptions\AI\AIServiceException;
use App\Exceptions\AI\AuthenticationException;
use App\Exceptions\AI\BusyException;
use App\Exceptions\AI\ConnectionException;
use App\Exceptions\AI\ContentPolicyException;
use App\Exceptions\AI\ContextLengthException;
use App\Exceptions\AI\ModelNotFoundException;
use App\Exceptions\AI\PermanentException;
use App\Exceptions\AI\RateLimitException;
use App\Exceptions\AI\ServerOverloadException;
use App\Exceptions\AI\TimeoutException;
use App\Exceptions\AI\TransientException;
use App\Exceptions\AI\ValidationException;
use App\Services\AIService;
use App\Services\SystemConfigService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use ReflectionMethod;
use Tests\Support\HostileCorpus;
use Tests\TestCase;

/**
 * Unit tests for AIService
 *
 * Tests pure logic methods that do not require actual LLM API calls:
 * - estimateTokenCount: string length to token estimation
 * - splitForEmbedding: complete UTF-8 input chunking
 * - getOverlapText: chunk overlap extraction
 * - meanPoolEmbeddings: vector math for embedding fusion
 * - calculateBackoff: exponential backoff with jitter
 * - isModelMatch: model name comparison
 * - getEmbeddingContextLimit: context limit resolution
 * - AIExceptionFactory: exception classification (transient vs permanent)
 */
class AIServiceTest extends TestCase
{
    private ?AIService $service = null;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Lazily create an AIService instance with all DB/cache/log mocks.
     * Only tests that need private method reflection should call this.
     */
    private function getService(): AIService
    {
        if ($this->service === null) {
            // Mock DB for AIRouter constructor (selectOne) and AIService constructor (select)
            DB::shouldReceive('selectOne')->andReturn(null);
            DB::shouldReceive('select')->andReturn([]);

            // Mock Cache for SemanticCache and general usage
            Cache::shouldReceive('has')->andReturn(false);
            Cache::shouldReceive('get')->andReturn(null);
            Cache::shouldReceive('put')->andReturn(true);
            Cache::shouldReceive('remember')->andReturnUsing(function ($key, $ttl, $callback) {
                return $callback();
            });

            Log::shouldReceive('warning')->zeroOrMoreTimes();
            Log::shouldReceive('info')->zeroOrMoreTimes();
            Log::shouldReceive('debug')->zeroOrMoreTimes();

            if (! $this->app->bound(SystemConfigService::class)) {
                $config = Mockery::mock(SystemConfigService::class);
                $config->shouldReceive('get')
                    ->byDefault()
                    ->andReturnUsing(fn (string $key, mixed $default = null): mixed => $default);
                $this->app->instance(SystemConfigService::class, $config);
            }

            $this->service = new AIService;
        }

        return $this->service;
    }

    /**
     * Call a private/protected method on the service for testing.
     */
    private function callPrivate(string $method, ...$args): mixed
    {
        $ref = new ReflectionMethod($this->getService(), $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->getService(), ...$args);
    }

    // =========================================================================
    // estimateTokenCount Tests
    // =========================================================================

    public function test_model_telemetry_correlates_and_orders_route_attempts(): void
    {
        $config = $this->callPrivate('withRouteTraceContext', [
            'route_trace_id' => '00112233445566778899aabbccddeeff',
            'caller' => 'unit_test',
            'request_kind' => 'vision',
        ]);

        $first = $this->callPrivate('modelTelemetry', $config, 'model-a');
        $second = $this->callPrivate('modelTelemetry', $config, 'model-b');

        $this->assertSame('00112233445566778899aabbccddeeff', $first['route_trace_id']);
        $this->assertSame($first['route_trace_id'], $second['route_trace_id']);
        $this->assertSame(1, $first['route_attempt_index']);
        $this->assertSame(2, $second['route_attempt_index']);
        $this->assertSame('unit_test', $second['caller']);
        $this->assertSame('vision', $second['request_kind']);
    }

    public function test_estimate_token_count_empty_string(): void
    {
        $result = $this->callPrivate('estimateTokenCount', '');
        $this->assertEquals(0, $result);
    }

    public function test_estimate_token_count_short_string(): void
    {
        // strlen("hello") = 5, ceil(5 / 1.5) = ceil(3.33) = 4
        $result = $this->callPrivate('estimateTokenCount', 'hello');
        $this->assertEquals(4, $result);
    }

    public function test_estimate_token_count_uses_strlen_divided_by_1_5(): void
    {
        $text = str_repeat('a', 150);
        // ceil(150 / 1.5) = 100
        $result = $this->callPrivate('estimateTokenCount', $text);
        $this->assertEquals(100, $result);
    }

    public function test_codex_exec_telemetry_includes_request_log_metadata(): void
    {
        $metadata = $this->callPrivate('codexExecTelemetry', [
            'model_role' => 'fast',
            'caller' => 'genealogy_agent_research_queue',
        ], [
            'model' => 'gpt-5.4-mini',
            'usage' => [
                'input_tokens' => 120,
                'cached_input_tokens' => 80,
                'cache_write_input_tokens' => 20,
                'output_tokens' => 30,
                'reasoning_output_tokens' => 10,
            ],
        ]);

        $this->assertSame('codex_exec', $metadata['instance_id']);
        $this->assertSame('gpt-5.4-mini', $metadata['model']);
        $this->assertSame('fast', $metadata['model_role']);
        $this->assertSame('genealogy_agent_research_queue', $metadata['caller']);
        $this->assertSame('chat', $metadata['request_kind']);
        $this->assertTrue($metadata['escalated']);
        $this->assertSame(120, $metadata['input_tokens']);
        $this->assertSame(80, $metadata['cached_input_tokens']);
        $this->assertSame(20, $metadata['cache_write_input_tokens']);
        $this->assertSame(30, $metadata['output_tokens']);
        $this->assertSame(10, $metadata['reasoning_output_tokens']);
    }

    public function test_table_role_policy_blocks_quality_until_instance_is_promoted_for_that_role(): void
    {
        $jetsonConfig = [
            'models' => [
                'fast' => 'qwen3:4b',
                'embedding' => 'embeddinggemma:latest',
                'health' => 'smollm2:135m',
                'coding' => 'qwen2.5-coder:3b',
            ],
            'route_policy' => [
                'require_model_role' => true,
                'allowed_model_roles' => ['fast', 'embedding', 'health', 'coding'],
                'allow_generic_fallback' => false,
            ],
        ];

        $this->assertTrue($this->callPrivate('providerConfigAllowsModelRole', $jetsonConfig, 'fast'));
        $this->assertFalse($this->callPrivate('providerConfigAllowsModelRole', $jetsonConfig, 'quality'));
    }

    public function test_ollama_role_filter_removes_instances_without_table_allowed_role(): void
    {
        $instances = [
            [
                'id' => 'ollama_jetson',
                'config' => [
                    'models' => [
                        'fast' => 'qwen3:4b',
                        'embedding' => 'embeddinggemma:latest',
                    ],
                    'route_policy' => [
                        'require_model_role' => true,
                        'allowed_model_roles' => ['fast', 'embedding'],
                        'allow_generic_fallback' => false,
                    ],
                ],
            ],
            [
                'id' => 'ollama_secondary',
                'config' => [
                    'models' => [
                        'fast' => 'gemma3:4b',
                        'quality' => 'qwen3:8b',
                    ],
                    'route_policy' => [
                        'require_model_role' => true,
                        'allowed_model_roles' => ['fast', 'quality'],
                        'allow_generic_fallback' => false,
                    ],
                ],
            ],
        ];

        $filtered = $this->callPrivate('filterOllamaInstancesForRole', $instances, 'quality');

        $this->assertSame(['ollama_secondary'], array_column($filtered, 'id'));
    }

    public function test_estimate_token_count_rounds_up(): void
    {
        // strlen = 10, ceil(10 / 1.5) = ceil(6.666) = 7
        $result = $this->callPrivate('estimateTokenCount', 'abcdefghij');
        $this->assertEquals(7, $result);
    }

    public function test_estimate_token_count_returns_integer(): void
    {
        $result = $this->callPrivate('estimateTokenCount', 'test string');
        $this->assertIsInt($result);
    }

    public function test_estimate_token_count_large_text(): void
    {
        $text = str_repeat('x', 30000);
        // ceil(30000 / 1.5) = 20000
        $result = $this->callPrivate('estimateTokenCount', $text);
        $this->assertEquals(20000, $result);
    }

    public function test_audio_transcript_formatter_wraps_whisper_text_as_external_data(): void
    {
        $formatted = $this->callPrivate(
            'formatAudioTranscript',
            "Speaker 1: Birth record summary.\n".HostileCorpus::INSTRUCTION_OVERRIDE_LOWERCASE,
            '/media/interview.mp3'
        );

        $this->assertStringContainsString('source_type: audio_transcript', $formatted);
        $this->assertStringContainsString('origin: /media/interview.mp3', $formatted);
        $this->assertStringContainsString('[neutralized-injection-opener]', $formatted);
        $this->assertStringNotContainsString('ignore previous instructions', $formatted);
    }

    public function test_vision_extraction_formatter_wraps_model_text_as_external_data(): void
    {
        $formatted = $this->callPrivate(
            'formatVisionExtractionText',
            "Visible note.\n".HostileCorpus::INSTRUCTION_OVERRIDE_LOWERCASE,
            '/media/photo.jpg'
        );

        $this->assertStringContainsString('source_type: vision_image', $formatted);
        $this->assertStringContainsString('origin: /media/photo.jpg', $formatted);
        $this->assertStringContainsString('[neutralized-injection-opener]', $formatted);
        $this->assertStringNotContainsString('ignore previous instructions', $formatted);
    }

    public function test_retry_after_parser_bounds_provider_error_cooldown(): void
    {
        $this->assertSame(120, $this->callPrivate('retryAfterSecondsFromProviderError', '429 retry after 120s'));
        $this->assertSame(30, $this->callPrivate('retryAfterSecondsFromProviderError', '429 quota exceeded'));
        $this->assertSame(7200, $this->callPrivate('retryAfterSecondsFromProviderError', 'retry-after: 99999'));
        $this->assertSame(30, $this->callPrivate('retryAfterSecondsFromProviderError', 'retry after 0s'));
    }

    // =========================================================================
    // splitForEmbedding Tests
    // =========================================================================

    public function test_split_for_embedding_short_text_returns_single_chunk(): void
    {
        $text = 'Short text that fits in one chunk.';
        $result = $this->callPrivate('splitForEmbedding', $text, 8192);
        $this->assertCount(1, $result);
        $this->assertEquals($text, $result[0]);
    }

    public function test_split_for_embedding_respects_token_limit(): void
    {
        // tokenLimit=100, chunkCharLimit = 100 * 0.60 * 1.5 = 90
        $text = str_repeat('Word ', 100); // 500 chars
        $result = $this->callPrivate('splitForEmbedding', $text, 100);

        foreach ($result as $chunk) {
            // Each chunk should be <= chunkCharLimit (90 chars)
            $this->assertLessThanOrEqual(90, strlen($chunk));
        }
    }

    public function test_split_for_embedding_preserves_paragraph_separators(): void
    {
        // chunkCharLimit for tokenLimit=200 = 200 * 0.60 * 1.5 = 180 chars
        $para1 = str_repeat('A', 80);
        $para2 = str_repeat('B', 80);
        $para3 = str_repeat('C', 80);
        $text = $para1."\n\n".$para2."\n\n".$para3;

        $result = $this->callPrivate('splitForEmbedding', $text, 200);

        // First chunk should contain para1+para2 (162 chars < 180 limit)
        $this->assertStringContainsString($para1, $result[0]);
        // Should have at least 2 chunks total
        $this->assertGreaterThanOrEqual(2, count($result));
        $this->assertSame($text, implode('', $result));
    }

    public function test_split_for_embedding_preserves_complete_sentences_when_reassembled(): void
    {
        // Build a single paragraph with multiple sentences that exceeds chunk limit
        // tokenLimit=100, chunkCharLimit = 90
        $sentences = [];
        for ($i = 0; $i < 10; $i++) {
            $sentences[] = "This is sentence number {$i} with some padding text.";
        }
        $text = implode(' ', $sentences);

        $result = $this->callPrivate('splitForEmbedding', $text, 100);

        $this->assertGreaterThan(1, count($result));
        $joined = implode('', $result);
        $this->assertSame($text, $joined);
        foreach ($sentences as $sentence) {
            $this->assertStringContainsString($sentence, $joined);
        }
    }

    public function test_split_for_embedding_handles_very_long_words(): void
    {
        // A base64-like blob that exceeds chunk limit in a single "word"
        // tokenLimit=100, chunkCharLimit = 90
        $longWord = str_repeat('X', 200);
        $result = $this->callPrivate('splitForEmbedding', $longWord, 100);

        $this->assertGreaterThan(1, count($result));
        foreach ($result as $chunk) {
            $this->assertLessThanOrEqual(90, strlen($chunk));
        }
    }

    public function test_split_for_embedding_empty_text_returns_original(): void
    {
        $result = $this->callPrivate('splitForEmbedding', '', 8192);
        $this->assertCount(1, $result);
    }

    public function test_split_for_embedding_whitespace_only_returns_original(): void
    {
        $result = $this->callPrivate('splitForEmbedding', "   \n\n   ", 8192);
        $this->assertCount(1, $result);
    }

    public function test_split_for_embedding_preserves_original_bytes_without_overlap_duplication(): void
    {
        // Contiguous chunks must reconstruct the original, without duplicated evidence.
        $paragraphs = [];
        for ($i = 0; $i < 10; $i++) {
            $paragraphs[] = "Paragraph {$i} has unique content here that should appear in output.";
        }
        $text = implode("\n\n", $paragraphs);

        $result = $this->callPrivate('splitForEmbedding', $text, 200);

        $this->assertGreaterThan(1, count($result));
        $this->assertSame($text, implode('', $result));
    }

    public function test_split_for_embedding_default_8192_limit(): void
    {
        // At default 8192 token limit, chunkCharLimit = 8192 * 0.60 * 1.5 = 7372.8 → (int) = 7372 chars
        $text = str_repeat('A', 7000);
        $result = $this->callPrivate('splitForEmbedding', $text, 8192);
        // 7000 < 7372, should fit in single chunk
        $this->assertCount(1, $result);

        // Just over the limit
        $text2 = str_repeat('B', 8000);
        $result2 = $this->callPrivate('splitForEmbedding', $text2, 8192);
        $this->assertGreaterThanOrEqual(2, count($result2));
    }

    // =========================================================================
    // getOverlapText Tests
    // =========================================================================

    public function test_get_overlap_text_short_chunk_returns_full_text(): void
    {
        $result = $this->callPrivate('getOverlapText', 'short', 100);
        $this->assertEquals('short', $result);
    }

    public function test_get_overlap_text_extracts_from_end(): void
    {
        $chunk = 'This is the beginning. This is the end portion of the chunk.';
        $result = $this->callPrivate('getOverlapText', $chunk, 30);

        // Should be from the tail of the chunk
        $this->assertLessThanOrEqual(30, strlen($result));
        $this->assertStringContainsString('chunk', $result);
    }

    public function test_get_overlap_text_tries_sentence_boundary(): void
    {
        $chunk = 'First sentence here. Second sentence starts here and continues on for a while with more words.';
        // Get overlap from the last 60 chars — should try to break at sentence boundary
        $result = $this->callPrivate('getOverlapText', $chunk, 60);
        $this->assertNotEmpty($result);
    }

    // =========================================================================
    // meanPoolEmbeddings Tests
    // =========================================================================

    public function test_mean_pool_embeddings_single_vector_returns_unchanged(): void
    {
        $embedding = [0.5, 0.3, -0.2, 0.8];
        $result = $this->callPrivate('meanPoolEmbeddings', [$embedding]);
        $this->assertEquals($embedding, $result);
    }

    public function test_mean_pool_embeddings_averages_two_vectors(): void
    {
        $e1 = [1.0, 0.0, 0.0];
        $e2 = [0.0, 1.0, 0.0];

        $result = $this->callPrivate('meanPoolEmbeddings', [$e1, $e2]);

        // Mean: [0.5, 0.5, 0.0], then L2 normalize
        // L2 norm = sqrt(0.25 + 0.25 + 0) = sqrt(0.5) ~= 0.7071
        $expectedNorm = sqrt(0.5);
        $this->assertEqualsWithDelta(0.5 / $expectedNorm, $result[0], 0.0001);
        $this->assertEqualsWithDelta(0.5 / $expectedNorm, $result[1], 0.0001);
        $this->assertEqualsWithDelta(0.0, $result[2], 0.0001);
    }

    public function test_mean_pool_embeddings_result_is_l2_normalized(): void
    {
        $e1 = [0.3, 0.4, 0.5];
        $e2 = [0.6, 0.1, 0.2];
        $e3 = [0.1, 0.7, 0.3];

        $result = $this->callPrivate('meanPoolEmbeddings', [$e1, $e2, $e3]);

        // L2 norm of result should be ~1.0
        $norm = 0.0;
        foreach ($result as $val) {
            $norm += $val * $val;
        }
        $this->assertEqualsWithDelta(1.0, sqrt($norm), 0.0001);
    }

    public function test_mean_pool_embeddings_preserves_dimensionality(): void
    {
        $dims = 768;
        $e1 = array_fill(0, $dims, 0.01);
        $e2 = array_fill(0, $dims, 0.02);

        $result = $this->callPrivate('meanPoolEmbeddings', [$e1, $e2]);
        $this->assertCount($dims, $result);
    }

    public function test_mean_pool_embeddings_handles_zero_vectors(): void
    {
        // Two zero vectors — norm will be 0, should not divide by zero
        Log::shouldReceive('warning')->once();

        $e1 = [0.0, 0.0, 0.0];
        $e2 = [0.0, 0.0, 0.0];

        $result = $this->callPrivate('meanPoolEmbeddings', [$e1, $e2]);

        // Should return zero vector without crashing
        $this->assertCount(3, $result);
        $this->assertEquals(0.0, $result[0]);
    }

    // =========================================================================
    // calculateBackoff Tests
    // =========================================================================

    public function test_calculate_backoff_first_attempt_near_initial(): void
    {
        $result = $this->callPrivate('calculateBackoff', 1);

        // INITIAL_BACKOFF_MS = 500, jitter +-25% → range 375-625
        $this->assertGreaterThanOrEqual(375, $result);
        $this->assertLessThanOrEqual(625, $result);
    }

    public function test_calculate_backoff_increases_exponentially(): void
    {
        // Run multiple times and check average trend (jitter makes exact comparison unreliable)
        $attempt1_sum = 0;
        $attempt3_sum = 0;
        $iterations = 50;

        for ($i = 0; $i < $iterations; $i++) {
            $attempt1_sum += $this->callPrivate('calculateBackoff', 1);
            $attempt3_sum += $this->callPrivate('calculateBackoff', 3);
        }

        $avg1 = $attempt1_sum / $iterations;
        $avg3 = $attempt3_sum / $iterations;

        // Attempt 3 base = 500 * 2^2 = 2000, attempt 1 base = 500
        // Average of attempt 3 should be significantly higher
        $this->assertGreaterThan($avg1, $avg3);
    }

    public function test_calculate_backoff_capped_at_max(): void
    {
        // MAX_BACKOFF_MS = 4000
        for ($i = 0; $i < 20; $i++) {
            $result = $this->callPrivate('calculateBackoff', 10);
            $this->assertLessThanOrEqual(4000, $result);
        }
    }

    public function test_calculate_backoff_returns_integer(): void
    {
        $result = $this->callPrivate('calculateBackoff', 1);
        $this->assertIsInt($result);
    }

    public function test_provider_retry_runtime_policy_helpers_use_table_values(): void
    {
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')
            ->byDefault()
            ->andReturnUsing(fn (string $key, mixed $default = null): mixed => $default);
        $config->shouldReceive('get')
            ->with('ai.service_runtime_policy', Mockery::type('array'))
            ->times(4)
            ->andReturn([
                'provider_retry_max_attempts' => 4,
                'provider_retry_initial_backoff_ms' => 250,
                'provider_retry_max_backoff_ms' => 3000,
                'provider_retry_backoff_multiplier' => 3,
            ]);
        $this->app->instance(SystemConfigService::class, $config);

        $this->assertSame(4, $this->callPrivate('providerRetryMaxAttempts'));
        $this->assertSame(250, $this->callPrivate('providerRetryInitialBackoffMs'));
        $this->assertSame(3000, $this->callPrivate('providerRetryMaxBackoffMs'));
        $this->assertSame(3, $this->callPrivate('providerRetryBackoffMultiplier'));
    }

    public function test_calculate_backoff_uses_runtime_policy_values(): void
    {
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')
            ->byDefault()
            ->andReturnUsing(fn (string $key, mixed $default = null): mixed => $default);
        $config->shouldReceive('get')
            ->with('ai.service_runtime_policy', Mockery::type('array'))
            ->andReturn([
                'provider_retry_initial_backoff_ms' => 100,
                'provider_retry_max_backoff_ms' => 1000,
                'provider_retry_backoff_multiplier' => 3,
            ]);
        $this->app->instance(SystemConfigService::class, $config);

        $result = $this->callPrivate('calculateBackoff', 2);

        // Attempt 2 base = 100 * 3, jitter +-25%.
        $this->assertGreaterThanOrEqual(225, $result);
        $this->assertLessThanOrEqual(375, $result);
    }

    // =========================================================================
    // isModelMatch Tests
    // =========================================================================

    public function test_is_model_match_exact_match(): void
    {
        $result = $this->callPrivate('isModelMatch', 'llama3.1:8b-instruct-q5_K_M', 'llama3.1:8b-instruct-q5_K_M');
        $this->assertTrue($result);
    }

    public function test_is_model_match_same_base_different_tag(): void
    {
        $result = $this->callPrivate('isModelMatch', 'llama3.1:8b-instruct-q5_K_M', 'llama3.1:latest');
        $this->assertTrue($result);
    }

    public function test_is_model_match_different_models(): void
    {
        $result = $this->callPrivate('isModelMatch', 'llama3.1:8b', 'mistral:7b');
        $this->assertFalse($result);
    }

    public function test_is_model_match_null_current_returns_false(): void
    {
        $result = $this->callPrivate('isModelMatch', null, 'llama3.1:8b');
        $this->assertFalse($result);
    }

    public function test_is_model_match_no_tag_separator(): void
    {
        $result = $this->callPrivate('isModelMatch', 'nomic-embed-text', 'nomic-embed-text');
        $this->assertTrue($result);
    }

    // =========================================================================
    // getEmbeddingContextLimit Tests
    // =========================================================================

    public function test_get_embedding_context_limit_returns_first_available(): void
    {
        $instances = [
            ['embedding_context_length' => 2048],
            ['embedding_context_length' => 4096],
        ];
        $result = $this->callPrivate('getEmbeddingContextLimit', $instances);
        $this->assertEquals(2048, $result);
    }

    public function test_get_embedding_context_limit_falls_back_to_context_length(): void
    {
        $instances = [
            ['context_length' => 4096],
        ];
        $result = $this->callPrivate('getEmbeddingContextLimit', $instances);
        $this->assertEquals(4096, $result);
    }

    public function test_get_embedding_context_limit_returns_default_8192_when_empty(): void
    {
        $result = $this->callPrivate('getEmbeddingContextLimit', []);
        $this->assertEquals(8192, $result);
    }

    public function test_get_embedding_context_limit_skips_null_entries(): void
    {
        $instances = [
            ['other_field' => 100],
            ['embedding_context_length' => 512],
        ];
        $result = $this->callPrivate('getEmbeddingContextLimit', $instances);
        $this->assertEquals(512, $result);
    }

    public function test_order_embedding_providers_preserves_pool_ranked_ollama_order_before_external(): void
    {
        $providers = [
            ['id' => 'external_b', 'instance_type' => 'openai', 'priority' => 20],
            ['id' => 'ollama_primary', 'instance_type' => 'ollama', 'priority' => 11, 'health_score' => 0.60],
            ['id' => 'external_a', 'instance_type' => 'openai', 'priority' => 10],
            ['id' => 'ollama_secondary', 'instance_type' => 'ollama', 'priority' => 5, 'health_score' => 1.00],
        ];

        $ordered = $this->callPrivate('orderEmbeddingProviders', $providers);

        $this->assertSame(
            ['ollama_primary', 'ollama_secondary', 'external_a', 'external_b'],
            array_column($ordered, 'id')
        );
    }

    public function test_order_embedding_providers_does_not_erase_table_owned_ollama_order(): void
    {
        $providers = [
            ['id' => 'ollama_b', 'instance_type' => 'ollama', 'priority' => 5, 'health_score' => 0.50],
            ['id' => 'ollama_a', 'instance_type' => 'ollama', 'priority' => 5, 'health_score' => 0.95],
        ];

        $ordered = $this->callPrivate('orderEmbeddingProviders', $providers);

        $this->assertSame(['ollama_b', 'ollama_a'], array_column($ordered, 'id'));
    }

    public function test_generate_embedding_supports_disabling_cpu_fallback(): void
    {
        $reflection = new \ReflectionClass(AIService::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('array $options = []', $source);
        $this->assertStringContainsString("'allow_cpu_fallback'", $source);
        $this->assertStringContainsString('CPU fallback disabled for this request', $source);
        $this->assertStringContainsString('$circuitState === \'closed\'', $source);
    }

    // =========================================================================
    // AIExceptionFactory::fromMessage Tests
    // These tests do NOT require AIService instantiation.
    // =========================================================================

    public function test_factory_from_message_timeout(): void
    {
        $ex = AIExceptionFactory::fromMessage('Request timed out after 120s', 'ollama');
        $this->assertInstanceOf(TimeoutException::class, $ex);
        $this->assertInstanceOf(TransientException::class, $ex);
        $this->assertTrue($ex->isRetryable());
    }

    public function test_factory_from_message_connection_refused(): void
    {
        $ex = AIExceptionFactory::fromMessage('Connection refused at port 11434', 'ollama');
        $this->assertInstanceOf(ConnectionException::class, $ex);
        $this->assertTrue($ex->isRetryable());
    }

    public function test_factory_from_message_connection_reset(): void
    {
        $ex = AIExceptionFactory::fromMessage('Connection reset by peer', 'ollama');
        $this->assertInstanceOf(ConnectionException::class, $ex);
        $this->assertTrue($ex->isRetryable());
    }

    public function test_factory_from_message_could_not_resolve(): void
    {
        $ex = AIExceptionFactory::fromMessage('Could not resolve host', 'ollama');
        $this->assertInstanceOf(ConnectionException::class, $ex);
        $this->assertTrue($ex->isRetryable());
    }

    public function test_factory_from_message_busy(): void
    {
        $ex = AIExceptionFactory::fromMessage('Server is busy processing another request', 'ollama');
        $this->assertInstanceOf(BusyException::class, $ex);
        $this->assertTrue($ex->isRetryable());
    }

    public function test_factory_from_message_lock(): void
    {
        $ex = AIExceptionFactory::fromMessage('Could not acquire lock for model', 'ollama');
        $this->assertInstanceOf(BusyException::class, $ex);
        $this->assertTrue($ex->isRetryable());
    }

    public function test_factory_from_message_rate_limit(): void
    {
        $ex = AIExceptionFactory::fromMessage('Rate limit exceeded, try again later', 'groq');
        $this->assertInstanceOf(RateLimitException::class, $ex);
        $this->assertTrue($ex->isRetryable());
        $this->assertEquals(429, $ex->getHttpStatus());
    }

    public function test_factory_from_message_429_in_message(): void
    {
        $ex = AIExceptionFactory::fromMessage('HTTP 429 Too Many Requests', 'openrouter');
        $this->assertInstanceOf(RateLimitException::class, $ex);
        $this->assertTrue($ex->isRetryable());
    }

    public function test_factory_from_message_503_overload(): void
    {
        $ex = AIExceptionFactory::fromMessage('Server returned 503 overloaded', 'sambanova');
        $this->assertInstanceOf(ServerOverloadException::class, $ex);
        $this->assertTrue($ex->isRetryable());
    }

    public function test_factory_from_message_unavailable(): void
    {
        $ex = AIExceptionFactory::fromMessage('Service unavailable', 'cerebras');
        $this->assertInstanceOf(ServerOverloadException::class, $ex);
        $this->assertTrue($ex->isRetryable());
    }

    public function test_factory_from_message_content_policy(): void
    {
        $ex = AIExceptionFactory::fromMessage('Content policy violation detected', 'openrouter');
        $this->assertInstanceOf(ContentPolicyException::class, $ex);
        $this->assertInstanceOf(PermanentException::class, $ex);
        $this->assertFalse($ex->isRetryable());
    }

    public function test_factory_from_message_content_blocked(): void
    {
        // "content blocked" now correctly routes to ContentPolicyException
        // (factory reordered: content policy/blocked checked before busy/lock)
        $ex = AIExceptionFactory::fromMessage('Content blocked by safety filter', 'gemini');
        $this->assertInstanceOf(ContentPolicyException::class, $ex);
        $this->assertFalse($ex->isRetryable());

        $ex2 = AIExceptionFactory::fromMessage('Content policy: unsafe content detected', 'gemini');
        $this->assertInstanceOf(ContentPolicyException::class, $ex2);
        $this->assertFalse($ex2->isRetryable());
    }

    public function test_factory_from_message_context_length(): void
    {
        $ex = AIExceptionFactory::fromMessage('Context length exceeded, max 8192 tokens', 'ollama');
        $this->assertInstanceOf(ContextLengthException::class, $ex);
        $this->assertFalse($ex->isRetryable());
    }

    public function test_factory_from_message_too_long(): void
    {
        $ex = AIExceptionFactory::fromMessage('Input too long for this model', 'groq');
        $this->assertInstanceOf(ContextLengthException::class, $ex);
        $this->assertFalse($ex->isRetryable());
    }

    public function test_factory_from_message_token_limit(): void
    {
        $ex = AIExceptionFactory::fromMessage('Token limit reached for this request', 'groq');
        $this->assertInstanceOf(ContextLengthException::class, $ex);
        $this->assertFalse($ex->isRetryable());
    }

    public function test_factory_from_message_authentication(): void
    {
        $ex = AIExceptionFactory::fromMessage('Authentication failed: invalid API key', 'gemini');
        $this->assertInstanceOf(AuthenticationException::class, $ex);
        $this->assertFalse($ex->isRetryable());
    }

    public function test_factory_from_message_api_key(): void
    {
        $ex = AIExceptionFactory::fromMessage('Invalid API key provided', 'openrouter');
        $this->assertInstanceOf(AuthenticationException::class, $ex);
        $this->assertFalse($ex->isRetryable());
    }

    public function test_factory_from_message_unauthorized(): void
    {
        $ex = AIExceptionFactory::fromMessage('Unauthorized access', 'openrouter');
        $this->assertInstanceOf(AuthenticationException::class, $ex);
        $this->assertFalse($ex->isRetryable());
    }

    public function test_factory_from_message_model_not_found(): void
    {
        $ex = AIExceptionFactory::fromMessage('Model not found: llama99:999b', 'ollama');
        $this->assertInstanceOf(ModelNotFoundException::class, $ex);
        $this->assertFalse($ex->isRetryable());
    }

    public function test_factory_from_message_unknown_model(): void
    {
        $ex = AIExceptionFactory::fromMessage('Unknown model requested', 'ollama');
        $this->assertInstanceOf(ModelNotFoundException::class, $ex);
        $this->assertFalse($ex->isRetryable());
    }

    public function test_factory_from_message_malformed_utf8(): void
    {
        $ex = AIExceptionFactory::fromMessage('Malformed UTF-8 in request body', 'ollama');
        $this->assertInstanceOf(ValidationException::class, $ex);
        $this->assertFalse($ex->isRetryable());
    }

    public function test_factory_from_message_json_encode_error(): void
    {
        $ex = AIExceptionFactory::fromMessage('json_encode error on input data', 'ollama');
        $this->assertInstanceOf(ValidationException::class, $ex);
        $this->assertFalse($ex->isRetryable());
    }

    public function test_factory_from_message_unknown_defaults_to_base(): void
    {
        $ex = AIExceptionFactory::fromMessage('Something completely unexpected happened', 'unknown_provider');
        $this->assertInstanceOf(AIServiceException::class, $ex);
        $this->assertFalse($ex->isRetryable());
    }

    public function test_factory_from_message_preserves_provider(): void
    {
        $ex = AIExceptionFactory::fromMessage('Timed out', 'ollama_primary');
        $this->assertEquals('ollama_primary', $ex->getProvider());
    }

    public function test_factory_from_message_preserves_model(): void
    {
        $ex = AIExceptionFactory::fromMessage('Timed out', 'ollama', 'llama3.1:8b');
        $this->assertEquals('llama3.1:8b', $ex->getModel());
    }

    public function test_factory_from_message_preserves_previous_exception(): void
    {
        $prev = new \RuntimeException('original error');
        $ex = AIExceptionFactory::fromMessage('Connection refused', 'ollama', null, $prev);
        $this->assertSame($prev, $ex->getPrevious());
    }

    public function test_factory_from_message_case_insensitive(): void
    {
        $ex = AIExceptionFactory::fromMessage('REQUEST TIMED OUT', 'ollama');
        $this->assertInstanceOf(TimeoutException::class, $ex);
    }

    // =========================================================================
    // AIServiceException::toArray Tests
    // =========================================================================

    public function test_exception_to_array_contains_all_fields(): void
    {
        $ex = new AIServiceException('Test error', 'ollama', 'llama3.1:8b', 500, true, 2000);
        $arr = $ex->toArray();

        $this->assertArrayHasKey('type', $arr);
        $this->assertArrayHasKey('message', $arr);
        $this->assertArrayHasKey('provider', $arr);
        $this->assertArrayHasKey('model', $arr);
        $this->assertArrayHasKey('http_status', $arr);
        $this->assertArrayHasKey('retryable', $arr);
        $this->assertArrayHasKey('suggested_backoff_ms', $arr);

        $this->assertEquals('Test error', $arr['message']);
        $this->assertEquals('ollama', $arr['provider']);
        $this->assertEquals('llama3.1:8b', $arr['model']);
        $this->assertEquals(500, $arr['http_status']);
        $this->assertTrue($arr['retryable']);
        $this->assertEquals(2000, $arr['suggested_backoff_ms']);
    }

    // =========================================================================
    // Transient vs Permanent Classification
    // =========================================================================

    public function test_transient_exceptions_are_retryable(): void
    {
        $transientTypes = [
            TimeoutException::class,
            ConnectionException::class,
            ServerOverloadException::class,
            BusyException::class,
            RateLimitException::class,
        ];

        foreach ($transientTypes as $type) {
            $ex = new $type('test', 'provider');
            $this->assertTrue($ex->isRetryable(), "{$type} should be retryable");
            $this->assertInstanceOf(TransientException::class, $ex);
        }
    }

    public function test_permanent_exceptions_are_not_retryable(): void
    {
        $permanentTypes = [
            ValidationException::class,
            AuthenticationException::class,
            ContentPolicyException::class,
            ContextLengthException::class,
            ModelNotFoundException::class,
        ];

        foreach ($permanentTypes as $type) {
            $ex = new $type('test', 'provider');
            $this->assertFalse($ex->isRetryable(), "{$type} should NOT be retryable");
            $this->assertInstanceOf(PermanentException::class, $ex);
        }
    }

    // =========================================================================
    // RateLimitException backoff defaults
    // =========================================================================

    public function test_rate_limit_exception_default_backoff_30s(): void
    {
        $ex = new RateLimitException('rate limited', 'groq');
        $this->assertEquals(30000, $ex->getSuggestedBackoffMs());
    }

    public function test_rate_limit_exception_custom_backoff(): void
    {
        $ex = new RateLimitException('rate limited', 'groq', null, 429, 60000);
        $this->assertEquals(60000, $ex->getSuggestedBackoffMs());
    }
}
