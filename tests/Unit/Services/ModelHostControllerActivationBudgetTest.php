<?php

namespace Tests\Unit\Services;

use App\Services\ModelHostControllerClient;
use App\Services\ModelHostControllerPolicyService;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UsesLlmRoutingBindings;
use Tests\TestCase;

class ModelHostControllerActivationBudgetTest extends TestCase
{
    use UsesLlmRoutingBindings;

    private string $tokenPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenPath = tempnam(sys_get_temp_dir(), 'mhc-token-');
        file_put_contents($this->tokenPath, str_repeat('a', 48));
        chmod($this->tokenPath, 0600);
        config()->set('model_host_controller.expected_host_id', 'fixture-host');
        config()->set('model_host_controller.url', 'http://172.29.50.1:11439');
        config()->set('model_host_controller.allowed_hosts', ['172.29.50.1']);
        config()->set('model_host_controller.allowed_port', 11439);
        config()->set('model_host_controller.token_file', $this->tokenPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->tokenPath);
        Mockery::close();
        parent::tearDown();
    }

    public function test_activate_sends_and_validates_exact_lease_identity(): void
    {
        Http::fake([
            'http://172.29.50.1:11439/v1/activate' => Http::response([
                'schema' => 'plos.model_host_controller.activation.v1',
                'host_id' => 'fixture-host',
                'target' => 'fixture_text',
                'request_id' => 'request-12345678',
                'lease_id' => 'lease-12345678',
                'contains_prompt_or_response_content' => false,
                'result' => 'activated',
            ]),
        ]);
        $client = new ModelHostControllerClient($this->policyService(true));

        $activation = $client->activate('fixture_text', 'request-12345678', 'lease-12345678');

        $this->assertSame('lease-12345678', $activation['lease_id']);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://172.29.50.1:11439/v1/activate'
            && $request['target'] === 'fixture_text'
            && $request['request_id'] === 'request-12345678'
            && $request['lease_id'] === 'lease-12345678'
            && $request['execute'] === true);
    }

    #[DataProvider('activationBudgetCases')]
    public function test_activation_transport_covers_table_owned_startup_and_verification(int $startup, ?int $verification, int $expected): void
    {
        config()->set('model_host_controller.mutation_timeout_seconds', 600);
        config()->set('model_host_controller.request_timeout_seconds', 30);
        $policy = $this->policyService(true)->required();
        $policy['targets']['fixture_text']['startup_timeout_seconds'] = $startup;
        if ($verification !== null) {
            $policy['targets']['fixture_text']['verification_timeout_seconds'] = $verification;
        }
        $policies = Mockery::mock(ModelHostControllerPolicyService::class);
        $policies->shouldReceive('required')->andReturn($policy);
        $observedTimeout = null;
        Http::fake(function ($request, $options) use (&$observedTimeout) {
            $observedTimeout = $options['timeout'];

            return Http::response(['schema' => 'plos.model_host_controller.activation.v1', 'host_id' => 'fixture-host',
                'target' => 'fixture_text', 'request_id' => 'request-12345678', 'lease_id' => 'lease-12345678',
                'contains_prompt_or_response_content' => false, 'result' => 'activation_failed']);
        });
        $result = (new ModelHostControllerClient($policies))->activate('fixture_text', 'request-12345678', 'lease-12345678');
        $this->assertSame('activation_failed', $result['result']);
        $this->assertSame($expected, $observedTimeout);
        Http::assertSentCount(1);
    }

    public static function activationBudgetCases(): array
    {
        return ['cold startup' => [600, null, 630], 'phased verification' => [600, 1800, 2430],
            'existing mutation floor' => [180, null, 600]];
    }

    private function policyService(bool $enabled, string $engine = 'llama.cpp'): ModelHostControllerPolicyService
    {
        $policy = [
            'schema' => 'plos.model_host_controller_policy.v1',
            'revision' => 'test',
            'enabled' => $enabled,
            'automatic_activation_enabled' => false,
            'compatible_residency_enabled' => false,
            'concurrent_inference_enabled' => false,
            'execution_mode' => 'serial',
            'host_id' => 'fixture-host',
            'default_target' => 'fixture_text',
            'targets' => [
                'fixture_text' => [
                    'model' => 'fixture-model',
                    'engine' => $engine,
                    'state' => 'production',
                    'activation_allowed' => true,
                    'requires_operator_override' => false,
                    'roles' => ['standard'],
                    'resource_group' => 'fixture_inference',
                ],
            ],
        ];
        $service = Mockery::mock(ModelHostControllerPolicyService::class);
        $service->shouldReceive('required')->andReturn((new ModelHostControllerPolicyService)->normalize($policy));

        return $service;
    }
}
