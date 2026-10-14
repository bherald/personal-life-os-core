<?php

namespace Tests\Unit\Services;

use App\Services\SystemConfigService;
use App\Services\UpstreamSourceDriftRuntimePolicyService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class UpstreamSourceDriftRuntimePolicyServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_requires_complete_coherent_table_policy(): void
    {
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')
            ->once()
            ->with(UpstreamSourceDriftRuntimePolicyService::CONFIG_KEY)
            ->andReturn($this->policy());

        $this->assertSame(
            array_diff_key($this->policy(), ['schema' => true]),
            (new UpstreamSourceDriftRuntimePolicyService($config))->required(),
        );
    }

    public function test_missing_or_incoherent_policy_fails_closed(): void
    {
        $config = Mockery::mock(SystemConfigService::class);
        $policy = $this->policy();
        $policy['request_timeout_seconds'] = 61;
        $config->shouldReceive('get')->once()->andReturn($policy);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('request timeout exceeds its maximum');

        (new UpstreamSourceDriftRuntimePolicyService($config))->required();
    }

    public function test_missing_invalid_or_excessive_response_cap_fails_closed(): void
    {
        foreach ([null, 0, 1048577, '262144'] as $cap) {
            $policy = $this->policy();
            $policy['response_max_bytes'] = $cap;
            $config = Mockery::mock(SystemConfigService::class);
            $config->shouldReceive('get')->once()->andReturn($policy);
            try {
                (new UpstreamSourceDriftRuntimePolicyService($config))->required();
                $this->fail('Expected unqualified response cap to hold.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('invalid response_max_bytes', $exception->getMessage());
            }
        }
    }

    public function test_options_use_defaults_and_cannot_exceed_table_maximum(): void
    {
        $service = new UpstreamSourceDriftRuntimePolicyService(Mockery::mock(SystemConfigService::class));

        $this->assertSame(20, $service->resolveOption(null, 'limit', 20, 500));
        $this->assertSame(125, $service->resolveOption('125', 'limit', 20, 500));

        foreach (['0', '-1', '2.5', '501', []] as $invalid) {
            try {
                $service->resolveOption($invalid, 'limit', 20, 500);
                $this->fail('Expected invalid source-drift option to fail.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => UpstreamSourceDriftRuntimePolicyService::SCHEMA,
            'default_limit' => 20,
            'max_limit' => 500,
            'connect_timeout_seconds' => 5,
            'request_timeout_seconds' => 15,
            'response_max_bytes' => 262144,
            'max_request_timeout_seconds' => 60,
        ];
    }
}
