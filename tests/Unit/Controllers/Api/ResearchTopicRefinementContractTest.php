<?php

namespace Tests\Unit\Controllers\Api;

use App\Http\Controllers\Api\ResearchTopicController;
use App\Services\AIService;
use App\Services\RAGService;
use Illuminate\Http\Request;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ResearchTopicRefinementContractTest extends TestCase
{
    public function test_prompt_batches_only_the_current_independent_decision_frontier(): void
    {
        $prompt = $this->invokePrivate(
            $this->controller(),
            'buildRefineSystemPrompt',
            [false, null]
        );

        $this->assertStringContainsString('1 to 3 concise numbered questions', $prompt);
        $this->assertStringContainsString('answers do not depend on each other', $prompt);
        $this->assertStringContainsString('defer it to the next round', $prompt);
        $this->assertStringContainsString('Never ask for a fact already present', $prompt);
        $this->assertStringContainsString('at most 2 clarification rounds', $prompt);
        $this->assertStringNotContainsString('Ask exactly ONE question per response', $prompt);
    }

    public function test_second_answered_frontier_forces_an_editable_proposal(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('process')
            ->once()
            ->withArgs(function (string $prompt, array $options): bool {
                $this->assertStringContainsString('The human wants to finalize', $prompt);
                $this->assertSame(false, $options['use_cache']);

                return true;
            })
            ->andReturn([
                'success' => true,
                'provider' => 'test-provider',
                'response' => <<<'RESPONSE'
```json
{
  "description": "Battery recycling policy",
  "topic_content": "## Primary Question\nTrack policy changes.\n\n## Sub-questions\n1. Which incentives changed?\n\n## Context & Constraints\nUnited States.\n\n## Success Criteria\nSource-backed comparison.",
  "frequency": "weekly",
  "rag_category": "technology",
  "search_depth": 4,
  "max_sources": 15,
  "date_filter_days": 30,
  "require_recent_only": true
}
```
Ready for operator editing.
RESPONSE,
            ]);
        $this->app->instance(AIService::class, $ai);

        $request = Request::create('/api/research-topics/refine', 'POST', [
            'raw_idea' => 'Track battery recycling policy.',
            'conversation' => [
                ['role' => 'user', 'content' => 'Track battery recycling policy.'],
                ['role' => 'assistant', 'content' => "1. Which region?\n2. What decision should this support?"],
                ['role' => 'user', 'content' => 'United States; investment planning.'],
                ['role' => 'assistant', 'content' => '1. How current should sources be?'],
                ['role' => 'user', 'content' => 'Thirty days, with weekly updates.'],
            ],
        ]);

        $response = $this->controller()->refine($request);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('Battery recycling policy', $payload['proposal']['description']);
        $this->assertSame('plos.research_topic_refinement.v1', $payload['refinement']['schema']);
        $this->assertSame('proposal_ready', $payload['refinement']['state']);
        $this->assertSame('dependency_aware_frontier_batch', $payload['refinement']['mode']);
        $this->assertSame(3, $payload['refinement']['max_questions_per_round']);
        $this->assertSame(2, $payload['refinement']['max_clarification_rounds']);
        $this->assertSame(2, $payload['refinement']['clarification_rounds_completed']);
        $this->assertTrue($payload['refinement']['operator_can_finalize_anytime']);
        $this->assertFalse($payload['refinement']['asks_known_facts']);
        $this->assertFalse($payload['refinement']['changes_model_route_or_tool_authority']);
    }

    private function controller(): ResearchTopicController
    {
        return new ResearchTopicController(Mockery::mock(RAGService::class));
    }

    private function invokePrivate(object $target, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($target, $method);

        return $reflection->invokeArgs($target, $arguments);
    }
}
