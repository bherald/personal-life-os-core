<?php

namespace Tests\Feature\Console;

use App\Services\Ops\ReviewBacklogReportService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class OpsReviewBacklogReportCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_json_option_emits_observe_payload(): void
    {
        $payload = $this->payload();

        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('collect')
            ->once()
            ->with(7, 8, false)
            ->andReturn($payload);
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', ['--json' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame($payload, json_decode((string) Artisan::output(), true));
    }

    public function test_markdown_option_uses_service_renderer(): void
    {
        $payload = $this->payload();

        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('collect')
            ->once()
            ->with(10, 9, false)
            ->andReturn($payload);
        $service->shouldReceive('toMarkdown')
            ->once()
            ->with($payload)
            ->andReturn("# Review Backlog Report\n\n- Status: `review_required`\n");
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', [
            '--markdown' => true,
            '--stale-days' => 10,
            '--high-priority' => 9,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('# Review Backlog Report', (string) Artisan::output());
    }

    public function test_compact_json_option_uses_compact_payload_renderer(): void
    {
        $payload = $this->payload();
        $compact = [
            'version' => 1,
            'compact' => true,
            'status' => 'review_required',
            'summary' => ['pending_total' => 40],
        ];

        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('collect')
            ->once()
            ->with(7, 8, false)
            ->andReturn($payload);
        $service->shouldReceive('compactPayload')
            ->once()
            ->with($payload)
            ->andReturn($compact);
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', [
            '--json' => true,
            '--compact' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame($compact, json_decode((string) Artisan::output(), true));
    }

    public function test_compact_markdown_option_uses_compact_renderer(): void
    {
        $payload = $this->payload();

        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('collect')
            ->once()
            ->with(7, 8, false)
            ->andReturn($payload);
        $service->shouldReceive('toCompactMarkdown')
            ->once()
            ->with($payload)
            ->andReturn("# Review Backlog Compact Report\n\n- Status: `review_required`\n");
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', [
            '--markdown' => true,
            '--compact' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('# Review Backlog Compact Report', (string) Artisan::output());
    }

    public function test_compact_text_option_uses_compact_renderer(): void
    {
        $payload = $this->payload();

        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('collect')
            ->once()
            ->with(7, 8, false)
            ->andReturn($payload);
        $service->shouldReceive('toCompactText')
            ->once()
            ->with($payload)
            ->andReturn("Review backlog compact: review_required captured=2026-05-02T13:52:00Z pending=40 stale=10 high_priority=1\n");
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', ['--compact' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Review backlog compact: review_required', (string) Artisan::output());
    }

    public function test_dry_run_flag_is_forwarded(): void
    {
        $payload = $this->payload(['dry_run' => true]);

        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('collect')
            ->once()
            ->with(7, 8, true)
            ->andReturn($payload);
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', [
            '--json' => true,
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue(json_decode((string) Artisan::output(), true)['dry_run']);
    }

    public function test_next_target_json_option_uses_sanitized_next_target_payload(): void
    {
        $payload = $this->nextTargetPayload();

        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('nextTarget')
            ->once()
            ->with(7, 8, false, null)
            ->andReturn($payload);
        $service->shouldReceive('collect')->never();
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', [
            '--json' => true,
            '--next-target' => true,
        ]);

        $decoded = json_decode((string) Artisan::output(), true);
        $this->assertSame(0, $exit);
        $this->assertSame($payload, $decoded);
        $this->assertSame('typed_preview_needed', $decoded['next_target']['underlying_classification']);
        $this->assertArrayNotHasKey('unified_id', $decoded['next_target']);
        $this->assertArrayHasKey('target_ref', $decoded['next_target']);
        $this->assertArrayNotHasKey('title', $decoded['next_target']);
        $this->assertArrayNotHasKey('summary', $decoded['next_target']);
        $this->assertArrayNotHasKey('details', $decoded['next_target']);
    }

    public function test_next_target_text_option_uses_renderer(): void
    {
        $payload = $this->nextTargetPayload();

        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('nextTarget')
            ->once()
            ->with(10, 9, true, null)
            ->andReturn($payload);
        $service->shouldReceive('toNextTargetText')
            ->once()
            ->with($payload)
            ->andReturn("Review backlog next target: review_required target_ref=system_alert:target-abc123abc123\n");
        $service->shouldReceive('collect')->never();
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', [
            '--next-target' => true,
            '--stale-days' => 10,
            '--high-priority' => 9,
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Review backlog next target:', (string) Artisan::output());
    }

    public function test_next_target_focus_option_is_forwarded(): void
    {
        $payload = $this->nextTargetPayload(['focus' => 'typed-remediation']);

        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('nextTarget')
            ->once()
            ->with(7, 8, false, 'typed-remediation')
            ->andReturn($payload);
        $service->shouldReceive('collect')->never();
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', [
            '--json' => true,
            '--next-target' => true,
            '--focus' => 'typed-remediation',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('typed-remediation', json_decode((string) Artisan::output(), true)['focus']);
    }

    public function test_next_target_materializable_focus_option_is_forwarded(): void
    {
        $payload = $this->nextTargetPayload(['focus' => 'materializable-remediation']);

        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('nextTarget')
            ->once()
            ->with(7, 8, false, 'materializable-remediation')
            ->andReturn($payload);
        $service->shouldReceive('collect')->never();
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', [
            '--json' => true,
            '--next-target' => true,
            '--focus' => 'materializable-remediation',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('materializable-remediation', json_decode((string) Artisan::output(), true)['focus']);
    }

    public function test_next_target_source_backed_packet_focus_option_is_forwarded(): void
    {
        $payload = $this->nextTargetPayload(['focus' => 'source-backed-packet']);

        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('nextTarget')
            ->once()
            ->with(7, 8, false, 'source-backed-packet')
            ->andReturn($payload);
        $service->shouldReceive('collect')->never();
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', [
            '--json' => true,
            '--next-target' => true,
            '--focus' => 'source-backed-packet',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('source-backed-packet', json_decode((string) Artisan::output(), true)['focus']);
    }

    public function test_next_target_aged_review_focus_option_is_forwarded(): void
    {
        $payload = $this->nextTargetPayload(['focus' => 'aged-review']);

        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('nextTarget')
            ->once()
            ->with(7, 8, false, 'aged-review')
            ->andReturn($payload);
        $service->shouldReceive('collect')->never();
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', [
            '--json' => true,
            '--next-target' => true,
            '--focus' => 'aged-review',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('aged-review', json_decode((string) Artisan::output(), true)['focus']);
    }

    public function test_source_backed_packet_next_target_text_output_is_redacted(): void
    {
        $payload = $this->sourceBackedPacketNextTargetPayload();
        $expectedRef = $payload['next_target']['target_ref'];

        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('nextTarget')
            ->once()
            ->with(7, 8, false, 'source-backed-packet')
            ->andReturn($payload);
        $service->shouldReceive('toNextTargetText')
            ->once()
            ->with($payload)
            ->andReturn("Review backlog next target: review_required focus=source-backed-packet target_ref={$expectedRef} classification=source_backed_packet_review review_pass=ready details_included=false raw_identifiers_included=false tokens_included=false locators_included=false\n");
        $service->shouldReceive('collect')->never();
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', [
            '--next-target' => true,
            '--focus' => 'source-backed-packet',
        ]);

        $output = (string) Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Review backlog next target: review_required', $output);
        $this->assertStringContainsString('focus=source-backed-packet', $output);
        $this->assertStringContainsString('target_ref='.$expectedRef, $output);
        $this->assertStringContainsString('classification=source_backed_packet_review', $output);
        $this->assertStringContainsString('review_pass=ready', $output);
        $this->assertStringContainsString('details_included=false', $output);
        $this->assertStringContainsString('raw_identifiers_included=false', $output);
        $this->assertStringContainsString('tokens_included=false', $output);
        $this->assertStringContainsString('locators_included=false', $output);
        $this->assertSourceBackedPacketCommandOutputIsRedacted($output);
    }

    public function test_source_backed_packet_next_target_markdown_output_is_redacted(): void
    {
        $payload = $this->sourceBackedPacketNextTargetPayload();
        $expectedRef = $payload['next_target']['target_ref'];

        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('nextTarget')
            ->once()
            ->with(7, 8, false, 'source-backed-packet')
            ->andReturn($payload);
        $service->shouldReceive('toNextTargetMarkdown')
            ->once()
            ->with($payload)
            ->andReturn(implode("\n", [
                '# Review Backlog Next Target',
                '',
                '- Status: `review_required`',
                '- Focus: `source-backed-packet`',
                '- Target ref: `'.$expectedRef.'`',
                '- Classification: `source_backed_packet_review`',
                '- Review pass: `state=ready`, `source_backed=true`, `preview_only=true`, `canonical_mutation=false`',
                '- Posture: `details_included=false`, `raw_identifiers_included=false`, `tokens_included=false`, `locators_included=false`',
                '',
            ]));
        $service->shouldReceive('collect')->never();
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', [
            '--next-target' => true,
            '--focus' => 'source-backed-packet',
            '--markdown' => true,
        ]);

        $output = (string) Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('# Review Backlog Next Target', $output);
        $this->assertStringContainsString('- Focus: `source-backed-packet`', $output);
        $this->assertStringContainsString('- Target ref: `'.$expectedRef.'`', $output);
        $this->assertStringContainsString('- Classification: `source_backed_packet_review`', $output);
        $this->assertStringContainsString('- Review pass: `state=ready`, `source_backed=true`, `preview_only=true`, `canonical_mutation=false`', $output);
        $this->assertStringContainsString('`details_included=false`', $output);
        $this->assertStringContainsString('`raw_identifiers_included=false`', $output);
        $this->assertStringContainsString('`tokens_included=false`', $output);
        $this->assertStringContainsString('`locators_included=false`', $output);
        $this->assertSourceBackedPacketCommandOutputIsRedacted($output);
    }

    public function test_source_backed_packet_next_target_compact_flag_output_is_redacted(): void
    {
        $payload = $this->sourceBackedPacketNextTargetPayload();
        $this->bindPartialNextTargetService($payload, 'source-backed-packet');

        $exit = Artisan::call('ops:review-backlog-report', [
            '--next-target' => true,
            '--focus' => 'source-backed-packet',
            '--compact' => true,
        ]);

        $output = (string) Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Review backlog next target compact: review_required', $output);
        $this->assertStringContainsString('focus=source-backed-packet', $output);
        $this->assertStringContainsString('available=true', $output);
        $this->assertStringContainsString('classification=source_backed_packet_review', $output);
        $this->assertStringContainsString('operator_action=review_one_source_backed_packet', $output);
        $this->assertStringContainsString('target_ref_included=false', $output);
        $this->assertStringContainsString('raw_ids_included=false', $output);
        $this->assertStringContainsString('tokens_included=false', $output);
        $this->assertStringContainsString('locators_included=false', $output);
        $this->assertSourceBackedPacketCommandOutputIsRedacted($output);
    }

    public function test_aged_review_next_target_compact_json_is_redacted(): void
    {
        $payload = $this->agedReviewNextTargetPayload();
        $this->bindPartialNextTargetService($payload, 'aged-review');

        $exit = Artisan::call('ops:review-backlog-report', [
            '--next-target' => true,
            '--focus' => 'aged-review',
            '--json' => true,
            '--compact' => true,
        ]);

        $compact = json_decode((string) Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertTrue($compact['compact']);
        $this->assertSame('aged-review', $compact['focus']);
        $this->assertSame('next_target_selected', $compact['query_state']);
        $this->assertTrue($compact['target']['available']);
        $this->assertSame('system_alert', $compact['target']['review_type']);
        $this->assertSame('high_priority_pending_review', $compact['target']['classification']);
        $this->assertSame('oldest_aged_review', $compact['target']['selection_reason']);
        $this->assertSame('classify_one_aged_review', $compact['target']['operator_action']);
        $this->assertSame(34, $compact['target']['age_days']);
        $this->assertSame('31d_plus', $compact['target']['age_bucket']);
        $this->assertSame(35, $compact['focus_summary']['candidate_rows']);
        $this->assertSame(35, $compact['focus_summary']['stale_rows']);
        $this->assertSame(1, $compact['focus_summary']['high_priority_rows']);
        $this->assertSame([
            '31d_plus' => 2,
            '8_30d' => 33,
        ], $compact['focus_summary']['age_bucket_counts']);
        $this->assertFalse($compact['posture']['target_ref_included']);
        $this->assertFalse($compact['posture']['raw_ids_included']);
        $this->assertFalse($compact['posture']['tokens_included']);
        $this->assertFalse($compact['posture']['locators_included']);
        $this->assertFalse($compact['posture']['commands_included']);
        $this->assertFalse($compact['posture']['apply_controls_included']);

        $encoded = json_encode($compact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ([
            'system_alert:target-private-aged',
            'next-target-aged-private-token',
            'COMMAND SECRET',
            'genealogy:materialize-typed-remediation',
            '--execute',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $encoded);
        }
    }

    public function test_aged_review_next_target_compact_text_is_redacted(): void
    {
        $payload = $this->agedReviewNextTargetPayload();
        $this->bindPartialNextTargetService($payload, 'aged-review');

        $exit = Artisan::call('ops:review-backlog-report', [
            '--next-target' => true,
            '--focus' => 'aged-review',
            '--compact' => true,
        ]);

        $output = (string) Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Review backlog next target compact: review_required', $output);
        $this->assertStringContainsString('focus=aged-review', $output);
        $this->assertStringContainsString('query_state=next_target_selected', $output);
        $this->assertStringContainsString('available=true', $output);
        $this->assertStringContainsString('type=system_alert', $output);
        $this->assertStringContainsString('classification=high_priority_pending_review', $output);
        $this->assertStringContainsString('selection_reason=oldest_aged_review', $output);
        $this->assertStringContainsString('operator_action=classify_one_aged_review', $output);
        $this->assertStringContainsString('age_days=34', $output);
        $this->assertStringContainsString('age_bucket=31d_plus', $output);
        $this->assertStringContainsString('stale_over=27', $output);
        $this->assertStringContainsString('focus_candidates=35', $output);
        $this->assertStringContainsString('stale_rows=35', $output);
        $this->assertStringContainsString('high_priority_rows=1', $output);
        $this->assertStringContainsString('age_buckets=31d_plus:2,8_30d:33', $output);
        $this->assertStringContainsString('target_ref_included=false', $output);
        $this->assertStringContainsString('raw_ids_included=false', $output);
        $this->assertStringContainsString('tokens_included=false', $output);
        $this->assertStringContainsString('locators_included=false', $output);
        $this->assertStringContainsString('commands_included=false', $output);
        $this->assertStringContainsString('apply_controls_included=false', $output);
        $this->assertAgedReviewCompactOutputIsRedacted($output);
    }

    public function test_aged_review_next_target_compact_markdown_is_redacted(): void
    {
        $payload = $this->agedReviewNextTargetPayload();
        $this->bindPartialNextTargetService($payload, 'aged-review');

        $exit = Artisan::call('ops:review-backlog-report', [
            '--next-target' => true,
            '--focus' => 'aged-review',
            '--markdown' => true,
            '--compact' => true,
        ]);

        $output = (string) Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('# Review Backlog Next Target Compact', $output);
        $this->assertStringContainsString('- Focus: `aged-review`', $output);
        $this->assertStringContainsString('- Target available: `true`', $output);
        $this->assertStringContainsString('- Review type: `system_alert`', $output);
        $this->assertStringContainsString('- Classification: `high_priority_pending_review`', $output);
        $this->assertStringContainsString('- Selection reason: `oldest_aged_review`', $output);
        $this->assertStringContainsString('- Operator action: `classify_one_aged_review`', $output);
        $this->assertStringContainsString('- Age: `34` days', $output);
        $this->assertStringContainsString('- Age bucket: `31d_plus`', $output);
        $this->assertStringContainsString('- Candidate rows: `35`', $output);
        $this->assertStringContainsString('- Stale rows: `35`', $output);
        $this->assertStringContainsString('- High-priority rows: `1`', $output);
        $this->assertStringContainsString('- Age buckets: `31d_plus:2,8_30d:33`', $output);
        $this->assertStringContainsString('- Target ref included: `false`', $output);
        $this->assertStringContainsString('- Raw ids included: `false`', $output);
        $this->assertStringContainsString('- Tokens included: `false`', $output);
        $this->assertStringContainsString('- Locators included: `false`', $output);
        $this->assertStringContainsString('- Commands included: `false`', $output);
        $this->assertStringContainsString('- Apply controls included: `false`', $output);
        $this->assertAgedReviewCompactOutputIsRedacted($output);
    }

    public function test_validation_blocked_typed_remediation_next_target_text_output_is_no_execute_and_redacted(): void
    {
        $payload = $this->validationBlockedTypedRemediationNextTargetPayload();
        $expectedRef = $payload['next_target']['target_ref'];
        $this->bindPartialNextTargetService($payload, 'typed-remediation');

        $exit = Artisan::call('ops:review-backlog-report', [
            '--next-target' => true,
            '--focus' => 'typed-remediation',
        ]);

        $output = (string) Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Review backlog next target: review_required', $output);
        $this->assertStringContainsString('focus=typed-remediation', $output);
        $this->assertStringContainsString('target_ref='.$expectedRef, $output);
        $this->assertStringContainsString('materialization=validation_blocked available=false reason=packet_validation_failed', $output);
        $this->assertStringContainsString('dry_run_available=true', $output);
        $this->assertStringContainsString('no_canonical_write=true', $output);
        $this->assertStringContainsString('apply_enabled=false', $output);
        $this->assertStringContainsString('apply_held=true', $output);
        $this->assertStringContainsString('missing_inputs=source_locator_required,privacy_clearance_required', $output);
        $this->assertTypedRemediationCommandOutputIsRedacted($output);
    }

    public function test_validation_blocked_typed_remediation_next_target_markdown_output_is_no_execute_and_redacted(): void
    {
        $payload = $this->validationBlockedTypedRemediationNextTargetPayload();
        $expectedRef = $payload['next_target']['target_ref'];
        $this->bindPartialNextTargetService($payload, 'typed-remediation');

        $exit = Artisan::call('ops:review-backlog-report', [
            '--next-target' => true,
            '--focus' => 'typed-remediation',
            '--markdown' => true,
        ]);

        $output = (string) Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('# Review Backlog Next Target', $output);
        $this->assertStringContainsString('- Focus: `typed-remediation`', $output);
        $this->assertStringContainsString('- Target ref: `'.$expectedRef.'`', $output);
        $this->assertStringContainsString('- Materialization available: `false`', $output);
        $this->assertStringContainsString('- Materialization reason: `packet_validation_failed`', $output);
        $this->assertStringContainsString('- Missing materialization inputs: `source_locator_required,privacy_clearance_required`', $output);
        $this->assertStringContainsString('`no_canonical_write=true`', $output);
        $this->assertStringContainsString('`canonical_write_allowed=false`', $output);
        $this->assertStringContainsString('`apply_enabled=false`', $output);
        $this->assertStringContainsString('`apply_held=true`', $output);
        $this->assertTypedRemediationCommandOutputIsRedacted($output);
    }

    public function test_no_focus_candidates_next_target_output_stays_empty_and_no_execute(): void
    {
        $payload = $this->noFocusCandidatesNextTargetPayload('materializable-remediation');
        $this->bindPartialNextTargetService($payload, 'materializable-remediation');

        $exit = Artisan::call('ops:review-backlog-report', [
            '--next-target' => true,
            '--focus' => 'materializable-remediation',
        ]);

        $output = (string) Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Review backlog next target: observe_ok', $output);
        $this->assertStringContainsString('query_state=no_focus_candidates', $output);
        $this->assertStringContainsString('focus=materializable-remediation', $output);
        $this->assertStringContainsString('target=none', $output);
        $this->assertStringContainsString('focus_candidates=2', $output);
        $this->assertStringContainsString('validation_blocked=1', $output);
        $this->assertStringContainsString('unsupported_operation=1', $output);
        $this->assertStringContainsString('missing_inputs=privacy_clearance_required:1,source_locator_required:1', $output);

        foreach ([
            'target_ref=',
            'materialization=',
            'operator_action=',
            'genealogy:materialize-typed-remediation',
            '--token',
            '--id',
            'execute_options',
            'dry_run_options',
            'apply_enabled=true',
            'canonical_write_allowed=true',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $output);
        }
    }

    public function test_aged_review_no_focus_candidates_next_target_text_summarizes_aggregate_only(): void
    {
        $payload = $this->agedReviewNoFocusCandidatesNextTargetPayload();
        $this->bindPartialNextTargetService($payload, 'aged-review');

        $exit = Artisan::call('ops:review-backlog-report', [
            '--next-target' => true,
            '--focus' => 'aged-review',
        ]);

        $output = (string) Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Review backlog next target: observe_ok', $output);
        $this->assertStringContainsString('query_state=no_focus_candidates', $output);
        $this->assertStringContainsString('focus=aged-review', $output);
        $this->assertStringContainsString('target=none', $output);
        $this->assertStringContainsString('focus_candidates=0', $output);
        $this->assertStringContainsString('stale_rows=0', $output);
        $this->assertStringContainsString('high_priority_rows=0', $output);
        $this->assertStringContainsString('age_buckets=none', $output);
        $this->assertStringContainsString('classifications=none', $output);

        foreach ([
            'target_ref=',
            'operator_action=',
            'genealogy:materialize-typed-remediation',
            '--token',
            '--id',
            'apply_enabled=true',
            'canonical_write_allowed=true',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $output);
        }
    }

    public function test_aged_review_no_focus_candidates_next_target_markdown_summarizes_aggregate_only(): void
    {
        $payload = $this->agedReviewNoFocusCandidatesNextTargetPayload();
        $this->bindPartialNextTargetService($payload, 'aged-review');

        $exit = Artisan::call('ops:review-backlog-report', [
            '--next-target' => true,
            '--focus' => 'aged-review',
            '--markdown' => true,
        ]);

        $output = (string) Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('# Review Backlog Next Target', $output);
        $this->assertStringContainsString('- Query state: `no_focus_candidates`', $output);
        $this->assertStringContainsString('- Focus: `aged-review`', $output);
        $this->assertStringContainsString('- Target: `none`', $output);
        $this->assertStringContainsString('- Candidate rows: `0`', $output);
        $this->assertStringContainsString('- Stale rows: `0`', $output);
        $this->assertStringContainsString('- High-priority rows: `0`', $output);
        $this->assertStringContainsString('- Age buckets: `none`', $output);
        $this->assertStringContainsString('- Classifications: `none`', $output);
        $this->assertStringNotContainsString('target_ref=', $output);
        $this->assertStringNotContainsString('genealogy:materialize-typed-remediation', $output);
    }

    public function test_focus_option_requires_next_target(): void
    {
        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('collect')->never();
        $service->shouldReceive('nextTarget')->never();
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', [
            '--focus' => 'typed-remediation',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('The --focus option is only supported with --next-target.', (string) Artisan::output());
    }

    public function test_focus_option_rejects_unknown_values(): void
    {
        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('collect')->never();
        $service->shouldReceive('nextTarget')->never();
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', [
            '--next-target' => true,
            '--focus' => 'all-the-things',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unsupported --focus value. Supported values: typed-remediation, materializable-remediation, source-backed-packet, aged-review.', (string) Artisan::output());
    }

    public function test_json_and_markdown_options_are_mutually_exclusive(): void
    {
        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('collect')->never();
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report', [
            '--json' => true,
            '--markdown' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Choose either --json or --markdown', (string) Artisan::output());
    }

    public function test_text_summary_includes_counts(): void
    {
        $payload = $this->payload();

        $service = Mockery::mock(ReviewBacklogReportService::class);
        $service->shouldReceive('collect')
            ->once()
            ->with(7, 8, false)
            ->andReturn($payload);
        $this->app->instance(ReviewBacklogReportService::class, $service);

        $exit = Artisan::call('ops:review-backlog-report');

        $this->assertSame(0, $exit);
        $output = (string) Artisan::output();
        $this->assertStringContainsString('pending=40', $output);
        $this->assertStringContainsString('stale=10', $output);
        $this->assertStringContainsString('high_priority=1', $output);
        $this->assertStringContainsString('age=8_30d pending=10 high_priority=1', $output);
        $this->assertStringContainsString('triage=typed_remediation_preview_needed pending=13 high_priority=1', $output);
        $this->assertStringContainsString('next_classification=typed_preview_needed stale=10 high_priority=1', $output);
        $this->assertStringContainsString('cleanup_step=1 focus=high_priority_pending_review pending=1 stale=1 high_priority=1', $output);
        $this->assertStringContainsString('cleanup_step=2 focus=typed_preview_needed pending=10 stale=10 high_priority=1', $output);
        $this->assertStringContainsString('remediation_readiness sample_limit=200 pending_typed=3 apply_preview=1 preview_only=1 supported_preview=1 context_without_preview=1 without_ids=1', $output);
        $this->assertStringContainsString('source_duplicate_ids=1 family_duplicate_ids=1 source_change_context=1 family_context=1 family_id_keys=1 family_ids=0 family_comparisons=0 malformed_details=0', $output);
        $this->assertStringContainsString('remediation_change_type_typo=date_quality_review suggested=data_quality_review rows=1', $output);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'version' => 1,
            'mode' => 'observe',
            'dry_run' => false,
            'stale_days' => 7,
            'high_priority_threshold' => 8,
            'captured_at' => '2026-05-02T13:52:00Z',
            'status' => 'review_required',
            'summary' => [
                'pending_total' => 40,
                'stale_pending' => 10,
                'high_priority_pending' => 1,
                'oldest_pending_at' => '2026-04-03 02:19:32',
                'newest_pending_at' => '2026-04-27 20:00:30',
            ],
            'pending_by_age' => [
                [
                    'bucket' => '8_30d',
                    'pending' => 10,
                    'high_priority_pending' => 1,
                    'oldest_pending_at' => '2026-04-03 02:19:32',
                    'newest_pending_at' => '2026-04-25 18:37:07',
                ],
                [
                    'bucket' => '1_7d',
                    'pending' => 30,
                    'high_priority_pending' => 0,
                    'oldest_pending_at' => '2026-04-25 18:37:07',
                    'newest_pending_at' => '2026-04-27 20:00:30',
                ],
            ],
            'pending_by_type' => [
                [
                    'review_type' => 'genealogy_finding',
                    'finding_type' => 'genealogy_data_quality',
                    'pending' => 13,
                    'high_priority_pending' => 1,
                    'oldest_pending_at' => '2026-04-25 18:37:07',
                    'newest_pending_at' => '2026-04-25 19:05:44',
                ],
            ],
            'triage_buckets' => [
                [
                    'category' => 'typed_remediation_preview_needed',
                    'pending' => 13,
                    'high_priority_pending' => 1,
                    'oldest_pending_at' => '2026-04-25 18:37:07',
                    'newest_pending_at' => '2026-04-25 19:05:44',
                    'review_types' => ['genealogy_finding/genealogy_data_quality'],
                    'next_action' => 'Convert into a typed remediation or source-cleanup preview before any canonical data change.',
                ],
            ],
            'next_classification_needed' => [
                [
                    'classification' => 'typed_preview_needed',
                    'stale_pending' => 10,
                    'high_priority_pending' => 1,
                    'oldest_pending_at' => '2026-04-03 02:19:32',
                    'newest_pending_at' => '2026-04-25 18:37:07',
                    'review_types' => ['genealogy_finding/genealogy_data_quality'],
                    'next_action' => 'Classify as typed-preview-needed unless a materialized read-only preview already exists.',
                ],
            ],
            'cleanup_sequence' => [
                [
                    'rank' => 1,
                    'focus' => 'high_priority_pending_review',
                    'pending' => 1,
                    'stale_pending' => 1,
                    'high_priority_pending' => 1,
                    'evidence_groups' => ['typed_remediation_preview_needed'],
                    'evidence' => 'High-priority rows appear in aggregate triage bucket(s): typed_remediation_preview_needed.',
                    'next_action' => 'Review one high-priority pending row first; classify only, and do not bulk approve or reject.',
                ],
                [
                    'rank' => 2,
                    'focus' => 'typed_preview_needed',
                    'pending' => 10,
                    'stale_pending' => 10,
                    'high_priority_pending' => 1,
                    'oldest_pending_at' => '2026-04-03 02:19:32',
                    'newest_pending_at' => '2026-04-25 18:37:07',
                    'review_types' => ['genealogy_finding/genealogy_data_quality'],
                    'evidence' => 'Stale aggregate bucket `typed_preview_needed` has 10 row(s), 1 high priority, oldest `2026-04-03 02:19:32`.',
                    'next_action' => 'Classify as typed-preview-needed unless a materialized read-only preview already exists.',
                ],
            ],
            'remediation_readiness' => [
                'sample_limit' => 200,
                'pending_typed_remediation_rows' => 3,
                'apply_preview_rows' => 1,
                'preview_only_rows' => 1,
                'supported_preview_operation_rows' => 1,
                'context_ready_without_preview_rows' => 1,
                'source_duplicate_id_candidates' => 1,
                'family_duplicate_id_candidates' => 1,
                'source_proposed_change_id_rows' => 1,
                'family_context_rows' => 1,
                'family_id_key_context_rows' => 1,
                'family_ids_context_rows' => 0,
                'family_comparison_context_rows' => 0,
                'without_materialized_ids' => 1,
                'malformed_details' => 0,
                'change_types' => [
                    'data_quality_review' => 1,
                    'date_quality_review' => 1,
                ],
                'possible_change_type_typos' => [
                    'date_quality_review' => [
                        'suggested_change_type' => 'data_quality_review',
                        'rows' => 1,
                    ],
                ],
                'supported_operations' => [
                    'source_duplicate_mark' => 1,
                ],
            ],
            'recommendations' => ['Review high-priority pending rows one at a time.'],
        ], $overrides);
    }

    private function nextTargetPayload(array $overrides = []): array
    {
        return array_merge([
            'version' => 1,
            'mode' => 'observe',
            'status' => 'review_required',
            'dry_run' => false,
            'queries_executed' => true,
            'query_state' => 'next_target_selected',
            'stale_days' => 7,
            'high_priority_threshold' => 8,
            'captured_at' => '2026-05-02T13:52:00Z',
            'next_target' => [
                'target_ref' => 'system_alert:target-abc123abc123',
                'review_type' => 'system_alert',
                'finding_type' => null,
                'classification' => 'high_priority_pending_review',
                'underlying_classification' => 'typed_preview_needed',
                'created_at' => '2026-04-03 02:19:32',
                'priority' => 8,
                'next_action' => 'Review one high-priority pending row first; classify only, and do not bulk approve or reject.',
                'underlying_next_action' => 'Classify as typed-preview-needed unless a materialized read-only preview already exists.',
                'evidence_flags' => [
                    'stale' => true,
                    'high_priority' => true,
                    'typed_remediation' => false,
                    'source_backed_context' => false,
                    'has_apply_preview' => false,
                    'preview_only' => false,
                    'supported_preview_operation' => false,
                    'context_ready_without_preview' => false,
                    'without_materialized_ids' => true,
                    'malformed_details' => false,
                    'possible_change_type_typo' => false,
                ],
            ],
        ], $overrides);
    }

    private function bindPartialNextTargetService(array $payload, ?string $focus): void
    {
        $service = Mockery::mock(ReviewBacklogReportService::class)->makePartial();
        $service->shouldReceive('nextTarget')
            ->once()
            ->with(7, 8, false, $focus)
            ->andReturn($payload);
        $service->shouldReceive('collect')->never();
        $this->app->instance(ReviewBacklogReportService::class, $service);
    }

    private function validationBlockedTypedRemediationNextTargetPayload(): array
    {
        return [
            'version' => 1,
            'mode' => 'observe',
            'status' => 'review_required',
            'dry_run' => false,
            'queries_executed' => true,
            'query_state' => 'next_target_selected',
            'stale_days' => 7,
            'high_priority_threshold' => 8,
            'captured_at' => '2026-05-02T13:52:00Z',
            'focus' => 'typed-remediation',
            'next_target' => [
                'target_ref' => 'genealogy_finding:target-a1b2c3d4e5f6',
                'review_type' => 'genealogy_finding',
                'finding_type' => 'source_duplicate_cleanup',
                'classification' => 'typed_preview_needed',
                'underlying_classification' => 'typed_preview_needed',
                'selection_reason' => 'focused_oldest_typed_remediation',
                'created_at' => '2037-04-20 12:00:00',
                'priority' => 9,
                'age_days' => 12,
                'age_bucket' => '8_30d',
                'stale_days_over_threshold' => 5,
                'next_action' => 'Review typed remediation readiness; materialization remains dry-run only when validation passes.',
                'title' => 'COMMAND SECRET VALIDATION TITLE',
                'summary' => 'COMMAND SECRET VALIDATION SUMMARY',
                'details' => [
                    'evidence_summary' => 'COMMAND SECRET VALIDATION DETAILS',
                    'source_locators' => ['https://example.test/command-validation-sensitive-source'],
                    'suspect_source_id' => 71001,
                    'retained_source_id' => 71002,
                    'raw_marker' => 'COMMAND-VALIDATION-RAW-MARKER',
                ],
                'token' => 'command-validation-blocked-token',
                'selector' => 'raw-selector-should-not-render',
                'remediation_materialization' => [
                    'available' => false,
                    'status' => 'validation_blocked',
                    'reason' => 'packet_validation_failed',
                    'missing_materialization_inputs' => [
                        'source_locator_required',
                        'privacy_clearance_required',
                    ],
                    'readiness_flags' => [
                        'packet_validation_ready' => false,
                        'packet_validation_error_count' => 2,
                        'materializable_remediation' => false,
                    ],
                    'source' => 'agent_review_queue',
                    'source_review_type' => 'genealogy_finding',
                    'target_review_type' => 'genealogy_review_packet',
                    'default_mode' => 'dry_run',
                    'dry_run_available' => true,
                    'dry_run_first' => true,
                    'operator_action' => 'materialize_typed_remediation_dry_run',
                    'selector_required' => true,
                    'selector_redacted' => true,
                    'execute_effect' => 'create_or_reuse_pending_genealogy_review_packet_only',
                    'operation_types' => ['source_duplicate_mark'],
                    'command' => 'genealogy:materialize-typed-remediation --token command-validation-blocked-token',
                    'dry_run_options' => ['--token' => 'command-validation-blocked-token'],
                    'execute_options' => ['--id' => 71001],
                    'safety' => [
                        'scope' => 'review_packet_materialization_only',
                        'preview_only' => true,
                        'canonical_write_allowed' => false,
                        'canonical_writes_performed' => false,
                        'no_canonical_write' => true,
                        'apply_held' => true,
                        'apply_enabled' => false,
                        'apply_performed' => false,
                    ],
                ],
            ],
        ];
    }

    private function noFocusCandidatesNextTargetPayload(string $focus): array
    {
        return [
            'version' => 1,
            'mode' => 'observe',
            'status' => 'observe_ok',
            'dry_run' => false,
            'queries_executed' => true,
            'query_state' => 'no_focus_candidates',
            'stale_days' => 7,
            'high_priority_threshold' => 8,
            'captured_at' => '2026-05-02T13:52:00Z',
            'focus' => $focus,
            'next_target' => null,
            'focus_summary' => [
                'schema' => 'review_backlog_focus_summary.v1',
                'scope' => 'aggregate_only',
                'focus' => $focus,
                'candidate_rows' => 2,
                'typed_remediation_rows' => 2,
                'materializable_rows' => 0,
                'dry_run_available_rows' => 1,
                'validation_blocked_rows' => 1,
                'unsupported_operation_rows' => 1,
                'without_materialized_ids_rows' => 2,
                'context_ready_without_preview_rows' => 1,
                'malformed_details_rows' => 0,
                'possible_change_type_typo_rows' => 0,
                'missing_materialization_input_counts' => [
                    'source_locator_required' => 1,
                    'privacy_clearance_required' => 1,
                ],
                'validation_blocker_code_counts' => [
                    'source_locator_required' => 1,
                    'privacy_clearance_required' => 1,
                ],
                'row_pointers_included' => false,
                'auth_markers_included' => false,
                'raw_ids_included' => false,
                'source_refs_included' => false,
                'commands_included' => false,
                'apply_controls_included' => false,
            ],
        ];
    }

    private function agedReviewNoFocusCandidatesNextTargetPayload(): array
    {
        return [
            'version' => 1,
            'mode' => 'observe',
            'status' => 'observe_ok',
            'dry_run' => false,
            'queries_executed' => true,
            'query_state' => 'no_focus_candidates',
            'stale_days' => 7,
            'high_priority_threshold' => 8,
            'captured_at' => '2026-05-02T13:52:00Z',
            'focus' => 'aged-review',
            'next_target' => null,
            'focus_summary' => [
                'schema' => 'review_backlog_focus_summary.v1',
                'scope' => 'aggregate_only',
                'focus' => 'aged-review',
                'candidate_rows' => 0,
                'stale_rows' => 0,
                'high_priority_rows' => 0,
                'age_bucket_counts' => [],
                'classification_counts' => [],
                'row_pointers_included' => false,
                'auth_markers_included' => false,
                'raw_ids_included' => false,
                'source_refs_included' => false,
                'commands_included' => false,
                'apply_controls_included' => false,
            ],
        ];
    }

    private function agedReviewNextTargetPayload(): array
    {
        return [
            'version' => 1,
            'mode' => 'observe',
            'status' => 'review_required',
            'dry_run' => false,
            'queries_executed' => true,
            'query_state' => 'next_target_selected',
            'stale_days' => 7,
            'high_priority_threshold' => 8,
            'captured_at' => '2026-05-07T22:34:10Z',
            'focus' => 'aged-review',
            'next_target' => [
                'target_ref' => 'system_alert:target-private-aged',
                'review_type' => 'system_alert',
                'finding_type' => null,
                'classification' => 'high_priority_pending_review',
                'underlying_classification' => 'stale_infrastructure_relevance_check',
                'selection_reason' => 'oldest_aged_review',
                'created_at' => '2026-04-02 22:19:32',
                'age_days' => 34,
                'age_bucket' => '31d_plus',
                'stale_days_over_threshold' => 27,
                'priority' => 8,
                'next_action' => 'COMMAND SECRET should not appear in compact output.',
                'underlying_next_action' => 'COMMAND SECRET underlying action should not appear.',
                'evidence_flags' => [
                    'stale' => true,
                    'high_priority' => true,
                    'age_bucket' => '31d_plus',
                    'stale_days_over_threshold' => 27,
                ],
            ],
            'focus_summary' => [
                'schema' => 'review_backlog_focus_summary.v1',
                'scope' => 'aggregate_only',
                'focus' => 'aged-review',
                'candidate_rows' => 35,
                'stale_rows' => 35,
                'high_priority_rows' => 1,
                'age_bucket_counts' => [
                    '31d_plus' => 2,
                    '8_30d' => 33,
                ],
                'classification_counts' => [
                    'actionable_or_obsolete_triage' => 3,
                    'high_priority_pending_review' => 1,
                    'source_backed_packet_needed' => 12,
                    'stale_infrastructure_relevance_check' => 1,
                    'typed_preview_needed' => 18,
                ],
                'row_pointers_included' => false,
                'auth_markers_included' => false,
                'raw_ids_included' => false,
                'source_refs_included' => false,
                'commands_included' => false,
                'apply_controls_included' => false,
            ],
            'token' => 'next-target-aged-private-token',
        ];
    }

    private function sourceBackedPacketNextTargetPayload(): array
    {
        return [
            'version' => 1,
            'mode' => 'observe',
            'status' => 'review_required',
            'dry_run' => false,
            'queries_executed' => true,
            'query_state' => 'next_target_selected',
            'stale_days' => 7,
            'high_priority_threshold' => 8,
            'captured_at' => '2026-05-02T13:52:00Z',
            'focus' => 'source-backed-packet',
            'next_target' => [
                'target_ref' => 'genealogy_review_packet:target-f4f6d3699424',
                'review_type' => 'genealogy_review_packet',
                'finding_type' => null,
                'classification' => 'source_backed_packet_review',
                'underlying_classification' => 'source_backed_packet_needed',
                'created_at' => '2037-05-01 10:00:00',
                'priority' => 1,
                'age_days' => 1,
                'age_bucket' => 'under_7d',
                'next_action' => 'Open source-backed packet in Review Hub and decide one packet at a time.',
                'review_pass' => [
                    'schema' => 'genealogy_review_packet_review_pass.v1',
                    'mode' => 'display_only',
                    'state' => 'ready',
                    'source_backed' => true,
                    'preview_only' => true,
                    'canonical_mutation' => false,
                    'posture' => [
                        'canonical_write_allowed' => false,
                        'batch_review_allowed' => false,
                        'automation_allowed' => false,
                        'details_included' => false,
                        'raw_identifiers_included' => false,
                        'tokens_included' => false,
                        'locators_included' => false,
                    ],
                ],
                'evidence_flags' => [
                    'stale' => false,
                    'high_priority' => false,
                    'source_backed_context' => true,
                    'preview_only' => true,
                    'canonical_mutation' => false,
                    'malformed_details' => false,
                ],
            ],
        ];
    }

    private function assertSourceBackedPacketCommandOutputIsRedacted(string $output): void
    {
        foreach ([
            'COMMAND SECRET GLOBAL TITLE',
            'COMMAND SECRET GLOBAL SUMMARY',
            'COMMAND SECRET GLOBAL DETAILS',
            'COMMAND SECRET PACKET TITLE',
            'COMMAND SECRET PACKET SUMMARY',
            'COMMAND SECRET PACKET CLAIM',
            'COMMAND SECRET SOURCE LABEL',
            'COMMAND SECRET BOUNDARY LABEL',
            'COMMAND SECRET PACKET DETAILS',
            'https://example.test/command-sensitive-source',
            'command-source-backed-global-token',
            'command-source-backed-packet-token',
            'RAW-PERSON-ID-COMMAND-268711',
            'RAW-SOURCE-ID-COMMAND-991122',
            'RAW-FAMILY-ID-COMMAND-773355',
            'source_locator',
            'source_locators',
            'person_id',
            'source_id',
            'family_id',
            'raw_secret_marker',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $output);
        }
    }

    private function assertTypedRemediationCommandOutputIsRedacted(string $output): void
    {
        foreach ([
            'COMMAND SECRET VALIDATION TITLE',
            'COMMAND SECRET VALIDATION SUMMARY',
            'COMMAND SECRET VALIDATION DETAILS',
            'COMMAND-VALIDATION-RAW-MARKER',
            'https://example.test/command-validation-sensitive-source',
            'command-validation-blocked-token',
            'raw-selector-should-not-render',
            '71001',
            '71002',
            'genealogy:materialize-typed-remediation',
            '--token',
            '--id',
            'execute_options',
            'dry_run_options',
            'selector=',
            'source_locators',
            'source_id',
            'retained_source_id',
            'suspect_source_id',
            'canonical_write_allowed=true',
            'apply_enabled=true',
            'apply_held=false',
            'create_or_reuse_pending_genealogy_review_packet_only',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $output);
        }
    }

    private function assertAgedReviewCompactOutputIsRedacted(string $output): void
    {
        foreach ([
            'system_alert:target-private-aged',
            'next-target-aged-private-token',
            'COMMAND SECRET',
            'target_ref=',
            'Target ref:',
            'genealogy:materialize-typed-remediation',
            '--execute',
            '--token',
            '--id',
            'execute_options',
            'dry_run_options',
            'apply_enabled=true',
            'canonical_write_allowed=true',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $output);
        }
    }
}
