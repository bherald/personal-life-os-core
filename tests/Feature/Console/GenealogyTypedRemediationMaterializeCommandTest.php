<?php

namespace Tests\Feature\Console;

use App\Services\Review\ReviewTargetReferenceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\PreservesSchemaTables;
use Tests\TestCase;

class GenealogyTypedRemediationMaterializeCommandTest extends TestCase
{
    use PreservesSchemaTables;

    private bool $queueTableExistedBeforeTest = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedSqliteDatabase();
        $this->queueTableExistedBeforeTest = Schema::hasTable('agent_review_queue');
        $this->preserveTables(['agent_review_queue']);
        $this->createCompatibleQueueTable();
    }

    protected function tearDown(): void
    {
        $this->restorePreservedTables();
        if (! $this->queueTableExistedBeforeTest) {
            Schema::dropIfExists('agent_review_queue');
        }

        parent::tearDown();
    }

    private function useIsolatedSqliteDatabase(): void
    {
        config()->set('database.connections.genealogy_typed_remediation_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('database.default', 'genealogy_typed_remediation_test');
        DB::purge('genealogy_typed_remediation_test');
        DB::setDefaultConnection('genealogy_typed_remediation_test');
    }

    public function test_default_dry_run_reports_materialization_without_inserting_packet(): void
    {
        $sourceId = $this->insertFinding($this->typedRemediationDetails());

        $payload = $this->callJson([
            '--id' => $sourceId,
            '--json' => true,
        ], 0);

        $this->assertTrue($payload['success']);
        $this->assertSame('dry_run', $payload['mode']);
        $this->assertFalse($payload['execute']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame('would_create_packet', $payload['action']);
        $this->assertSame($sourceId, $payload['source']['review_queue_id']);
        $this->assertSame('source-cleanup:smith-1', $payload['source']['source_dedup_key']);
        $this->assertSame(['source_duplicate_mark'], $payload['operation_types']);
        $this->assertSame('genealogy_review_packet', $payload['packet_summary']['target_review_type']);
        $this->assertSame(1, $payload['packet_summary']['source_locator_count']);
        $this->assertSame(1, $payload['packet_summary']['claim_count']);
        $this->assertTrue($payload['packet_summary']['identity_present']);
        $this->assertTrue($payload['packet_summary']['privacy_present']);
        $this->assertTrue($payload['packet_summary']['validation_valid']);
        $this->assertSame(0, $payload['packet_summary']['validation_error_count']);
        $this->assertSame(0, $payload['packet_summary']['validation_warning_count']);
        $this->assertTrue($payload['packet_summary']['preview_only']);
        $this->assertFalse($payload['packet_summary']['mutates_accepted_facts']);
        $this->assertTrue($payload['packet_summary']['dedup_key_present']);
        $this->assertTrue($payload['no_canonical_write']);
        $this->assertFalse($payload['canonical_writes_performed']);
        $this->assertTrue($payload['apply_held']);
        $this->assertFalse($payload['apply_performed']);
        $this->assertTrue($payload['safety']['no_canonical_write']);
        $this->assertFalse($payload['safety']['canonical_writes_performed']);
        $this->assertTrue($payload['safety']['apply_held']);
        $this->assertFalse($payload['safety']['apply_enabled']);
        $this->assertFalse($payload['safety']['apply_performed']);
        $this->assertSame(1, $payload['typed_remediation_preview']['operation_count']);
        $this->assertSame(0, $this->packetCount());
    }

    public function test_compact_dry_run_reports_sanitized_summary_without_inserting_packet(): void
    {
        $sourceId = $this->insertFinding($this->typedRemediationDetails());

        $payload = $this->callJson([
            '--id' => $sourceId,
            '--json' => true,
            '--compact' => true,
        ], 0);

        $this->assertTrue($payload['success']);
        $this->assertSame('dry_run', $payload['mode']);
        $this->assertSame('would_create_packet', $payload['action']);
        $this->assertSame(['source_duplicate_mark'], $payload['operation_types']);
        $this->assertSame(['type' => 'id', 'value_present' => true], $payload['selection']);
        $this->assertSame([
            'present' => true,
            'review_type' => 'genealogy_finding',
            'status' => 'pending',
            'finding_type' => 'source_duplicate_cleanup',
            'source_dedup_key_present' => true,
        ], $payload['source']);
        $this->assertSame([
            'target_review_type' => 'genealogy_review_packet',
            'source_locator_count' => 1,
            'claim_count' => 1,
            'identity_present' => true,
            'target_context_present' => true,
            'target_context_types' => ['person'],
            'privacy_present' => true,
            'validation_valid' => true,
            'validation_error_count' => 0,
            'validation_warning_count' => 0,
            'preview_only' => true,
            'mutates_accepted_facts' => false,
            'dedup_key_present' => true,
        ], $payload['packet_summary']);
        $this->assertNull($payload['packet']);
        $this->assertTrue($payload['safety']['no_canonical_write']);
        $this->assertFalse($payload['safety']['canonical_write_allowed']);
        $this->assertTrue($payload['safety']['apply_held']);
        $this->assertFalse($payload['safety']['apply_enabled']);
        $this->assertSame('preview_only', $payload['typed_remediation_preview']['status']);
        $this->assertFalse($payload['typed_remediation_preview']['mutates_accepted_facts']);
        $this->assertSame(0, $payload['typed_remediation_preview']['accepted_fact_mutation_count']);
        $this->assertSame(1, $payload['typed_remediation_preview']['operation_count']);
        $this->assertSame(['source_duplicate_mark' => 1], $payload['typed_remediation_preview']['operation_type_counts']);
        $this->assertSame(['blocked' => 1], $payload['typed_remediation_preview']['operation_status_counts']);
        $this->assertSame(['fail' => 3], $payload['typed_remediation_preview']['guard_status_counts']);
        $this->assertSame(['distinct_sources', 'required_ids', 'sources_exist'], $payload['typed_remediation_preview']['failed_guard_names']);
        $this->assertSame(['mark_source_duplicate_preview_only' => 1], $payload['typed_remediation_preview']['proposed_effect_type_counts']);
        $this->assertSame(0, $payload['typed_remediation_preview']['proposed_effect_row_touch_count']);
        $this->assertSame(0, $this->packetCount());
    }

    public function test_compact_dry_run_accepts_sanitized_target_ref_without_raw_selector(): void
    {
        $sourceId = $this->insertFinding($this->typedRemediationDetails(), token: 'source-token-target-ref');
        $targetRef = $this->targetRefForSourceId($sourceId);

        $payload = $this->callJson([
            '--target-ref' => $targetRef,
            '--json' => true,
            '--compact' => true,
        ], 0);

        $this->assertTrue($payload['success']);
        $this->assertSame('dry_run', $payload['mode']);
        $this->assertSame('would_create_packet', $payload['action']);
        $this->assertSame(['type' => 'target_ref', 'value_present' => true], $payload['selection']);
        $this->assertSame('source_duplicate_cleanup', $payload['source']['finding_type']);
        $this->assertSame(['source_duplicate_mark'], $payload['operation_types']);
        $this->assertTrue($payload['packet_summary']['validation_valid']);
        $this->assertTrue($payload['safety']['no_canonical_write']);
        $this->assertTrue($payload['safety']['apply_held']);
        $this->assertSame(0, $this->packetCount());

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ([
            'source-token-target-ref',
            'source-cleanup:smith-1',
            'https://archive.org/details/source-cleanup',
            'Smith',
        ] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $encoded);
        }
    }

    public function test_dry_run_blocks_when_packet_validation_would_fail_without_inserting_packet(): void
    {
        config()->set('scraping.manual_only_domains', ['ancestry.com']);
        $details = $this->typedRemediationDetails();
        $details['source_locators'] = ['https://www.ancestry.com/genealogy/records/manual-only'];
        $sourceId = $this->insertFinding($details, token: 'manual-only-source-token');

        $payload = $this->callJson([
            '--id' => $sourceId,
            '--json' => true,
            '--compact' => true,
        ], 1);

        $this->assertFalse($payload['success']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('packet_validation_failed', $payload['error']);
        $this->assertSame('none', $payload['action']);
        $this->assertNull($payload['packet']);
        $this->assertSame('genealogy_review_packet', $payload['packet_summary']['target_review_type']);
        $this->assertSame(1, $payload['packet_summary']['source_locator_count']);
        $this->assertFalse($payload['packet_summary']['validation_valid']);
        $this->assertSame(1, $payload['packet_summary']['validation_error_count']);
        $this->assertTrue($payload['packet_summary']['preview_only']);
        $this->assertTrue($payload['safety']['no_canonical_write']);
        $this->assertTrue($payload['safety']['apply_held']);
        $this->assertFalse($payload['validation']['valid']);
        $this->assertSame(1, $payload['validation']['blocker_count']);
        $this->assertSame(['manual_source_as_evidence_blocked'], $payload['validation']['blocker_codes']);
        $this->assertArrayNotHasKey('errors', $payload['validation']);
        $this->assertArrayNotHasKey('gate', $payload['validation']);
        $this->assertSame(0, $this->packetCount());

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ([
            'manual-only-source-token',
            'source-cleanup:smith-1',
            'https://www.ancestry.com/genealogy/records/manual-only',
            'Two source records describe the same Smith family record.',
            'Manual sources cannot be used as evidence',
            'genealogy:materialize-typed-remediation',
            'execute_options',
            'dry_run_options',
            'approved apply path exists',
        ] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $encoded);
        }
    }

    public function test_compact_validation_blocked_json_reports_aggregate_blocker_codes_only(): void
    {
        $details = $this->typedRemediationDetails();
        unset($details['privacy'], $details['source_locators']);
        $sourceId = $this->insertFinding($details, token: 'validation-summary-source-token');

        $payload = $this->callJson([
            '--id' => $sourceId,
            '--json' => true,
            '--compact' => true,
        ], 1);

        $this->assertFalse($payload['success']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('packet_validation_failed', $payload['error']);
        $this->assertSame([
            'valid' => false,
            'blocker_count' => 2,
            'blocker_codes' => [
                'source_locator_required',
                'privacy_clearance_required',
            ],
        ], $payload['validation']);
        $this->assertSame(0, $this->packetCount());

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ([
            'validation-summary-source-token',
            'source-cleanup:smith-1',
            'Two source records describe the same Smith family record.',
            'errors',
            'gate',
            'message',
            'details',
            'claim_text',
            'evidence_summary',
            'genealogy:materialize-typed-remediation',
            'execute_options',
            'dry_run_options',
            'rows_that_would_be_touched',
            'approved apply path exists',
        ] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $encoded);
        }
    }

    public function test_compact_dry_run_reuse_existing_packet_omits_packet_identifiers(): void
    {
        $sourceId = $this->insertFinding($this->typedRemediationDetails(), token: 'source-token-compact-reuse');

        $created = $this->callJson([
            '--id' => $sourceId,
            '--execute' => true,
            '--json' => true,
        ], 0);
        $payload = $this->callJson([
            '--id' => $sourceId,
            '--json' => true,
            '--compact' => true,
        ], 0);

        $this->assertSame('created_packet', $created['action']);
        $this->assertSame('would_reuse_existing_packet', $payload['action']);
        $this->assertSame([
            'present' => true,
            'materialized_existing' => true,
        ], $payload['packet']);
        $this->assertArrayNotHasKey('review_queue_id', $payload['packet']);
        $this->assertArrayNotHasKey('token', $payload['packet']);
        $this->assertSame(1, $this->packetCount());
    }

    public function test_dry_run_does_not_mutate_review_queue_or_canonical_genealogy_tables(): void
    {
        $sourceId = $this->insertFinding($this->typedRemediationDetails());

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $payload = $this->callJson([
                '--id' => $sourceId,
                '--json' => true,
            ], 0);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($payload['success']);
        $this->assertSame('would_create_packet', $payload['action']);
        $this->assertSame([], $this->mutationTargets($queries));
        $this->assertNoCanonicalGenealogyMutationQueries($queries);
        $this->assertSame(0, $this->packetCount());
    }

    public function test_compact_json_recursively_omits_sensitive_preview_fields(): void
    {
        $sourceId = $this->insertFinding($this->typedRemediationDetails());

        $payload = $this->callJson([
            '--id' => $sourceId,
            '--json' => true,
            '--compact' => true,
        ], 0);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('https://archive.org/details/source-cleanup', $encoded);
        $this->assertStringNotContainsString('source-finding-token-1', $encoded);
        $this->assertStringNotContainsString('source-cleanup:smith-1', $encoded);
        $this->assertStringNotContainsString('Smith', $encoded);
        $this->assertStringNotContainsString('321', $encoded);
        $this->assertStringNotContainsString('Two source records describe the same Smith family record.', $encoded);
        $this->assertPayloadRecursivelyLacksKeys($payload, [
            'id',
            'token',
            'details',
            'current_state',
            'stale_hash',
            'source_locators',
            'source_dedup_key',
            'review_queue_id',
            'person_id',
            'source_id',
            'suspect_source_id',
            'retained_source_id',
            'proposed_change_ids',
            'rows_that_would_be_touched',
        ]);
    }

    public function test_compact_text_output_is_sanitized(): void
    {
        $sourceId = $this->insertFinding($this->typedRemediationDetails());

        $output = $this->callText([
            '--id' => $sourceId,
            '--compact' => true,
        ], 0);

        $this->assertStringContainsString('status=dry_run', $output);
        $this->assertStringContainsString('action=would_create_packet', $output);
        $this->assertStringContainsString('operation_types=source_duplicate_mark', $output);
        $this->assertStringContainsString('source_locators=1', $output);
        $this->assertStringContainsString('claims=1', $output);
        $this->assertStringContainsString('validation_blockers=0', $output);
        $this->assertStringContainsString('blocker_codes=none', $output);
        $this->assertStringContainsString('preview_only=yes', $output);
        $this->assertStringContainsString('operation_statuses=blocked:1', $output);
        $this->assertStringContainsString('guards=fail:3', $output);
        $this->assertStringContainsString('failed_guards=distinct_sources,required_ids,sources_exist', $output);
        $this->assertStringContainsString('row_touches=0', $output);
        $this->assertStringContainsString('no_canonical_write=yes', $output);
        $this->assertStringContainsString('apply_held=yes', $output);
        $this->assertStringNotContainsString('source-finding-token-1', $output);
        $this->assertStringNotContainsString('source-cleanup:smith-1', $output);
        $this->assertStringNotContainsString('https://archive.org/details/source-cleanup', $output);
        $this->assertStringNotContainsString('Two source records describe', $output);
        $this->assertStringNotContainsString('current_state', $output);
        $this->assertStringNotContainsString('review_queue_id', $output);
    }

    public function test_validation_blocked_compact_text_reports_sanitized_blocker_codes_only(): void
    {
        config()->set('scraping.manual_only_domains', ['ancestry.com']);
        $details = $this->typedRemediationDetails();
        $details['source_locators'] = ['https://www.ancestry.com/genealogy/records/manual-only-text'];
        $sourceId = $this->insertFinding($details, token: 'manual-only-text-source-token');

        $output = $this->callText([
            '--id' => $sourceId,
            '--compact' => true,
        ], 1);

        $this->assertStringContainsString('status=blocked', $output);
        $this->assertStringContainsString('action=none', $output);
        $this->assertStringContainsString('validation_blockers=1', $output);
        $this->assertStringContainsString('blocker_codes=manual_source_as_evidence_blocked', $output);
        $this->assertStringContainsString('no_canonical_write=yes', $output);
        $this->assertStringContainsString('apply_held=yes', $output);
        $this->assertSame(0, $this->packetCount());

        foreach ([
            'manual-only-text-source-token',
            'source-cleanup:smith-1',
            'https://www.ancestry.com/genealogy/records/manual-only-text',
            'Two source records describe',
            'Manual sources cannot be used as evidence',
            'genealogy:materialize-typed-remediation',
            '--execute',
            'execute_options',
            'dry_run_options',
            'approved apply path exists',
            'review_queue_id',
        ] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $output);
        }
    }

    public function test_validation_blocked_default_text_reports_sanitized_blocker_summary(): void
    {
        config()->set('scraping.manual_only_domains', ['ancestry.com']);
        $details = $this->typedRemediationDetails();
        $details['source_locators'] = ['https://www.ancestry.com/genealogy/records/manual-only-default-text'];
        $sourceId = $this->insertFinding($details, token: 'manual-only-default-text-source-token');

        $output = $this->callText([
            '--id' => $sourceId,
        ], 1);

        $this->assertStringContainsString('Genealogy typed remediation materialization: status=blocked mode=dry_run action=none', $output);
        $this->assertStringContainsString('Validation blockers: count=1 codes=manual_source_as_evidence_blocked', $output);
        $this->assertStringContainsString('no_canonical_write=yes', $output);
        $this->assertStringContainsString('apply_held=yes', $output);
        $this->assertSame(0, $this->packetCount());

        foreach ([
            'manual-only-default-text-source-token',
            'source-cleanup:smith-1',
            'https://www.ancestry.com/genealogy/records/manual-only-default-text',
            'Two source records describe',
            'Manual sources cannot be used as evidence',
            'genealogy:materialize-typed-remediation',
            '--execute',
            'execute_options',
            'dry_run_options',
            'approved apply path exists',
            'review_queue_id',
        ] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $output);
        }
    }

    public function test_execute_materializes_then_reuses_one_pending_packet(): void
    {
        $sourceId = $this->insertFinding($this->typedRemediationDetails(), token: 'source-token-execute');

        $first = $this->callJson([
            '--id' => $sourceId,
            '--execute' => true,
            '--json' => true,
        ], 0);
        $second = $this->callJson([
            '--token' => 'source-token-execute',
            '--execute' => true,
            '--json' => true,
        ], 0);

        $this->assertTrue($first['success']);
        $this->assertSame('execute', $first['mode']);
        $this->assertSame('created_packet', $first['action']);
        $this->assertFalse($first['packet']['materialized_existing']);
        $this->assertTrue($first['safety']['no_canonical_write']);
        $this->assertTrue($first['safety']['apply_held']);

        $this->assertTrue($second['success']);
        $this->assertSame('reused_existing_packet', $second['action']);
        $this->assertTrue($second['packet']['materialized_existing']);
        $this->assertSame($first['packet']['review_queue_id'], $second['packet']['review_queue_id']);
        $this->assertSame(1, $this->packetCount());

        $packet = DB::table('agent_review_queue')->where('id', $first['packet']['review_queue_id'])->first();
        $this->assertNotNull($packet);
        $this->assertSame('genealogy_review_packet', $packet->review_type);
        $this->assertSame('pending', $packet->status);

        $details = json_decode((string) $packet->details, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($sourceId, $details['packet']['materialization']['source_review_queue_id']);
        $this->assertFalse($details['packet']['materialization']['writeback']);
        $this->assertFalse($details['packet']['materialization']['apply_enabled']);
        $this->assertFalse($details['apply_preview']['mutates_accepted_facts']);
    }

    public function test_compact_execute_created_packet_omits_selectors_and_packet_identifiers(): void
    {
        $this->insertFinding($this->typedRemediationDetails(), token: 'source-token-compact-execute');

        $payload = $this->callJson([
            '--token' => 'source-token-compact-execute',
            '--execute' => true,
            '--json' => true,
            '--compact' => true,
        ], 0);

        $this->assertTrue($payload['success']);
        $this->assertSame('execute', $payload['mode']);
        $this->assertTrue($payload['execute']);
        $this->assertFalse($payload['dry_run']);
        $this->assertSame('created_packet', $payload['action']);
        $this->assertSame(['type' => 'token', 'value_present' => true], $payload['selection']);
        $this->assertSame([
            'present' => true,
            'materialized_existing' => false,
        ], $payload['packet']);
        $this->assertSame('genealogy_review_packet', $payload['packet_summary']['target_review_type']);
        $this->assertSame(1, $payload['packet_summary']['source_locator_count']);
        $this->assertTrue($payload['packet_summary']['preview_only']);
        $this->assertFalse($payload['packet_summary']['mutates_accepted_facts']);
        $this->assertTrue($payload['safety']['no_canonical_write']);
        $this->assertFalse($payload['safety']['canonical_write_allowed']);
        $this->assertTrue($payload['safety']['apply_held']);
        $this->assertFalse($payload['safety']['apply_enabled']);
        $this->assertSame(1, $this->packetCount());

        $packet = DB::table('agent_review_queue')
            ->where('review_type', 'genealogy_review_packet')
            ->first();
        $this->assertNotNull($packet);
        $this->assertNotNull($packet->token);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ([
            (string) $packet->token,
            'source-token-compact-execute',
            'source-cleanup:smith-1',
            'https://archive.org/details/source-cleanup',
            'Two source records describe the same Smith family record.',
            'Smith',
            '321',
        ] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $encoded);
        }

        $this->assertPayloadRecursivelyLacksKeys($payload, [
            'id',
            'token',
            'details',
            'current_state',
            'stale_hash',
            'source_locators',
            'source_dedup_key',
            'review_queue_id',
            'source_review_queue_id',
            'person_id',
            'source_id',
            'suspect_source_id',
            'retained_source_id',
            'proposed_change_ids',
            'rows_that_would_be_touched',
        ]);
    }

    public function test_compact_execute_accepts_target_ref_and_omits_selectors(): void
    {
        $sourceId = $this->insertFinding($this->typedRemediationDetails(), token: 'source-token-target-ref-execute');
        $targetRef = $this->targetRefForSourceId($sourceId);

        $payload = $this->callJson([
            '--target-ref' => $targetRef,
            '--execute' => true,
            '--json' => true,
            '--compact' => true,
        ], 0);

        $this->assertTrue($payload['success']);
        $this->assertSame('execute', $payload['mode']);
        $this->assertSame('created_packet', $payload['action']);
        $this->assertSame(['type' => 'target_ref', 'value_present' => true], $payload['selection']);
        $this->assertSame([
            'present' => true,
            'materialized_existing' => false,
        ], $payload['packet']);
        $this->assertSame(1, $this->packetCount());

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ([
            'source-token-target-ref-execute',
            $targetRef,
            'source-cleanup:smith-1',
            'https://archive.org/details/source-cleanup',
        ] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $encoded);
        }

        $this->assertPayloadRecursivelyLacksKeys($payload, [
            'id',
            'token',
            'details',
            'review_queue_id',
            'source_review_queue_id',
            'source_dedup_key',
            'source_locators',
            'rows_that_would_be_touched',
        ]);
    }

    public function test_data_quality_advisory_compact_dry_run_reports_research_task_preview(): void
    {
        $sourceId = $this->insertFinding(
            [
                'dedup_key' => 'data-quality:family-610',
                'tree_id' => 4,
                'person_id' => 2766,
                'privacy' => ['cleared' => true, 'status' => 'cleared'],
                'source_locators' => ['https://archive.org/details/data-quality-review'],
                'evidence_summary' => 'Family 610 appears to duplicate family 602 and needs research before repair.',
            ],
            token: 'data-quality-source-token',
            findingType: 'genealogy_data_quality',
        );

        $payload = $this->callJson([
            '--id' => $sourceId,
            '--json' => true,
            '--compact' => true,
        ], 0);

        $this->assertTrue($payload['success']);
        $this->assertSame('dry_run', $payload['mode']);
        $this->assertSame('would_create_packet', $payload['action']);
        $this->assertSame(['genealogy_todo_create'], $payload['operation_types']);
        $this->assertSame('genealogy_data_quality', $payload['source']['finding_type']);
        $this->assertSame(['genealogy_todo_create' => 1], $payload['typed_remediation_preview']['operation_type_counts']);
        $this->assertSame(['preview_only' => 1], $payload['typed_remediation_preview']['operation_status_counts']);
        $this->assertSame(['pass' => 2], $payload['typed_remediation_preview']['guard_status_counts']);
        $this->assertSame(['create_genealogy_research_task_preview_only' => 1], $payload['typed_remediation_preview']['proposed_effect_type_counts']);
        $this->assertSame(0, $payload['typed_remediation_preview']['proposed_effect_row_touch_count']);
        $this->assertSame(0, $this->packetCount());

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('data-quality-source-token', $encoded);
        $this->assertStringNotContainsString('Family 610 appears', $encoded);
        $this->assertStringNotContainsString('https://archive.org/details/data-quality-review', $encoded);
    }

    public function test_family_scoped_data_quality_advisory_compact_dry_run_does_not_require_person_identity(): void
    {
        $sourceId = $this->insertFinding(
            [
                'dedup_key' => 'data-quality:family-610-no-person-command',
                'tree_id' => 4,
                'family_id' => 610,
                'privacy' => ['cleared' => true, 'status' => 'cleared'],
                'source_locators' => ['https://archive.org/details/data-quality-family-command'],
                'evidence_summary' => 'Family 610 appears to duplicate family 602 and needs research before repair.',
            ],
            token: 'data-quality-family-command-token',
            findingType: 'genealogy_data_quality',
        );

        $payload = $this->callJson([
            '--id' => $sourceId,
            '--json' => true,
            '--compact' => true,
        ], 0);

        $this->assertTrue($payload['success']);
        $this->assertSame('dry_run', $payload['mode']);
        $this->assertSame('would_create_packet', $payload['action']);
        $this->assertSame(['genealogy_todo_create'], $payload['operation_types']);
        $this->assertFalse($payload['packet_summary']['identity_present']);
        $this->assertTrue($payload['packet_summary']['target_context_present']);
        $this->assertSame(['tree', 'family'], $payload['packet_summary']['target_context_types']);
        $this->assertTrue($payload['packet_summary']['validation_valid']);
        $this->assertSame(0, $payload['validation']['blocker_count']);
        $this->assertSame(['pass' => 2], $payload['typed_remediation_preview']['guard_status_counts']);
        $this->assertSame(0, $payload['typed_remediation_preview']['proposed_effect_row_touch_count']);
        $this->assertSame(0, $this->packetCount());

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ([
            'data-quality-family-command-token',
            'data-quality:family-610-no-person-command',
            'Family 610 appears',
            'https://archive.org/details/data-quality-family-command',
            '610',
        ] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $encoded);
        }
    }

    public function test_data_quality_advisory_compact_dry_run_accepts_target_ref_without_selector_leakage_or_insert(): void
    {
        $sourceId = $this->insertFinding(
            [
                'dedup_key' => 'data-quality:family-610-target-ref',
                'tree_id' => 4,
                'person_id' => 2766,
                'privacy' => ['cleared' => true, 'status' => 'cleared'],
                'source_locators' => ['https://archive.org/details/data-quality-target-ref'],
                'evidence_summary' => 'Family 610 appears to duplicate family 602 and needs research before repair.',
            ],
            token: 'data-quality-source-token-target-ref',
            findingType: 'genealogy_data_quality',
        );
        $targetRef = $this->targetRefForSourceId($sourceId);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $payload = $this->callJson([
                '--target-ref' => $targetRef,
                '--json' => true,
                '--compact' => true,
            ], 0);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($payload['success']);
        $this->assertSame('dry_run', $payload['mode']);
        $this->assertSame('would_create_packet', $payload['action']);
        $this->assertSame(['type' => 'target_ref', 'value_present' => true], $payload['selection']);
        $this->assertSame(['genealogy_todo_create'], $payload['operation_types']);
        $this->assertSame('genealogy_data_quality', $payload['source']['finding_type']);
        $this->assertSame('genealogy_review_packet', $payload['packet_summary']['target_review_type']);
        $this->assertTrue($payload['packet_summary']['validation_valid']);
        $this->assertTrue($payload['packet_summary']['preview_only']);
        $this->assertFalse($payload['packet_summary']['mutates_accepted_facts']);
        $this->assertNull($payload['packet']);
        $this->assertTrue($payload['safety']['no_canonical_write']);
        $this->assertFalse($payload['safety']['canonical_write_allowed']);
        $this->assertTrue($payload['safety']['apply_held']);
        $this->assertFalse($payload['safety']['apply_enabled']);
        $this->assertSame(['genealogy_todo_create' => 1], $payload['typed_remediation_preview']['operation_type_counts']);
        $this->assertSame(['preview_only' => 1], $payload['typed_remediation_preview']['operation_status_counts']);
        $this->assertSame(['pass' => 2], $payload['typed_remediation_preview']['guard_status_counts']);
        $this->assertSame(['create_genealogy_research_task_preview_only' => 1], $payload['typed_remediation_preview']['proposed_effect_type_counts']);
        $this->assertSame(0, $payload['typed_remediation_preview']['proposed_effect_row_touch_count']);
        $this->assertSame(0, $this->packetCount());
        $this->assertSame([], $this->mutationTargets($queries));
        $this->assertNoCanonicalGenealogyMutationQueries($queries);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ([
            'data-quality-source-token-target-ref',
            $targetRef,
            'data-quality:family-610-target-ref',
            'Family 610 appears',
            'https://archive.org/details/data-quality-target-ref',
            '2766',
        ] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $encoded);
        }
    }

    public function test_missing_and_invalid_selection_fails_without_insert(): void
    {
        $sourceId = $this->insertFinding($this->typedRemediationDetails(), token: 'source-token-selection');

        $missing = $this->callJson(['--json' => true], 2);
        $both = $this->callJson([
            '--id' => $sourceId,
            '--token' => 'source-token-selection',
            '--json' => true,
        ], 2);
        $badId = $this->callJson([
            '--id' => 0,
            '--json' => true,
        ], 2);
        $badTargetRef = $this->callJson([
            '--target-ref' => 'genealogy_review_packet:target-123456789abc',
            '--json' => true,
        ], 2);
        $notFound = $this->callJson([
            '--token' => 'missing-source-token',
            '--json' => true,
        ], 1);

        $this->assertSame('invalid_selection', $missing['error']);
        $this->assertSame('invalid_selection', $both['error']);
        $this->assertSame('invalid_selection', $badId['error']);
        $this->assertSame('invalid_selection', $badTargetRef['error']);
        $this->assertSame('source_row_not_found', $notFound['error']);
        $this->assertSame(0, $this->packetCount());
    }

    public function test_compact_failure_paths_omit_selector_values_and_row_identifiers(): void
    {
        $sourceId = $this->insertFinding($this->typedRemediationDetails(), token: 'source-token-selection');

        $both = $this->callJson([
            '--id' => $sourceId,
            '--token' => 'source-token-selection',
            '--json' => true,
            '--compact' => true,
        ], 2);
        $notFound = $this->callJson([
            '--token' => 'missing-source-token-sensitive',
            '--json' => true,
            '--compact' => true,
        ], 1);
        $approvedId = $this->insertFinding($this->typedRemediationDetails(), status: 'approved', token: 'source-token-approved-target-ref');
        $approvedTargetRef = $this->targetRefForSourceId($approvedId);
        $targetRefNotFound = $this->callJson([
            '--target-ref' => $approvedTargetRef,
            '--json' => true,
            '--compact' => true,
        ], 1);
        $text = $this->callText([
            '--token' => 'missing-source-token-sensitive',
            '--compact' => true,
        ], 1);

        $this->assertSame('invalid_selection', $both['error']);
        $this->assertSame(['type' => null, 'value_present' => false], $both['selection']);
        $this->assertSame('source_row_not_found', $notFound['error']);
        $this->assertSame(['type' => 'token', 'value_present' => true], $notFound['selection']);
        $this->assertNull($notFound['source']);
        $this->assertSame('source_row_not_found', $targetRefNotFound['error']);
        $this->assertSame(['type' => 'target_ref', 'value_present' => true], $targetRefNotFound['selection']);
        $this->assertNull($targetRefNotFound['source']);
        $this->assertSame(0, $this->packetCount());

        $encoded = json_encode([$both, $notFound, $targetRefNotFound], JSON_THROW_ON_ERROR);
        foreach ([
            'source-token-selection',
            'missing-source-token-sensitive',
            'source-token-approved-target-ref',
            $approvedTargetRef,
            'review_queue_id',
        ] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $encoded);
            $this->assertStringNotContainsString($sensitive, $text);
        }
    }

    public function test_unsupported_row_reports_failure_without_insert(): void
    {
        $sourceId = $this->insertFinding([
            'dedup_key' => 'unsupported-remediation',
            'person_id' => 321,
            'privacy' => ['cleared' => true],
            'source_locators' => ['https://archive.org/details/no-remediation'],
            'claim_text' => 'This row has no supported remediation operation.',
        ]);

        $payload = $this->callJson([
            '--id' => $sourceId,
            '--execute' => true,
            '--json' => true,
        ], 1);

        $this->assertFalse($payload['success']);
        $this->assertSame('unsupported_typed_remediation', $payload['error']);
        $this->assertSame('none', $payload['action']);
        $this->assertSame($sourceId, $payload['source']['review_queue_id']);
        $this->assertTrue($payload['safety']['no_canonical_write']);
        $this->assertTrue($payload['safety']['apply_held']);
        $this->assertSame(0, $this->packetCount());
    }

    private function callJson(array $parameters, int $expectedExitCode): array
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call('genealogy:materialize-typed-remediation', $parameters, $output);
        $contents = $output->fetch();

        $this->assertSame($expectedExitCode, $exitCode, $contents);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    private function callText(array $parameters, int $expectedExitCode): string
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call('genealogy:materialize-typed-remediation', $parameters, $output);
        $contents = $output->fetch();

        $this->assertSame($expectedExitCode, $exitCode, $contents);

        return $contents;
    }

    private function assertPayloadRecursivelyLacksKeys(array $payload, array $forbiddenKeys): void
    {
        foreach ($payload as $key => $value) {
            $this->assertNotContains($key, $forbiddenKeys, "Forbidden key [{$key}] was emitted.");

            if (is_array($value)) {
                $this->assertPayloadRecursivelyLacksKeys($value, $forbiddenKeys);
            }
        }
    }

    private function insertFinding(
        array $details,
        string $status = 'pending',
        string $token = 'source-finding-token-1',
        string $findingType = 'source_duplicate_cleanup',
    ): int {
        return (int) DB::table('agent_review_queue')->insertGetId([
            'agent_id' => 'genealogy-agent',
            'review_type' => 'genealogy_finding',
            'finding_type' => $findingType,
            'title' => 'Source duplicate cleanup advisory',
            'summary' => 'Review duplicate source remediation before any operator action.',
            'details' => json_encode($details),
            'confidence' => 0.91,
            'priority' => 1,
            'status' => $status,
            'token' => $token,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function typedRemediationDetails(): array
    {
        return [
            'dedup_key' => 'source-cleanup:smith-1',
            'person_id' => 321,
            'privacy' => ['cleared' => true, 'status' => 'cleared'],
            'source_locators' => ['https://archive.org/details/source-cleanup'],
            'operation_type' => 'source_duplicate_mark',
            'evidence_summary' => 'Two source records describe the same Smith family record.',
        ];
    }

    private function packetCount(): int
    {
        return DB::table('agent_review_queue')
            ->where('review_type', 'genealogy_review_packet')
            ->count();
    }

    private function targetRefForSourceId(int $sourceId): string
    {
        $row = DB::table('agent_review_queue')->where('id', $sourceId)->first();
        $this->assertNotNull($row);

        return app(ReviewTargetReferenceService::class)->forReviewRow($row, 'genealogy_finding');
    }

    /**
     * @param  array<int, array<string, mixed>>  $queries
     * @return list<string>
     */
    private function mutationTargets(array $queries): array
    {
        $targets = [];

        foreach ($queries as $query) {
            $sql = strtolower((string) ($query['query'] ?? ''));
            if (
                preg_match(
                    '/^\s*(?:insert\s+into|replace\s+into|update|delete\s+from|alter\s+table|drop\s+table|create\s+table)\s+[`"\[]?([a-z0-9_]+)/',
                    $sql,
                    $matches
                ) === 1
            ) {
                $targets[] = $matches[1];
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * @param  array<int, array<string, mixed>>  $queries
     */
    private function assertNoCanonicalGenealogyMutationQueries(array $queries): void
    {
        $mutations = [];

        foreach ($queries as $query) {
            $sql = strtolower((string) ($query['query'] ?? ''));
            if (
                preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/', $sql) === 1
                && preg_match('/\bgenealogy_/', $sql) === 1
            ) {
                $mutations[] = $query['query'];
            }
        }

        $this->assertSame([], $mutations);
    }

    private function createCompatibleQueueTable(): void
    {
        Schema::create('agent_review_queue', function (Blueprint $table): void {
            $table->id();
            $table->string('agent_id', 100);
            $table->string('review_type', 50);
            $table->string('finding_type', 80)->nullable();
            $table->string('title', 500);
            $table->text('summary');
            $table->json('details')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->string('status', 20)->default('pending');
            $table->text('reviewer_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('token', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
}
