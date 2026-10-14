<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\AIService;
use App\Services\HyDEService;
use App\Services\RagEmbeddingProvenanceService;
use App\Services\RAGService;
use App\Services\RAGTracingService;
use App\Services\SemDeDupService;
use App\Services\SystemConfigService;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

class RagEmbeddingQuerySpaceTest extends TestCase
{
    private string $previousConnection;

    private Connection $database;

    private mixed $manager;

    private bool $allowUnknown = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = DB::getDefaultConnection();
        $this->manager = DB::getFacadeRoot();
        config(['database.connections.query_space_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('query_space_test');
        $this->database = DB::connection();
        $this->database->getPdo()->sqliteCreateFunction('vector_dims', fn ($value) => count(json_decode($value, true)));
        $this->database->getSchemaBuilder()->create('rag_documents', function (Blueprint $table): void {
            $table->integer('id');
            $table->text('embedding');
            $table->text('metadata');
            $table->text('document_type');
            $table->text('title');
            $table->text('created_at');
            $table->float('distance_fixture');
        });
        $settings = Mockery::mock(SystemConfigService::class);
        $settings->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $key === 'rag.embedding_query_policy' ? ['schema' => 'plos.rag_embedding_query_policy.v1', 'allow_unknown_identity' => $this->allowUnknown] : $default);
        $this->app->instance(SystemConfigService::class, $settings);
        foreach ([
            1 => ['model' => 'other-model'], 2 => ['revision' => 'different-revision'],
            3 => ['provider' => 'other-provider'], 4 => ['dimensions' => 3],
            5 => ['instance_id' => 'another-host', 'chunked' => true, 'chunk_count' => 7, 'contract_fingerprint' => 'different-input-contract'],
            6 => null, 7 => ['revision' => null],
        ] as $id => $override) {
            $identity = $override === null ? [] : [RagEmbeddingProvenanceService::METADATA_KEY => array_replace([
                'provider' => 'ollama', 'model' => 'embedding-model', 'revision' => 'exact-revision', 'dimensions' => 2,
            ], $override)];
            $this->database->table('rag_documents')->insert(['id' => $id, 'embedding' => '[0.1,0.2]',
                'metadata' => json_encode($identity), 'document_type' => 'research', 'title' => 'Synthetic source',
                'created_at' => now()->toDateTimeString(), 'distance_fixture' => $id < 5 ? 0.001 * $id : 0.1 + ($id - 5) * 0.01]);
        }
    }

    protected function tearDown(): void
    {
        $this->manager->setDefaultConnection($this->previousConnection);
        $this->manager->purge('query_space_test');
        parent::tearDown();
    }

    public function test_predicate_excludes_known_mismatches_before_limit_while_retaining_legacy_unknowns(): void
    {
        $space = (new RagEmbeddingProvenanceService)->querySpacePredicate($this->queryEmbedding());
        $rows = $this->database->select('SELECT id FROM rag_documents WHERE '.$this->sqlite($space['sql']).' ORDER BY distance_fixture LIMIT 3', $space['bindings']);
        $this->assertSame([5, 6, 7], array_map(fn ($row) => $row->id, $rows));
    }

    public function test_explicit_strict_unknown_policy_retains_only_verified_space_and_ignores_host_or_chunk_fingerprints(): void
    {
        $this->allowUnknown = false;
        $space = (new RagEmbeddingProvenanceService)->querySpacePredicate($this->queryEmbedding());
        $rows = $this->database->select('SELECT id FROM rag_documents WHERE '.$this->sqlite($space['sql']), $space['bindings']);
        $this->assertSame([5], array_map(fn ($row) => $row->id, $rows));
        $unknown = (new RagEmbeddingProvenanceService)->querySpacePredicate(['embedding' => [0.1, 0.2]]);
        $this->assertSame([], $this->database->select('SELECT id FROM rag_documents WHERE '.$this->sqlite($unknown['sql']), $unknown['bindings']));
    }

    public function test_physical_vector_dimensions_are_checked_even_for_unknown_legacy_metadata(): void
    {
        $this->database->table('rag_documents')->where('id', 6)->update(['embedding' => '[0.1,0.2,0.3]']);
        $space = (new RagEmbeddingProvenanceService)->querySpacePredicate($this->queryEmbedding());
        $rows = $this->database->select('SELECT id FROM rag_documents WHERE '.$this->sqlite($space['sql']), $space['bindings']);
        $this->assertSame([5, 7], array_map(fn ($row) => $row->id, $rows));
    }

    public function test_content_addressed_artifact_remains_comparable_across_actual_provider_host_aliases(): void
    {
        $digest = str_repeat('a', 64);
        $this->database->table('rag_documents')->whereIn('id', [5, 7])->update(['metadata' => json_encode([
            RagEmbeddingProvenanceService::METADATA_KEY => ['provider' => 'ollama_secondary',
                'instance_id' => 'ollama_secondary', 'model' => 'embedding-model', 'revision' => $digest, 'dimensions' => 2],
        ])]);
        $this->database->table('rag_documents')->where('id', 7)->update(['metadata' => json_encode([
            RagEmbeddingProvenanceService::METADATA_KEY => ['provider' => 'ollama_secondary',
                'instance_id' => 'ollama_secondary', 'model' => 'embedding-model', 'revision' => str_repeat('b', 64), 'dimensions' => 2],
        ])]);
        $query = array_replace($this->queryEmbedding(), ['provider' => 'ollama_jetson', 'instance_id' => 'ollama_jetson', 'revision' => $digest]);
        foreach ([true, false] as $allowUnknown) {
            $this->allowUnknown = $allowUnknown;
            $space = (new RagEmbeddingProvenanceService)->querySpacePredicate($query);
            $rows = $this->database->select('SELECT id FROM rag_documents WHERE '.$this->sqlite($space['sql']), $space['bindings']);
            $this->assertSame($allowUnknown ? [5, 6] : [5], array_map(fn ($row) => $row->id, $rows));
        }
    }

    public function test_registration_preserves_operator_unknown_policy_on_rerun_and_rollback(): void
    {
        $this->database->getSchemaBuilder()->create('system_configs', function (Blueprint $table): void {
            $table->string('section');
            $table->string('config_key');
            $table->text('config_value');
            $table->string('data_type');
            $table->text('description');
            $table->timestamps();
            $table->unique(['section', 'config_key']);
        });
        $migration = require database_path('migrations/2026_10_14_150000_register_rag_embedding_query_identity_policy.php');
        $migration->up();
        $this->assertTrue(json_decode($this->database->table('system_configs')->value('config_value'), true)['allow_unknown_identity']);
        $edited = json_encode(['schema' => 'plos.rag_embedding_query_policy.v1', 'allow_unknown_identity' => false]);
        $this->database->table('system_configs')->update(['config_value' => $edited]);
        $migration->up();
        $migration->down();
        $this->assertSame($edited, $this->database->table('system_configs')->value('config_value'));
    }

    public static function consumers(): array
    {
        return [['rag'], ['hyde'], ['dedup'], ['similar']];
    }

    #[DataProvider('consumers')]
    public function test_vector_consumers_apply_complete_query_space_identity_before_ranking(string $consumer): void
    {
        $ai = Mockery::mock(AIService::class);
        if ($consumer !== 'similar') {
            $ai->shouldReceive('generateEmbedding')->once()->andReturn($this->queryEmbedding());
        }
        if ($consumer === 'hyde') {
            $ai->shouldReceive('process')->once()->andReturn(['success' => true, 'response' => 'Synthetic hypothesis']);
        }
        $pg = Mockery::mock();
        $method = $consumer === 'dedup' ? 'selectOne' : 'select';
        $pg->shouldReceive($method)->withArgs(fn ($sql, $params = []) => $sql !== 'SELECT * FROM rag_documents WHERE id = ? LIMIT 1')->andReturnUsing(function ($sql, $params = []) use ($consumer) {
            $rows = $this->database->select($this->sqlite($sql), $params);

            return $consumer === 'dedup' ? ($rows[0] ?? null) : $rows;
        });
        if ($consumer === 'similar') {
            $pg->shouldReceive('select')->once()->with('SELECT * FROM rag_documents WHERE id = ? LIMIT 1', [100])
                ->andReturn([(object) ['id' => 100, 'embedding' => '[0.1,0.2]', 'document_type' => 'research',
                    'metadata' => json_encode([RagEmbeddingProvenanceService::METADATA_KEY => $this->queryEmbedding()])]]);
        }
        DB::shouldReceive('connection')->with('pgsql_rag')->andReturn($pg);
        if ($consumer === 'dedup') {
            $result = (new SemDeDupService($ai))->checkEmbeddingSimilarity('Synthetic source', 0.8);
            $this->assertSame(5, $result['document_id']);
        } elseif ($consumer === 'hyde') {
            $this->assertSame(5, (new HyDEService($ai))->search('Synthetic hypothesis question', 1, 'research')[0]['document']->id);
        } else {
            $service = new RAGService($ai);
            $tracing = Mockery::mock(RAGTracingService::class);
            $tracing->shouldReceive('measure')->andReturnUsing(fn ($phase, $operation, &$phases) => $operation());
            $tracing->shouldReceive('recordSearch')->andReturnNull();
            (new ReflectionProperty(RAGService::class, 'tracingService'))->setValue($service, $tracing);
            $results = $consumer === 'similar' ? $service->findSimilar(100, 1)
                : $service->search('Synthetic long private research query', 1, 'research');
            $this->assertSame(5, $results[0]['document']->id);
        }
    }

    public function test_semantic_dedup_preserves_wrapped_unknown_before_any_database_query(): void
    {
        $stop = new \RuntimeException('wrapped stop', 0, new InferenceAdmissionException('unknown'));
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateEmbedding')->once()->andThrow($stop);
        DB::shouldReceive('connection')->never();
        try {
            (new SemDeDupService($ai))->checkEmbeddingSimilarity('Synthetic source', 0.8);
            $this->fail('Unknown embedding was silently treated as no duplicate.');
        } catch (\RuntimeException $observed) {
            $this->assertSame($stop, $observed);
        }
    }

    private function queryEmbedding(): array
    {
        return ['success' => true, 'embedding' => [0.1, 0.2], 'provider' => 'ollama',
            'model' => 'embedding-model', 'revision' => 'exact-revision', 'instance_id' => 'query-host'];
    }

    /** SQLite isolates behavior fixtures; only PostgreSQL casts/distance are adapted. */
    private function sqlite(string $sql): string
    {
        return str_replace(['::jsonb', 'BTRIM('], ['', 'TRIM('],
            preg_replace("/embedding <=> '[^']+'::vector/", 'distance_fixture', $sql));
    }
}
