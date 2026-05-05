<?php

namespace Tests\Feature\Console;

use App\Services\Genealogy\GenealogyEvidenceSprintReadinessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\PreservesSchemaTables;
use Tests\TestCase;

class GenealogyReviewPacketMaterializeCommandTest extends TestCase
{
    use PreservesSchemaTables;

    /** @var list<string> */
    private array $tempFiles = [];

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
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->restorePreservedTables();
        if (! $this->queueTableExistedBeforeTest) {
            Schema::dropIfExists('agent_review_queue');
        }

        parent::tearDown();
    }

    private function useIsolatedSqliteDatabase(): void
    {
        config()->set('database.connections.genealogy_review_packet_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('database.default', 'genealogy_review_packet_test');
        DB::purge('genealogy_review_packet_test');
        DB::setDefaultConnection('genealogy_review_packet_test');
    }

    public function test_default_dry_run_reports_would_create_without_inserting_packet(): void
    {
        $path = $this->packetFile($this->validPacket());

        $payload = $this->callJson([
            '--file' => $path,
            '--json' => true,
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('dry_run', $payload['mode']);
        $this->assertFalse($payload['execute']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame('would_create_packet', $payload['action']);
        $this->assertTrue($payload['validation']['valid']);
        $this->assertSame('genealogy_review_packet', $payload['packet_summary']['target_review_type']);
        $this->assertSame(1, $payload['packet_summary']['source_locator_count']);
        $this->assertSame(1, $payload['packet_summary']['claim_count']);
        $this->assertTrue($payload['packet_summary']['identity_present']);
        $this->assertTrue($payload['packet_summary']['privacy_present']);
        $this->assertTrue($payload['packet_summary']['boundary_present']);
        $this->assertTrue($payload['packet_summary']['preview_only']);
        $this->assertFalse($payload['packet_summary']['mutates_accepted_facts']);
        $this->assertTrue($payload['no_canonical_write']);
        $this->assertFalse($payload['canonical_writes_performed']);
        $this->assertTrue($payload['apply_held']);
        $this->assertFalse($payload['apply_performed']);
        $this->assertSame(0, $this->packetCount());
    }

    public function test_compact_dry_run_omits_raw_source_file_and_packet_identifiers(): void
    {
        $path = $this->packetFile($this->validPacket());

        $payload = $this->callJson([
            '--file' => $path,
            '--json' => true,
            '--compact' => true,
        ]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertSame('would_create_packet', $payload['action']);
        $this->assertSame([
            'target_review_type' => 'genealogy_review_packet',
            'source_locator_count' => 1,
            'claim_count' => 1,
            'identity_present' => true,
            'privacy_present' => true,
            'boundary_present' => true,
            'validation_valid' => true,
            'validation_error_count' => 0,
            'validation_warning_count' => 0,
            'preview_only' => true,
            'mutates_accepted_facts' => false,
            'dedup_key_present' => true,
        ], $payload['packet_summary']);
        $this->assertNull($payload['packet']);
        $this->assertTrue($payload['file']['path_present']);
        $this->assertTrue($payload['file']['readable']);
        $this->assertStringNotContainsString($path, $encoded);
        $this->assertStringNotContainsString('https://example.test/review-packet-source', $encoded);
        $this->assertStringNotContainsString('Review packet command fixture', $encoded);
        $this->assertArrayNotHasKey('path', $payload['file']);
        $this->assertSame(0, $this->packetCount());
    }

    public function test_dry_run_does_not_mutate_review_queue_or_canonical_genealogy_tables(): void
    {
        $path = $this->packetFile($this->validPacket());

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $payload = $this->callJson([
                '--file' => $path,
                '--json' => true,
            ]);
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

    public function test_execute_creates_then_reuses_one_pending_review_packet(): void
    {
        $path = $this->packetFile($this->validPacket());

        $first = $this->callJson([
            '--file' => $path,
            '--execute' => true,
            '--json' => true,
        ]);
        $second = $this->callJson([
            '--file' => $path,
            '--execute' => true,
            '--json' => true,
        ]);

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

        $row = DB::table('agent_review_queue')->where('id', $first['packet']['review_queue_id'])->first();
        $this->assertNotNull($row);
        $this->assertSame('genealogy-review-packet', $row->agent_id);
        $this->assertSame('genealogy_review_packet', $row->review_type);
        $this->assertSame('pending', $row->status);

        $details = json_decode((string) $row->details, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('genealogy_review_packet.v1', $details['schema']);
        $this->assertSame('Review packet command fixture', $details['packet_label']);
        $this->assertFalse($details['apply_preview']['mutates_accepted_facts']);
    }

    public function test_execute_mutates_only_agent_review_queue(): void
    {
        $path = $this->packetFile($this->validPacket());

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $payload = $this->callJson([
                '--file' => $path,
                '--execute' => true,
                '--json' => true,
            ]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($payload['success']);
        $this->assertSame('created_packet', $payload['action']);
        $this->assertSame(['agent_review_queue'], $this->mutationTargets($queries));
        $this->assertNoCanonicalGenealogyMutationQueries($queries);
        $this->assertSame(1, $this->packetCount());
    }

    public function test_execute_preserves_multi_source_multi_claim_packet_counts(): void
    {
        $packet = $this->fixturePacket([
            'packet_key' => 'source-backed-review-packet-multi-source-claim',
            'packet_label' => 'Source-backed review packet multi source claim',
            'sources' => [
                [
                    'locator' => 'https://catalog.archives.gov/id/999999101',
                    'access_class' => 'public_archive_fixture',
                    'label' => 'Synthetic public archive locator 1',
                ],
                [
                    'locator' => 'https://www.loc.gov/item/synthetic-review-packet-2/',
                    'access_class' => 'public_archive_fixture',
                    'label' => 'Synthetic LOC locator 2',
                ],
            ],
            'claims' => [
                [
                    'claim_text' => 'Evelyn Hart lived in Example Township in 1911.',
                    'field_name' => 'residence',
                    'change_type' => 'field_update',
                    'person_id' => 4321,
                    'source_ref' => 'synthetic-public-archive-page-1',
                    'proposed_value' => 'Example Township',
                ],
                [
                    'claim_text' => 'Evelyn Hart was recorded near Mill Creek in 1912.',
                    'field_name' => 'residence_note',
                    'change_type' => 'field_update',
                    'person_id' => 4321,
                    'source_ref' => 'synthetic-loc-page-2',
                    'proposed_value' => 'Mill Creek',
                ],
            ],
        ]);

        $payload = $this->callJson([
            '--file' => $this->packetFile($packet),
            '--execute' => true,
            '--json' => true,
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('created_packet', $payload['action']);
        $this->assertSame(2, $payload['packet_summary']['source_locator_count']);
        $this->assertSame(2, $payload['packet_summary']['claim_count']);
        $this->assertTrue($payload['safety']['no_canonical_write']);
        $this->assertTrue($payload['safety']['apply_held']);

        $row = DB::table('agent_review_queue')->where('id', $payload['packet']['review_queue_id'])->first();
        $this->assertNotNull($row);

        $details = json_decode((string) $row->details, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Source-backed review packet multi source claim', $details['packet_label']);
        $this->assertCount(2, $details['source_locators']);
        $this->assertSame('https://catalog.archives.gov/id/999999101', $details['source_locators'][0]);
        $this->assertSame('https://www.loc.gov/item/synthetic-review-packet-2/', $details['source_locators'][1]);
        $this->assertCount(2, $details['claims']);
        $this->assertSame('synthetic-public-archive-page-1', $details['claims'][0]['source_ref']);
        $this->assertSame('synthetic-loc-page-2', $details['claims'][1]['source_ref']);
        $this->assertSame(1, $this->packetCount());
    }

    public function test_public_fixture_materializes_five_packets_for_sprint_readiness(): void
    {
        $keys = [];
        $boundaries = [];

        foreach ($this->fixturePackets() as $packet) {
            $keys[] = $packet['packet_key'] ?? null;
            $boundaries[] = $packet['sprint_boundary'] ?? null;

            $payload = $this->callJson([
                '--file' => $this->packetFile($packet),
                '--execute' => true,
                '--json' => true,
                '--compact' => true,
            ]);

            $this->assertTrue($payload['success']);
            $this->assertSame('created_packet', $payload['action']);
            $this->assertSame([
                'present' => true,
                'materialized_existing' => false,
            ], $payload['packet']);
            $this->assertTrue($payload['safety']['no_canonical_write']);
            $this->assertTrue($payload['safety']['apply_held']);
        }

        $this->assertCount(5, array_unique($keys));
        $this->assertSame(['synthetic public archive family line'], array_values(array_unique($boundaries)));

        $readiness = app(GenealogyEvidenceSprintReadinessService::class)->collect(days: 30);

        $this->assertSame('ready_for_review', $readiness['status']);
        $this->assertSame(5, $readiness['summary']['source_backed_pending']);
        $this->assertSame(5, $readiness['summary']['reviewable_pending_packets']);
        $this->assertSame(1, $readiness['summary']['boundary_label_count']);
        $this->assertSame(0, $readiness['summary']['boundary_mismatch_packets']);
        $this->assertTrue($readiness['readiness']['ready_for_five_packet_review']);
        $this->assertSame(5, $this->packetCount());
    }

    public function test_dry_run_reuse_existing_packet_reports_without_new_insert(): void
    {
        $path = $this->packetFile($this->validPacket());

        $created = $this->callJson([
            '--file' => $path,
            '--execute' => true,
            '--json' => true,
        ]);
        $dryRun = $this->callJson([
            '--file' => $path,
            '--json' => true,
        ]);
        $compact = $this->callJson([
            '--file' => $path,
            '--json' => true,
            '--compact' => true,
        ]);

        $this->assertSame('created_packet', $created['action']);
        $this->assertSame('would_reuse_existing_packet', $dryRun['action']);
        $this->assertSame($created['packet']['review_queue_id'], $dryRun['packet']['review_queue_id']);
        $this->assertSame([
            'present' => true,
            'materialized_existing' => true,
        ], $compact['packet']);
        $this->assertArrayNotHasKey('review_queue_id', $compact['packet']);
        $this->assertArrayNotHasKey('token', $compact['packet']);
        $this->assertSame(1, $this->packetCount());
    }

    public function test_invalid_file_json_and_validation_fail_without_insert(): void
    {
        $missing = $this->callJson(['--json' => true], 1);
        $badJson = $this->callJson([
            '--file' => $this->rawFile('{bad json'),
            '--json' => true,
        ], 1);

        $invalidPacket = $this->validPacket();
        unset($invalidPacket['sources']);
        $invalid = $this->callJson([
            '--file' => $this->packetFile($invalidPacket),
            '--json' => true,
            '--compact' => true,
        ], 1);

        $this->assertSame('packet_file_required', $missing['error']);
        $this->assertSame('packet_json_invalid', $badJson['error']);
        $this->assertSame('packet_validation_failed', $invalid['error']);
        $this->assertFalse($invalid['validation']['valid']);
        $this->assertContains('source_locator_required', $invalid['validation']['error_codes']);
        $this->assertSame(0, $this->packetCount());
    }

    public function test_compact_text_output_is_sanitized(): void
    {
        $path = $this->packetFile($this->validPacket());

        $output = $this->callText([
            '--file' => $path,
            '--compact' => true,
        ]);

        $this->assertStringContainsString('Genealogy review packet materialization compact: status=dry_run', $output);
        $this->assertStringContainsString('action=would_create_packet', $output);
        $this->assertStringContainsString('source_locators=1', $output);
        $this->assertStringContainsString('claims=1', $output);
        $this->assertStringContainsString('boundary=yes', $output);
        $this->assertStringContainsString('preview_only=yes', $output);
        $this->assertStringContainsString('no_canonical_write=yes', $output);
        $this->assertStringContainsString('apply_held=yes', $output);
        $this->assertStringNotContainsString($path, $output);
        $this->assertStringNotContainsString('https://example.test/review-packet-source', $output);
        $this->assertSame(0, $this->packetCount());
    }

    private function validPacket(): array
    {
        return [
            'packet_label' => 'Review packet command fixture',
            'packet_key' => 'review-packet-command-fixture',
            'sprint_boundary' => 'Command materialization fixture boundary',
            'identity' => ['person_id' => 321, 'status' => 'resolved'],
            'privacy' => ['cleared' => true, 'living_person_risk' => false],
            'sources' => [['locator' => 'https://example.test/review-packet-source']],
            'claims' => [
                [
                    'claim_text' => 'William Brown lived in Salem in 1880.',
                    'field_name' => 'residence',
                    'proposed_value' => 'Salem, Massachusetts',
                ],
            ],
        ];
    }

    private function fixturePacket(array $overrides = []): array
    {
        $path = base_path('tests/Fixtures/genealogy/source-backed-review-packet.json');
        $this->assertFileExists($path);

        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($fixture['packet'] ?? null);

        return array_replace_recursive($fixture['packet'], $overrides);
    }

    private function fixturePackets(): array
    {
        $path = base_path('tests/Fixtures/genealogy/source-backed-review-packet.json');
        $this->assertFileExists($path);

        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($fixture['packets'] ?? null);
        $this->assertCount(5, $fixture['packets']);

        return array_values($fixture['packets']);
    }

    private function packetFile(array $packet): string
    {
        return $this->rawFile(json_encode($packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function rawFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'plos-review-packet-');
        $this->assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function callJson(array $parameters, int $expectedExitCode = 0): array
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call('genealogy:materialize-review-packet', $parameters, $output);
        $contents = $output->fetch();

        $this->assertSame($expectedExitCode, $exitCode, $contents);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    private function callText(array $parameters, int $expectedExitCode = 0): string
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call('genealogy:materialize-review-packet', $parameters, $output);
        $contents = $output->fetch();

        $this->assertSame($expectedExitCode, $exitCode, $contents);

        return $contents;
    }

    private function packetCount(): int
    {
        return DB::table('agent_review_queue')
            ->where('review_type', 'genealogy_review_packet')
            ->count();
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
