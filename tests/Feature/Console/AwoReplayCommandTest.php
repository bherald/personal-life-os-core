<?php

namespace Tests\Feature\Console;

use App\Services\AgentMetrics\AwoReplayService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class AwoReplayCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_json_option_emits_observe_payload(): void
    {
        $payload = $this->payload();
        $service = Mockery::mock(AwoReplayService::class);
        $service->shouldReceive('collect')
            ->once()
            ->with('7d', 500)
            ->andReturn($payload);
        $this->app->instance(AwoReplayService::class, $service);

        $exit = Artisan::call('awo:replay', ['--json' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame($payload, json_decode((string) Artisan::output(), true));
    }

    public function test_compact_json_option_uses_compact_payload_renderer(): void
    {
        $payload = $this->payload([
            'items' => [
                ['review_queue_id' => 123, 'agent_id' => 'agent-one'],
            ],
        ]);
        $compact = [
            'version' => 1,
            'compact' => true,
            'mode' => 'observe',
            'status' => 'observe_ok',
            'summary' => ['rows_scanned' => 12],
            'note' => 'Compact replay is read-only.',
        ];

        $service = Mockery::mock(AwoReplayService::class);
        $service->shouldReceive('collect')
            ->once()
            ->with('7d', 500)
            ->andReturn($payload);
        $service->shouldReceive('compactPayload')
            ->once()
            ->with($payload)
            ->andReturn($compact);
        $this->app->instance(AwoReplayService::class, $service);

        $exit = Artisan::call('awo:replay', ['--json' => true, '--compact' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame($compact, json_decode((string) Artisan::output(), true));
    }

    public function test_text_output_reports_insufficient_data(): void
    {
        $payload = $this->payload([
            'status' => 'insufficient_data',
            'summary' => [
                'rows_scanned' => 3,
                'completed_reviews' => 3,
                'approval_worthy_reviews' => 1,
                'hard_fail_count' => 0,
                'completed_hard_fail_count' => 0,
                'pending_hard_fail_signal_count' => 1,
                'scanned_hard_fail_signal_count' => 1,
                'insufficient_data' => true,
            ],
        ]);
        $service = Mockery::mock(AwoReplayService::class);
        $service->shouldReceive('collect')
            ->once()
            ->with('24h', 25)
            ->andReturn($payload);
        $this->app->instance(AwoReplayService::class, $service);

        $exit = Artisan::call('awo:replay', [
            '--window' => '24h',
            '--limit' => 25,
        ]);

        $output = (string) Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('AWO replay: insufficient_data', $output);
        $this->assertStringContainsString('completed_hard_fails=0 scanned_hard_fail_signals=1 pending_hard_fail_signals=1', $output);
        $this->assertStringContainsString('fewer than 10 completed reviews', $output);
    }

    public function test_compact_text_option_uses_compact_renderer(): void
    {
        $payload = $this->payload();
        $service = Mockery::mock(AwoReplayService::class);
        $service->shouldReceive('collect')
            ->once()
            ->with('7d', 500)
            ->andReturn($payload);
        $service->shouldReceive('toCompactText')
            ->once()
            ->with($payload)
            ->andReturn("AWO replay compact: observe_ok window=7d rows=12 read_only=true\n");
        $this->app->instance(AwoReplayService::class, $service);

        $exit = Artisan::call('awo:replay', ['--compact' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('AWO replay compact:', (string) Artisan::output());
    }

    public function test_markdown_option_uses_service_renderer(): void
    {
        $payload = $this->payload();
        $service = Mockery::mock(AwoReplayService::class);
        $service->shouldReceive('collect')
            ->once()
            ->with('7d', 500)
            ->andReturn($payload);
        $service->shouldReceive('toMarkdown')
            ->once()
            ->with($payload)
            ->andReturn("# AWO Replay Report\n\n- Status: `observe_ok`\n");
        $this->app->instance(AwoReplayService::class, $service);

        $exit = Artisan::call('awo:replay', ['--markdown' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('# AWO Replay Report', (string) Artisan::output());
    }

    public function test_compact_markdown_option_uses_compact_renderer(): void
    {
        $payload = $this->payload();
        $service = Mockery::mock(AwoReplayService::class);
        $service->shouldReceive('collect')
            ->once()
            ->with('7d', 500)
            ->andReturn($payload);
        $service->shouldReceive('toCompactMarkdown')
            ->once()
            ->with($payload)
            ->andReturn("# AWO Replay Compact Report\n\n- Status: `observe_ok`\n");
        $this->app->instance(AwoReplayService::class, $service);

        $exit = Artisan::call('awo:replay', ['--markdown' => true, '--compact' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('# AWO Replay Compact Report', (string) Artisan::output());
    }

    public function test_compare_scheduled_json_option_emits_read_only_comparison_payload(): void
    {
        $payload = [
            'version' => 1,
            'mode' => 'observe',
            'type' => 'scheduled_report_comparison',
            'status' => 'observe_pending',
            'job' => ['name' => 'awo_replay_weekly_report'],
            'latest_scheduled_run' => null,
            'field_matches' => [],
            'stop_rules' => [
                'Do not enable awo.recording_enabled from this report.',
            ],
        ];
        $service = Mockery::mock(AwoReplayService::class);
        $service->shouldReceive('collectScheduledComparison')
            ->once()
            ->with('7d', 500, 'awo_replay_weekly_report')
            ->andReturn($payload);
        $service->shouldNotReceive('collect');
        $this->app->instance(AwoReplayService::class, $service);

        $exit = Artisan::call('awo:replay', ['--compare-scheduled' => true, '--json' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame($payload, json_decode((string) Artisan::output(), true));
    }

    public function test_compare_scheduled_markdown_option_uses_service_renderer(): void
    {
        $payload = ['status' => 'observe_ok'];
        $service = Mockery::mock(AwoReplayService::class);
        $service->shouldReceive('collectScheduledComparison')
            ->once()
            ->with('24h', 25, 'awo_custom_report')
            ->andReturn($payload);
        $service->shouldReceive('comparisonToMarkdown')
            ->once()
            ->with($payload)
            ->andReturn("# AWO Scheduled Report Comparison\n\n- Status: `observe_ok`\n");
        $this->app->instance(AwoReplayService::class, $service);

        $exit = Artisan::call('awo:replay', [
            '--compare-scheduled' => true,
            '--markdown' => true,
            '--window' => '24h',
            '--limit' => 25,
            '--scheduled-job' => 'awo_custom_report',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('# AWO Scheduled Report Comparison', (string) Artisan::output());
    }

    public function test_compare_scheduled_rejects_compact_to_preserve_scheduled_report_contract(): void
    {
        $service = Mockery::mock(AwoReplayService::class);
        $service->shouldNotReceive('collect');
        $service->shouldNotReceive('collectScheduledComparison');
        $this->app->instance(AwoReplayService::class, $service);

        $exit = Artisan::call('awo:replay', [
            '--compare-scheduled' => true,
            '--compact' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('The --compact option is only supported for normal replay output.', (string) Artisan::output());
    }

    public function test_json_and_markdown_options_are_mutually_exclusive(): void
    {
        $service = Mockery::mock(AwoReplayService::class);
        $service->shouldNotReceive('collect');
        $this->app->instance(AwoReplayService::class, $service);

        $exit = Artisan::call('awo:replay', ['--json' => true, '--markdown' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Choose either --json or --markdown', (string) Artisan::output());
    }

    public function test_invalid_window_returns_failure(): void
    {
        $service = Mockery::mock(AwoReplayService::class);
        $service->shouldReceive('collect')
            ->once()
            ->with('forever', 500)
            ->andThrow(new \InvalidArgumentException('Invalid window. Use Nm, Nh, or Nd.'));
        $this->app->instance(AwoReplayService::class, $service);

        $exit = Artisan::call('awo:replay', ['--window' => 'forever']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid window', (string) Artisan::output());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'version' => 1,
            'mode' => 'observe',
            'window' => '7d',
            'cutoff' => '2026-04-22 20:00:00',
            'limit' => 500,
            'status' => 'observe_ok',
            'summary' => [
                'rows_scanned' => 12,
                'completed_reviews' => 10,
                'approval_worthy_reviews' => 7,
                'approval_worthy_rate' => 0.7,
                'review_approval_yield' => 0.8,
                'operator_rework_rate' => 0.1,
                'hard_fail_count' => 0,
                'completed_hard_fail_count' => 0,
                'pending_hard_fail_signal_count' => 0,
                'scanned_hard_fail_signal_count' => 0,
                'insufficient_data' => false,
            ],
            'by_agent' => [],
            'items' => [],
            'promotion_decisions' => [],
            'note' => 'Replay is read-only and does not promote, disable, approve, reject, or write agent state.',
        ], $overrides);
    }
}
