<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Exceptions\ModelHostControllerUnavailableException;
use App\Services\AIService;
use App\Services\CascadeQualityEvaluator;
use Mockery;
use Tests\TestCase;

/**
 * AI-1: CascadeQualityEvaluator unit tests
 *
 * Pure signal logic — no DB, no AI. Self-assess path skipped (requires _ai_service).
 */
class CascadeQualityEvaluatorTest extends TestCase
{
    public function test_optional_self_assessment_propagates_original_and_wrapped_admission_stops(): void
    {
        $admission = new InferenceAdmissionException('Held synthetic inference');
        $unknown = new ModelHostControllerUnavailableException('unknown', dispatched: true);
        foreach ([$admission, new \RuntimeException('wrapper', 0, $admission), $unknown,
            new \RuntimeException('wrapper', 0, $unknown)] as $stop) {
            $ai = Mockery::mock(AIService::class);
            $ai->shouldReceive('process')->once()->andThrow($stop);
            try {
                $this->evaluator->evaluate('Complete task', 'Complete response', ['cascade' => ['self_assess' => true], '_ai_service' => $ai]);
                $this->fail('An uncertain quality call was silently omitted.');
            } catch (\Throwable $error) {
                $this->assertSame($stop, $error);
            }
        }
    }

    public function test_optional_self_assessment_sees_complete_task_and_response(): void
    {
        $prompt = str_repeat('Task context. ', 50).'CRITICAL TASK TAIL';
        $response = str_repeat('Answer evidence. ', 80).'CRITICAL ANSWER TAIL';
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('process')->once()->with(Mockery::on(fn ($request) => str_contains($request, 'TASK: '.$prompt."\n\nRESPONSE: ".$response)),
            Mockery::on(fn ($options) => $options['_cascade_attempt'] === true && $options['max_tokens'] === 5))
            ->andReturn(['success' => true, 'response' => '10']);
        $result = $this->evaluator->evaluate($prompt, $response, ['cascade' => ['self_assess' => true], '_ai_service' => $ai]);
        $this->assertSame(1.0, $result['signals']['self_assess']);
    }

    public function test_failed_complete_self_assessment_keeps_other_signals(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('process')->once()->andReturn(['success' => false]);
        $result = $this->evaluator->evaluate('Complete task', 'Complete answer', ['cascade' => ['self_assess' => true], '_ai_service' => $ai]);
        $this->assertArrayNotHasKey('self_assess', $result['signals']);
        $this->assertArrayHasKey('length', $result['signals']);
    }

    private CascadeQualityEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new CascadeQualityEvaluator;
    }

    // =========================================================================
    // Return structure
    // =========================================================================

    public function test_evaluate_returns_required_keys(): void
    {
        $result = $this->evaluator->evaluate('prompt', 'response text here', []);
        $this->assertArrayHasKey('escalate', $result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertArrayHasKey('signals', $result);
        $this->assertIsBool($result['escalate']);
        $this->assertIsFloat($result['score']);
    }

    // =========================================================================
    // Length signal
    // =========================================================================

    public function test_empty_response_scores_zero_for_length(): void
    {
        $result = $this->evaluator->evaluate('tell me something', '', []);
        $this->assertSame(0.0, $result['signals']['length']);
    }

    public function test_response_at_min_length_scores_full_for_length(): void
    {
        $result = $this->evaluator->evaluate('describe it', str_repeat('a', 80), []);
        $this->assertSame(1.0, $result['signals']['length']);
    }

    public function test_response_above_min_length_scores_full_for_length(): void
    {
        $result = $this->evaluator->evaluate('describe it', str_repeat('word ', 30), []);
        $this->assertSame(1.0, $result['signals']['length']);
    }

    public function test_short_response_gets_partial_length_credit(): void
    {
        // 40 chars → 40/80 = 0.5
        $result = $this->evaluator->evaluate('describe it', str_repeat('x', 40), []);
        $this->assertEqualsWithDelta(0.5, $result['signals']['length'], 0.001);
    }

    public function test_min_length_override_via_config(): void
    {
        // 40 chars; with min=40 → full credit
        $result = $this->evaluator->evaluate(
            'describe it',
            str_repeat('x', 40),
            ['cascade' => ['min_response_length' => 40]]
        );
        $this->assertSame(1.0, $result['signals']['length']);
    }

    // =========================================================================
    // Refusal signal
    // =========================================================================

    public function test_clean_response_scores_full_for_refusal(): void
    {
        $response = str_repeat('Here is a helpful answer about your query. ', 4);
        $result = $this->evaluator->evaluate('help me', $response, []);
        $this->assertSame(1.0, $result['signals']['refusal']);
    }

    public function test_i_cannot_pattern_scores_zero_for_refusal(): void
    {
        $result = $this->evaluator->evaluate('help', 'I cannot do that.', []);
        $this->assertSame(0.0, $result['signals']['refusal']);
    }

    public function test_as_an_ai_pattern_scores_zero_for_refusal(): void
    {
        $result = $this->evaluator->evaluate('help', 'As an AI, I do not have the ability to perform this task.', []);
        $this->assertSame(0.0, $result['signals']['refusal']);
    }

    public function test_i_am_unable_pattern_scores_zero_for_refusal(): void
    {
        $result = $this->evaluator->evaluate('help', 'I am unable to provide information on that.', []);
        $this->assertSame(0.0, $result['signals']['refusal']);
    }

    // =========================================================================
    // Repetition signal
    // =========================================================================

    public function test_short_response_under_20_words_skips_repetition_check(): void
    {
        $result = $this->evaluator->evaluate('what is it', 'A brief clear answer here.', []);
        $this->assertSame(1.0, $result['signals']['repetition']);
    }

    public function test_diverse_response_scores_full_for_repetition(): void
    {
        $response = 'The quick brown fox jumps over the lazy dog. Birds fly south for winter. '
                  .'Scientists discovered ancient fossils beneath the ocean floor last spring.';
        $result = $this->evaluator->evaluate('describe', $response, []);
        $this->assertSame(1.0, $result['signals']['repetition']);
    }

    public function test_highly_repetitive_response_scores_low_for_repetition(): void
    {
        // 40 words with 4-word cycle → all trigrams repeat, score → 0.0
        $response = str_repeat('the quick brown fox ', 10);
        $result = $this->evaluator->evaluate('what happened', $response, []);
        $this->assertLessThan(0.5, $result['signals']['repetition']);
    }

    // =========================================================================
    // JSON validity signal
    // =========================================================================

    public function test_json_signal_absent_for_plain_prompt(): void
    {
        $result = $this->evaluator->evaluate('tell me about dogs', 'Dogs are friendly.', []);
        $this->assertArrayNotHasKey('json_validity', $result['signals']);
    }

    public function test_json_signal_present_when_prompt_contains_respond_with_json(): void
    {
        $result = $this->evaluator->evaluate('Please respond with JSON', str_repeat('x', 80), []);
        $this->assertArrayHasKey('json_validity', $result['signals']);
    }

    public function test_json_signal_present_via_expect_json_config(): void
    {
        $result = $this->evaluator->evaluate('describe it', str_repeat('x', 80), ['expect_json' => true]);
        $this->assertArrayHasKey('json_validity', $result['signals']);
    }

    public function test_valid_json_object_scores_full_json_validity(): void
    {
        $response = '{"name": "John Smith", "age": 42, "city": "Boston"}';
        $result = $this->evaluator->evaluate('Return JSON', $response, ['expect_json' => true]);
        $this->assertSame(1.0, $result['signals']['json_validity']);
    }

    public function test_valid_json_array_scores_full_json_validity(): void
    {
        $response = '[{"id": 1, "name": "Alice"}, {"id": 2, "name": "Bob"}]';
        $result = $this->evaluator->evaluate('Return JSON', $response, ['expect_json' => true]);
        $this->assertSame(1.0, $result['signals']['json_validity']);
    }

    public function test_invalid_json_scores_zero_json_validity(): void
    {
        $response = str_repeat('not valid json broken response here ', 3);
        $result = $this->evaluator->evaluate('Return JSON', $response, ['expect_json' => true]);
        $this->assertSame(0.0, $result['signals']['json_validity']);
    }

    public function test_json_in_markdown_fence_scores_full_json_validity(): void
    {
        $response = "```json\n{\"key\": \"value\", \"count\": 5}\n```";
        $result = $this->evaluator->evaluate('output as json', $response, ['expect_json' => true]);
        $this->assertSame(1.0, $result['signals']['json_validity']);
    }

    // =========================================================================
    // Escalation decision
    // =========================================================================

    public function test_good_response_does_not_escalate(): void
    {
        $response = 'The historical census records from 1880 show that John Smith was born in Ohio. '
                  .'His wife Mary was from Pennsylvania and they had three children listed.';
        $result = $this->evaluator->evaluate('describe the person', $response, []);
        $this->assertFalse($result['escalate']);
        $this->assertSame('quality acceptable', $result['reason']);
    }

    public function test_short_refusal_escalates(): void
    {
        // Short length + zero refusal score pushes aggregate below 0.5
        $result = $this->evaluator->evaluate('help me', 'I cannot.', []);
        $this->assertTrue($result['escalate']);
        $this->assertNotSame('quality acceptable', $result['reason']);
    }

    public function test_threshold_override_forces_escalation_on_short_response(): void
    {
        // "Noted." — 6 chars, no refusal, no repetition → score ~0.67 (passes 0.50, fails 0.99)
        $response = 'Noted.';

        // Default threshold (0.50): does not escalate
        $normal = $this->evaluator->evaluate('acknowledge receipt', $response, []);
        $this->assertFalse($normal['escalate']);

        // Threshold 0.99: forces escalation because length is too short
        $strict = $this->evaluator->evaluate('acknowledge receipt', $response, ['cascade' => ['threshold' => 0.99]]);
        $this->assertTrue($strict['escalate']);
    }

    public function test_score_is_between_zero_and_one(): void
    {
        $result = $this->evaluator->evaluate('anything', 'anything', []);
        $this->assertGreaterThanOrEqual(0.0, $result['score']);
        $this->assertLessThanOrEqual(1.0, $result['score']);
    }
}
