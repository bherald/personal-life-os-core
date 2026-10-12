<?php

namespace Tests\Unit\Services;

use App\DTOs\PolicyDecision;
use App\Services\CircuitBreaker;
use App\Services\OfflinePolicyService;
use App\Services\SkillLoaderService;
use App\Services\SystemConfigService;
use App\Services\TypeSafeJevDecisionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class TypeSafeJevDecisionServiceTest extends TestCase
{
    private const API_KEY = 'test-typesafe-key';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('TYPESAFE_API_KEY='.self::API_KEY);
        $_ENV['TYPESAFE_API_KEY'] = self::API_KEY;
    }

    protected function tearDown(): void
    {
        putenv('TYPESAFE_API_KEY');
        unset($_ENV['TYPESAFE_API_KEY']);
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_sends_the_typed_system_one_contract_and_returns_validated_answers(): void
    {
        $questions = [
            'suggest_skill' => ['type' => 'choice', 'instructions' => 'Choose the best matching skill.', 'criteria' => [
                'plos_chores' => 'PLOS maintenance and work tracking',
                'genealogy' => 'Genealogy source research',
            ]],
            'is_urgent' => ['type' => 'noul', 'instructions' => 'Is this due today?'],
            'priority' => ['type' => 'score', 'instructions' => 'Rate priority.', 'criteria' => ['low', 'medium', 'high']],
        ];
        Http::fake([
            'https://api.typesafe.ai/v1/systemone' => Http::response([
                'model' => 'jev-1.13.0',
                'answers' => [
                    'suggest_skill' => ['type' => 'choice', 'choice' => 'plos_chores', 'probabilities' => ['plos_chores' => 0.9, 'genealogy' => 0.1], 'confidence' => 0.9],
                    'is_urgent' => ['type' => 'noul', 'noul' => 0.2],
                    'priority' => ['type' => 'score', 'score' => 2, 'legend' => ['low', 'medium', 'high'], 'probabilities' => [0 => 0.05, 1 => 0.15, 2 => 0.8], 'confidence' => 0.8],
                ],
                'usage' => ['input_tokens' => 61, 'output_tokens' => 14],
            ]),
        ]);
        $this->bindServices();

        $result = app(TypeSafeJevDecisionService::class)->evaluate(
            'cli_task_skill_selection', 'sanitized_internal', ['request' => 'Review this sanitized work item.'], $questions
        );

        $this->assertSame('completed', $result['status']);
        $this->assertSame('plos_chores', $result['answers']['suggest_skill']['choice']);
        $this->assertSame(0.2, $result['answers']['is_urgent']['noul']);
        $this->assertSame(2, $result['answers']['priority']['score']);
        $this->assertSame(['input_tokens' => 61, 'output_tokens' => 14], $result['usage']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.typesafe.ai/v1/systemone'
            && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY)
            && $request['model'] === 'jev-1.13.0'
            && $request['state']['request'] === 'Review this sanitized work item.'
            && $request['questions'] === $questions);
    }

    public function test_offline_mode_skips_without_making_a_provider_request(): void
    {
        Http::preventStrayRequests();
        $this->bindServices(offline: true);

        $result = app(TypeSafeJevDecisionService::class)->evaluate(
            'todo_triage', 'public', ['item' => 'example'], [
                'is_actionable' => ['type' => 'noul', 'instructions' => 'Can this item be acted on?'],
            ]
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('offline_mode', $result['reason_code']);
        $this->assertSame('continue_existing_route', $result['fallback']);
    }

    public function test_malformed_provider_answers_are_skipped_after_a_bounded_retry(): void
    {
        Http::fakeSequence('https://api.typesafe.ai/v1/systemone')
            ->pushStatus(529)
            ->push(['model' => 'jev-1.13.0', 'answers' => [], 'usage' => ['input_tokens' => 4, 'output_tokens' => 1]]);
        $this->bindServices();

        $result = app(TypeSafeJevDecisionService::class)->evaluate(
            'evidence_relevance', 'public', ['evidence' => ['source' => 's1']], [
                'relevant' => ['type' => 'noul', 'instructions' => 'Is this evidence relevant?'],
            ]
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('invalid_provider_result', $result['reason_code']);
        Http::assertSentCount(2);
    }

    public function test_provider_http_failure_returns_the_existing_route_fallback(): void
    {
        Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response(['message' => 'private response text'], 503)]);
        $this->bindServices();

        $result = app(TypeSafeJevDecisionService::class)->evaluate(
            'todo_triage', 'public', ['item' => 'example'], [
                'actionable' => ['type' => 'noul', 'instructions' => 'Can an operator act on this item?'],
            ]
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('provider_http_error', $result['reason_code']);
        $this->assertSame('continue_existing_route', $result['fallback']);
        $this->assertArrayNotHasKey('message', $result);
        Http::assertSentCount(2);
    }

    #[DataProvider('transientHttpStatuses')]
    public function test_transient_http_failures_can_recover_within_the_retry_budget(int $status): void
    {
        Http::fakeSequence('https://api.typesafe.ai/v1/systemone')
            ->pushStatus($status)
            ->push($this->validNoulResponse());
        $this->bindServices();

        $result = $this->evaluateExample();

        $this->assertSame('completed', $result['status']);
        Http::assertSentCount(2);
    }

    public static function transientHttpStatuses(): array
    {
        return array_map(fn (int $status): array => [$status], [408, 429, 500, 502, 503, 504, 529]);
    }

    public function test_connection_failure_can_recover_within_the_retry_budget(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new ConnectionException('Synthetic connection failure');
            }

            return Http::response($this->validNoulResponse());
        });
        $this->bindServices();

        $this->assertSame('completed', $this->evaluateExample()['status']);
        $this->assertSame(2, $attempts);
    }

    public function test_persistent_connection_failure_stops_after_the_retry_budget(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Synthetic connection failure');
        });
        $this->bindServices();

        $result = $this->evaluateExample();

        $this->assertSame('provider_unavailable', $result['reason_code']);
        $this->assertSame('continue_existing_route', $result['fallback']);
        $this->assertSame(2, $attempts);
    }

    public function test_policy_can_disable_retries(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->bindServices(overrides: ['max_retries' => 0]);

        $this->assertSame('provider_http_error', $this->evaluateExample()['reason_code']);
        Http::assertSentCount(1);
    }

    #[DataProvider('permanentHttpStatuses')]
    public function test_authentication_and_request_errors_are_not_retried(int $status, string $reason): void
    {
        Http::fake(['*' => Http::response([], $status)]);
        $this->bindServices();

        $this->assertSame($reason, $this->evaluateExample()['reason_code']);
        Http::assertSentCount(1);
    }

    public static function permanentHttpStatuses(): array
    {
        return [[401, 'provider_authentication_failed'], [403, 'provider_authentication_failed'], [422, 'provider_http_error']];
    }

    public function test_provider_redirects_are_not_followed(): void
    {
        Http::fake(function ($request, array $options) {
            $this->assertFalse($options['allow_redirects']);

            return Http::response([], 307, ['Location' => 'https://example.test/collect']);
        });
        $this->bindServices();

        $this->assertSame('provider_http_error', $this->evaluateExample()['reason_code']);
        Http::assertSentCount(1);
    }

    #[DataProvider('malformedPolicies')]
    public function test_malformed_table_policy_skips_without_transport(array $overrides): void
    {
        Http::fake();
        Schema::shouldReceive('hasTable')->andReturnFalse();
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')->once()->andReturn(array_replace_recursive([
            'schema' => 'plos.typesafe_jev_policy.v1', 'enabled' => true,
            'allowed_data_classifications' => ['public'],
            'tasks' => ['todo_triage' => ['enabled' => true, 'allowed_data_classifications' => ['public']]],
        ], $overrides));
        $network = Mockery::mock(OfflinePolicyService::class);
        $network->shouldNotReceive('isOfflineModeActive');
        $breaker = Mockery::mock(CircuitBreaker::class);
        $breaker->shouldNotReceive('call');
        $this->app->instance(TypeSafeJevDecisionService::class, new TypeSafeJevDecisionService($config, $network, $breaker));

        $result = $this->evaluateExample();

        $this->assertSame('task_policy_invalid', $result['reason_code']);
        $this->assertSame('continue_existing_route', $result['fallback']);
        Http::assertNothingSent();
    }

    public static function malformedPolicies(): array
    {
        return [
            [['tasks' => 'invalid']],
            [['allowed_data_classifications' => 'public']],
            [['tasks' => ['todo_triage' => ['allowed_data_classifications' => 'public']]]],
        ];
    }

    private function validNoulResponse(): array
    {
        return ['model' => 'jev-1.13.0', 'answers' => ['actionable' => ['type' => 'noul', 'noul' => 0.8]], 'usage' => ['input_tokens' => 20, 'output_tokens' => 3]];
    }

    private function evaluateExample(): array
    {
        return app(TypeSafeJevDecisionService::class)->evaluate('todo_triage', 'public', ['item' => 'example'], [
            'actionable' => ['type' => 'noul', 'instructions' => 'Can an operator act on this item?'],
        ]);
    }

    public function test_policy_registers_the_cross_cutting_advisory_tasks(): void
    {
        $migration = require base_path('database/migrations/2026_09_21_160000_register_typesafe_jev_decision_tool.php');

        $policy = $migration->policy();
        $tool = $migration->tool();

        $this->assertSame(['public', 'sanitized_internal'], $policy['allowed_data_classifications']);
        $this->assertTrue($policy['enabled']);
        foreach (['cli_task_skill_selection', 'todo_triage', 'social_state_classification', 'evidence_relevance', 'claim_support', 'genealogy_queue_triage', 'pipeline_issue_triage'] as $task) {
            $this->assertTrue($policy['tasks'][$task]['enabled'], $task);
        }
        $this->assertSame('read', $tool['risk_level']);
        $this->assertSame(0, $tool['allows_private_data']);
        foreach (['offline-assistant', 'ai-ops', 'research-analyst', 'genealogy-researcher', 'workflow-ops'] as $skillName) {
            $path = resource_path('agents/skills/'.$skillName.'/SKILL.md');
            $content = file_get_contents($path);
            $parts = preg_split('/^---\s*$/m', $content, 3);
            $frontmatter = Yaml::parse($parts[1]);
            $declaredTools = SkillLoaderService::declaredToolNames($frontmatter);
            $this->assertContains('typesafe_jev_decide', $declaredTools, $skillName);
        }
    }

    public function test_unregistered_task_cannot_transmit_private_data(): void
    {
        Http::fake();
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')->once()->andReturn([
            'schema' => 'plos.typesafe_jev_policy.v1', 'enabled' => true,
            'allowed_data_classifications' => ['private_internal'],
            'tasks' => [],
        ]);
        $network = Mockery::mock(OfflinePolicyService::class);
        $network->shouldNotReceive('isOfflineModeActive');
        $breaker = Mockery::mock(CircuitBreaker::class);
        $breaker->shouldNotReceive('call');
        $service = new TypeSafeJevDecisionService($config, $network, $breaker);
        $result = $service->evaluate('arbitrary_new_task', 'private_internal', ['text' => 'fictional private input'], ['answer' => ['type' => 'noul', 'instructions' => 'Judge it.']]);
        $this->assertSame('task_not_enabled', $result['reason_code']);
        Http::assertNothingSent();
    }

    public function test_private_task_payload_reaches_provider_unchanged(): void
    {
        $migration = require database_path('migrations/2026_09_25_170000_register_typesafe_private_task_policy.php');
        $this->bindServices(overrides: [
            'allowed_data_classifications' => ['private_internal'],
            'tasks' => ['evidence_relevance' => ['allowed_data_classifications' => ['private_internal']]],
            'payload_exclusions' => $migration->exclusions(),
        ]);
        $state = ['name' => 'Fictional Jane Example', 'date' => '1900-01-01', 'source' => 'Full invented source transcript', 'path' => '/private/review/example', 'relationship' => 'daughter'];
        $questions = ['relevant' => ['type' => 'noul', 'instructions' => 'Does the supplied transcript support the relationship?']];
        Http::fake(['*' => Http::response(['model' => 'jev-1.13.0', 'answers' => ['relevant' => ['type' => 'noul', 'noul' => 0.8]], 'usage' => ['input_tokens' => 20, 'output_tokens' => 3]])]);
        $result = app(TypeSafeJevDecisionService::class)->evaluate('evidence_relevance', 'private_internal', $state, $questions);
        $this->assertSame('completed', $result['status']);
        Http::assertSent(fn ($request) => $request['state'] === $state && $request['questions'] === $questions);
    }

    #[DataProvider('excludedPayloads')]
    public function test_auth_material_is_blocked_before_transport(array $state, array $question, ?array $rules = null): void
    {
        $migration = require database_path('migrations/2026_09_25_170000_register_typesafe_private_task_policy.php');
        $this->bindServices(overrides: [
            'allowed_data_classifications' => ['private_internal'],
            'tasks' => ['evidence_relevance' => ['allowed_data_classifications' => ['private_internal']]],
            'payload_exclusions' => $rules ?? $migration->exclusions(),
        ], attempt: false);
        Http::fake();
        $result = app(TypeSafeJevDecisionService::class)->evaluate('evidence_relevance', 'private_internal', $state, ['review' => $question + ['type' => 'noul', 'instructions' => 'Review this source.']]);
        $this->assertSame('skipped', $result['status']);
        $this->assertSame($rules === null ? 'excluded_auth_material' : 'payload_exclusion_policy_invalid', $result['reason_code']);
        Http::assertNothingSent();
    }

    public static function excludedPayloads(): array
    {
        return [
            'nested credential' => [['data' => ['accessToken' => 'fixture']], []],
            'serialized credential' => [['data' => '{"password":"fixture"}'], []],
            'key value config' => [['key' => 'client_secret', 'value' => 'fixture'], []],
            'bearer in text' => [['log' => 'Bearer fixture-token-12345'], []],
            'basic auth' => [['log' => 'Basic ZmljdGlvbmFsOnRlc3Q='], []],
            'key in question' => [[], ['instructions' => 'password=fixture']],
            'private key' => [['source' => '-----BEGIN PRIVATE KEY-----'], []],
            'url credential' => [['url' => 'https://user:password@example.test'], []],
            'cookie' => [['log' => 'Cookie: session=fixture'], []],
            'invalid rule' => [[], [], ['key_pattern' => '[', 'text_patterns' => ['test']]],
        ];
    }

    private function bindServices(bool $offline = false, array $overrides = [], bool $attempt = true): void
    {
        $policy = [
            'schema' => 'plos.typesafe_jev_policy.v1',
            'enabled' => true,
            'endpoint' => 'https://api.typesafe.ai/v1/systemone',
            'model' => 'jev-1.13.0',
            'max_retries' => 1,
            'tasks' => [
                'cli_task_skill_selection' => ['enabled' => true, 'schema_version' => 'v1', 'allowed_data_classifications' => ['public', 'sanitized_internal']],
                'todo_triage' => ['enabled' => true, 'schema_version' => 'v1', 'allowed_data_classifications' => ['public', 'sanitized_internal']],
                'evidence_relevance' => ['enabled' => true, 'schema_version' => 'v1', 'allowed_data_classifications' => ['public', 'sanitized_internal']],
            ],
            'allowed_data_classifications' => ['public', 'sanitized_internal'],
        ];
        $policy = array_replace_recursive($policy, $overrides);
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')->once()->with(TypeSafeJevDecisionService::POLICY_KEY, [])->andReturn($policy);
        $this->app->instance(SystemConfigService::class, $config);

        $network = Mockery::mock(OfflinePolicyService::class);
        $network->shouldReceive('isOfflineModeActive')->once()->andReturn($offline);
        if (! $offline) {
            $network->shouldReceive('isInternetAccessEnabled')->once()->andReturn(true);
            $network->shouldReceive('evaluateRemoteDomain')->once()->with('api.typesafe.ai')
                ->andReturn(PolicyDecision::allow('test', 'default'));
        }
        $this->app->instance(OfflinePolicyService::class, $network);

        $breaker = Mockery::mock(CircuitBreaker::class);
        if (! $offline && $attempt) {
            $breaker->shouldReceive('call')->once()->andReturnUsing(
                fn (string $name, callable $operation): mixed => $operation()
            );
        }
        $this->app->instance(CircuitBreaker::class, $breaker);
        Schema::shouldReceive('hasTable')->andReturnFalse();
    }
}
