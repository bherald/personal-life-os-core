<?php

namespace Tests\Unit\Services;

use App\Services\SystemConfigService;
use App\Services\UniversalResearchRuntimePolicyService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class UniversalResearchRuntimePolicyServiceTest extends TestCase
{
    public function test_required_returns_validated_policy(): void
    {
        $systemConfig = Mockery::mock(SystemConfigService::class);
        $systemConfig->shouldReceive('get')
            ->once()
            ->with(UniversalResearchRuntimePolicyService::CONFIG_KEY)
            ->andReturn($this->policy());

        $policy = (new UniversalResearchRuntimePolicyService($systemConfig))->required();

        $this->assertSame(3, $policy['gather']['max_articles_per_source']);
        $this->assertSame(20, $policy['gather']['search_timeout_seconds']);
        $this->assertSame(5000, $policy['extraction']['max_content_chars']);
        $this->assertSame(3000, $policy['synthesis']['max_tokens']);
        $this->assertSame(15, $policy['enhanced_knowledge']['max_facts']);
    }

    public function test_required_rejects_missing_policy(): void
    {
        $systemConfig = Mockery::mock(SystemConfigService::class);
        $systemConfig->shouldReceive('get')->once()->andReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('runtime policy is unavailable');

        (new UniversalResearchRuntimePolicyService($systemConfig))->required();
    }

    public function test_required_rejects_out_of_range_nested_value(): void
    {
        $policy = $this->policy();
        $policy['gather']['search_timeout_seconds'] = 0;

        $systemConfig = Mockery::mock(SystemConfigService::class);
        $systemConfig->shouldReceive('get')->once()->andReturn($policy);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid gather.search_timeout_seconds');

        (new UniversalResearchRuntimePolicyService($systemConfig))->required();
    }

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => UniversalResearchRuntimePolicyService::SCHEMA,
            'gather' => [
                'max_articles_per_source' => 3,
                'search_timeout_seconds' => 20,
                'article_timeout_seconds' => 15,
                'source_delay_ms' => 500,
                'article_delay_ms' => 300,
                'min_link_text_chars' => 10,
                'min_article_content_chars' => 500,
            ],
            'extraction' => [
                'min_content_chars' => 100,
                'max_content_chars' => 5000,
                'max_facts_per_source' => 5,
                'max_tokens' => 1000,
            ],
            'synthesis' => [
                'max_facts' => 30,
                'max_sources' => 10,
                'max_words' => 500,
                'max_tokens' => 3000,
            ],
            'enhanced_knowledge' => [
                'max_existing_facts' => 10,
                'max_facts' => 15,
                'max_tokens' => 2500,
            ],
        ];
    }
}
