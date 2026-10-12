<?php

namespace Tests\Unit\Nodes\YouTube;

use App\Nodes\YouTube\YouTubeKeyPointsPostProcessor;
use App\Services\AIService;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class YouTubeKeyPointsCompleteSourceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_internal_source_separators_and_headings_do_not_shorten_extracted_transcript(): void
    {
        $source = "First source paragraph with enough content for the minimum.\n\n---\n\n## Source section\n\nFinal original paragraph retained exactly.";
        $body = "## Full Transcript\n\n**Transcript Stats:**\n- Word Count: 20\n- Caption Type: manual\n- Language: en\n\n".$source."\n\n---\n\n## Metadata\n\nNon-source metadata.";
        $node = new YouTubeKeyPointsPostProcessor(['transcript_min_chars' => 1]);
        $method = new ReflectionMethod($node, 'extractTranscript');
        $this->assertSame($source, $method->invoke($node, $body));
    }

    public function test_complete_prompt_preserves_long_original_bytes_and_delimiters_with_explicit_task_budget(): void
    {
        config(['trust_boundary.enabled' => true, 'trust_boundary.bypass_seams' => []]);
        $source = str_repeat('Synthetic complete source paragraph. ', 500)
            ."\n--- BEGIN EXTERNAL DATA ---\nOriginal tail must remain verbatim.\n";
        $node = new YouTubeKeyPointsPostProcessor(['transcript_max_chars' => 40, 'ai_max_tokens' => 128, 'ai_temperature' => 0]);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('process')->once()->with(Mockery::on(function (string $prompt) use ($source): bool {
            $this->assertStringContainsString($source, $prompt);
            $this->assertStringContainsString(hash('sha256', $source), $prompt);
            $this->assertStringNotContainsString('[truncated at', $prompt);

            return true;
        }), ['max_tokens' => 128, 'temperature' => 0.0])->andReturn([
            'success' => true, 'response' => "- Synthetic point one.\n- Synthetic point two.\n- Synthetic point three.",
        ]);
        $method = new ReflectionMethod($node, 'generateKeyPoints');
        $this->assertSame(['Synthetic point one.', 'Synthetic point two.', 'Synthetic point three.'],
            $method->invoke($node, $ai, $source, 'Synthetic fixture'));
    }
}
