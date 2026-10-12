<?php

namespace Tests\Unit\Services;

use App\Services\Custody\TaskCustodyService;
use App\Services\JoplinFilesService;
use App\Services\JoplinLockHandler;
use App\Services\JoplinQueueService;
use App\Services\JoplinWriteService;
use App\Services\JoplinYouTubeKnowledgeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\CreatesYouTubeSourceCustodySchema;
use Tests\TestCase;

class JoplinYouTubeKnowledgeServiceTest extends TestCase
{
    use CreatesYouTubeSourceCustodySchema;

    private array $items = [];

    private JoplinFilesService $reader;

    private JoplinWriteService $writer;

    private JoplinQueueService $queue;

    private JoplinLockHandler $locks;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'knowledge_test',
            'database.connections.knowledge_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array',
        ]);
        DB::purge('knowledge_test');
        $this->createSourceCustodySchema();
        Schema::create('system_configs', function (Blueprint $table): void {
            $table->string('section');
            $table->string('config_key');
            $table->text('config_value');
            $table->string('data_type');
            $table->text('description');
            $table->timestamps();
            $table->unique(['section', 'config_key']);
        });
        (require base_path('database/migrations/2026_09_19_160000_create_joplin_review_mirror.php'))->up();
        (require base_path('database/migrations/2026_09_19_170000_bound_joplin_review_batches.php'))->up();
        (require base_path('database/migrations/2026_09_19_180000_create_joplin_youtube_knowledge.php'))->up();

        $this->add('1', 'Inbox', '', 2);
        $this->add('2', 'Food & Recipes', '', 2);
        $this->add('3', 'Technology & PLOS', '', 2);
        $this->add('4', 'Archive', '', 2);
        $completeTranscript = implode(' ', array_fill(0, 120, 'word'));
        $this->add('5', 'Two ingredient bread', str_repeat('2', 32), 1,
            "https://youtu.be/abcdefghijk\n\n## Key Points\n\n- Mix and bake\n\n## Full Transcript\n\n{$completeTranscript}\n\n---\n\n## Metadata");
        $this->add('6', 'Family grocery list', str_repeat('2', 32), 1, 'Milk and eggs');
        $this->add('7', 'Local AI tutorial', str_repeat('3', 32), 1,
            'https://www.youtube.com/watch?v=lmnopqrstuv ## Key Points To be generated ## Full Transcript short');
        $this->add('8', 'Archived note', str_repeat('4', 32), 1, 'Leave here');
        $this->add('9', 'Review Mirror', str_repeat('a', 32), 2);
        $this->add('a', 'Managed review with video link', str_repeat('9', 32), 1, 'https://youtu.be/zzzzzzzzzzz');
        DB::table('joplin_review_items')->insert([
            'mirror_key' => 'plos-review', 'source_key' => str_repeat('c', 64), 'source_path' => 'review/managed.html',
            'note_id' => str_repeat('a', 32), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->reader = Mockery::mock(JoplinFilesService::class);
        $this->reader->shouldReceive('listNotes')->byDefault()->andReturnUsing(fn () => array_map(fn ($id) => $id.'.md', array_keys($this->items)));
        $this->reader->shouldReceive('getNote')->byDefault()->andReturnUsing(fn ($id) => $this->items[$id] ?? null);
        $this->writer = Mockery::mock(JoplinWriteService::class);
        $this->writer->shouldReceive('reviewSnapshot')->byDefault()->andReturnUsing(fn (string $path): ?array => isset($this->items[substr($path, 0, -3)]) ? ['body' => substr($path, 0, -3)] : null);
        $this->writer->shouldReceive('reviewNoteExactFields')->byDefault()->andReturnUsing(fn (string $wire, string $id): array => $this->items[$id]);
        $this->queue = Mockery::mock(JoplinQueueService::class);
        $this->queue->shouldReceive('getStatistics')->andReturn(['pending' => 0, 'processing' => 0, 'failed' => 0]);
        $this->locks = Mockery::mock(JoplinLockHandler::class);
        $this->locks->shouldReceive('getLockStatus')->andReturn(['sync_locks' => 0, 'exclusive_locks' => 0]);
        $this->locks->shouldReceive('acquireSyncLock')->andReturn(['test' => true]);
        $this->locks->shouldReceive('releaseLock')->andReturnNull();
    }

    protected function tearDown(): void
    {
        DB::purge('knowledge_test');
        Mockery::close();
        parent::tearDown();
    }

    public function test_preview_scopes_moves_and_keeps_each_food_recipe_as_a_source(): void
    {
        $result = $this->service()->run();

        $this->assertSame('preview', $result['status']);
        $this->assertSame(2, $result['youtube_notes']);
        $this->assertSame(2, $result['youtube_moves']);
        $this->assertSame(1, $result['ordinary_notes_to_inbox']);
        $this->assertSame(1, $result['food_recipe_candidates']);
        $this->assertSame(1, $result['complete']);
        $this->assertSame(1, $result['needs_review']);
        $this->assertSame(['Food & Recipes' => 1, 'Technology & PLOS' => 1], $result['notes_by_topic']);
        $this->assertSame([], $result['exact_duplicate_notes_by_topic']);
        $this->assertSame(0, DB::table('joplin_youtube_knowledge_items')->count());
    }

    public function test_held_exact_source_is_excluded_without_blocking_other_source_planning(): void
    {
        DB::table('task_custody_records')->insert([
            'surface' => TaskCustodyService::SURFACE_YOUTUBE_SOURCE_WRITE,
            'surface_ref' => str_repeat('5', 32), 'owner_token' => 'dead-owner',
            'result_envelope' => json_encode(['schema' => 'plos.youtube_source_write.v1', 'note_id' => str_repeat('5', 32), 'video_id' => 'abcdefghijk']),
            'acquired_at' => now(), 'expires_at' => '2000-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = $this->service()->run();
        $this->assertSame(1, $result['youtube_notes']);
        $this->assertSame(1, $result['youtube_moves']);
        $this->assertSame(['Technology & PLOS' => 1], $result['notes_by_topic']);
        $this->assertNull(DB::table('task_custody_records')->sole()->released_at);
    }

    public function test_full_apply_builds_indexes_records_inventory_and_verifies_moves(): void
    {
        $nextId = 100;
        $this->writer->shouldReceive('createNotebook')->andReturnUsing(function (string $title, ?string $parent = null) use (&$nextId): array {
            $id = str_pad((string) $nextId++, 32, 'a');

            return ['success' => true, 'notebook_id' => $id, 'title' => $title, 'parent_id' => $parent];
        });
        $this->writer->shouldReceive('reviewMoveNote')->times(3)->andReturnUsing(function (string $id, string $parent): void {
            $this->items[$id]['parent_id'] = $parent;
            $this->items[$id]['updated_time'] = '2026-09-19T16:00:00.000Z';
        });
        $this->writer->shouldReceive('createNote')->times(12)->andReturnUsing(function (string $title, string $body, ?string $parent) use (&$nextId): array {
            $id = str_pad((string) $nextId++, 32, 'b');
            $this->items[$id] = $this->item($id, $title, $parent ?? '', 1, $body);

            return ['success' => true, 'note_id' => $id];
        });

        $result = $this->service()->run(true);

        $this->assertSame('verified', $result['status']);
        $this->assertSame(3, $result['applied_moves']);
        $this->assertSame(3, $result['verified_moves']);
        $this->assertSame(12, $result['managed_notes_updated']);
        $this->assertSame(2, DB::table('joplin_youtube_knowledge_items')->where('present', true)->count());
        $this->assertSame(12, DB::table('joplin_youtube_knowledge_notes')->count());
        $recipe = DB::table('joplin_youtube_knowledge_items')->where('note_id', str_repeat('5', 32))->first();
        $this->assertSame('food_recipe', $recipe->format_class);
        $this->assertStringContainsString('original_content_hash', $recipe->provenance);
    }

    public function test_operator_edited_topic_is_preserved_while_remaining_work_completes(): void
    {
        $children = $this->prepareApplyWithExistingTree(10, 4);
        $foodId = $this->managedIndex('topic:food-recipes', 'Food & Recipes — Source Index', $children['Food & Recipes']);
        $food = $this->items[$foodId];
        DB::table('joplin_youtube_knowledge_notes')->where('note_id', $foodId)->update([
            'remote_hash' => hash('sha256', $food['title']."\n".$food['content']."\n".$food['parent_id']),
        ]);
        $id = $this->managedIndex('topic:technology-plos', 'Technology & PLOS — Source Index', $children['Technology & PLOS']);
        $before = $this->items[$id];

        $result = $this->service()->run(true);

        $this->assertSame('completed_with_conflicts', $result['status']);
        $this->assertSame(3, $result['applied_moves']);
        $this->assertSame(3, $result['verified_moves']);
        $this->assertSame(11, $result['managed_notes_updated']);
        $this->assertSame(1, $result['managed_index_conflict_count']);
        $this->assertSame('operator_edit_preserved', $result['managed_index_conflicts'][0]['reason']);
        $this->assertSame($id, $result['managed_index_conflicts'][0]['note_id']);
        $this->assertSame($before, $this->items[$id]);
        $this->assertStringContainsString('Two ingredient bread', $this->items[$foodId]['content']);
        $this->assertSame(hash('sha256', 'old managed content'), DB::table('joplin_youtube_knowledge_notes')->where('note_id', $id)->value('remote_hash'));
        $this->assertSame(2, DB::table('joplin_youtube_knowledge_items')->where('present', true)->count());
        $root = DB::table('joplin_youtube_knowledge_notes')->where('note_key', 'root:index')->value('note_id');
        $this->assertStringContainsString('(:/'.$id.')', $this->items[$root]['content']);
    }

    public function test_operator_edited_root_is_preserved_after_other_indexes_complete(): void
    {
        $this->prepareApplyWithExistingTree(11);
        $id = $this->managedIndex('root:index', '00 — YouTube Knowledge', str_repeat('b', 32));
        $before = $this->items[$id];

        $result = $this->service()->run(true);

        $this->assertSame('completed_with_conflicts', $result['status']);
        $this->assertSame(11, $result['managed_notes_updated']);
        $this->assertSame(1, $result['managed_index_conflict_count']);
        $this->assertSame('root:index', $result['managed_index_conflicts'][0]['note_key']);
        $this->assertSame($before, $this->items[$id]);
        $this->assertSame(3, $result['verified_moves']);
    }

    public function test_unchanged_exact_wire_hash_skips_index_write_despite_parser_framing(): void
    {
        $children = $this->prepareApplyWithExistingTree(11);
        $topic = 'Personal & Family';
        $title = $topic.' — Source Index';
        $id = md5('managed:'.$title);
        $body = "<!-- PLOS:YOUTUBE-KNOWLEDGE:topic -->\n"
            ."# {$topic}\n\nSource index maintained by PLOS. Each source stays separate; follow the note and YouTube links for attribution.\n\n";
        $exact = $this->item($id, $title, $children[$topic], 1, $body);
        $this->items[$id] = array_merge($exact, ['content' => "\n".rtrim($body, "\n")]);
        DB::table('joplin_youtube_knowledge_notes')->insert([
            'note_key' => 'topic:personal-family', 'note_id' => $id, 'parent_key' => $children[$topic],
            'title' => $title, 'remote_hash' => hash('sha256', $title."\n".$body."\n".$children[$topic]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->writer->shouldReceive('reviewNoteExactFields')->with($id, $id)->once()->andReturn($exact);

        $result = $this->service()->run(true);

        $this->assertSame('verified', $result['status']);
        $this->assertSame(0, $result['managed_index_conflict_count']);
        $this->assertSame(11, $result['managed_notes_updated']);
        $this->assertSame("\n".rtrim($body, "\n"), $this->items[$id]['content']);
    }

    public function test_incomplete_inventory_does_not_mark_sources_missing_or_start_writes(): void
    {
        DB::table('joplin_youtube_knowledge_items')->insert([
            'note_id' => str_repeat('5', 32), 'topic' => 'Food & Recipes',
            'completeness_state' => 'complete', 'source_hash' => str_repeat('c', 64),
            'issues' => '[]', 'provenance' => '{}', 'present' => true,
        ]);
        $this->reader->shouldReceive('listNotes')->with(true)->once()->andReturn([str_repeat('f', 32).'.md']);
        $this->reader->shouldReceive('getNote')->with(str_repeat('f', 32), true)->once()->andReturnNull();
        $this->writer->shouldNotReceive('createNotebook', 'createNote', 'updateNote', 'reviewMoveNote');

        try {
            $this->service()->run(true);
            $this->fail('Incomplete inventory should stop maintenance');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Incomplete YouTube Knowledge inventory', $e->getMessage());
        }
        $this->assertSame(1, DB::table('joplin_youtube_knowledge_items')->where('present', true)->count());
        $this->assertSame(0, DB::table('joplin_youtube_knowledge_notes')->count());
    }

    private function prepareApplyWithExistingTree(int $createdIndexes, int $updates = 3): array
    {
        $this->add('b', 'YouTube Knowledge', '', 2);
        $policy = json_decode(DB::table('system_configs')->where('section', 'joplin')->where('config_key', 'youtube_knowledge')->value('config_value'), true);
        $children = [];
        foreach (array_merge(array_keys($policy['topics']), [$policy['review_topic']]) as $topic) {
            $id = md5('topic:'.$topic);
            $children[$topic] = $id;
            $this->items[$id] = $this->item($id, $topic, str_repeat('b', 32), 2, '');
        }
        $this->writer->shouldReceive('reviewMoveNote')->times(3)->andReturnUsing(function (string $id, string $parent): void {
            $this->items[$id]['parent_id'] = $parent;
        });
        $this->writer->shouldReceive('updateNote')->times($updates - 3)->andReturnUsing(function (string $id, array $updates): array {
            $this->items[$id] = array_merge($this->items[$id], $updates);

            return ['success' => true, 'note_id' => $id];
        });
        $this->writer->shouldReceive('createNote')->times($createdIndexes)->andReturnUsing(function (string $title, string $body, ?string $parent): array {
            $id = md5('managed:'.$title);
            $this->items[$id] = $this->item($id, $title, $parent ?? '', 1, $body);

            return ['success' => true, 'note_id' => $id];
        });

        return $children;
    }

    private function managedIndex(string $key, string $title, string $parent): string
    {
        $id = md5('managed:'.$title);
        $this->items[$id] = $this->item($id, $title, $parent, 1, 'Operator content must remain exactly unchanged.');
        DB::table('joplin_youtube_knowledge_notes')->insert([
            'note_key' => $key, 'note_id' => $id, 'parent_key' => $parent,
            'title' => $title, 'remote_hash' => hash('sha256', 'old managed content'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function service(): JoplinYouTubeKnowledgeService
    {
        return new JoplinYouTubeKnowledgeService($this->reader, $this->writer, $this->queue, $this->locks);
    }

    private function add(string $digit, string $title, string $parent, int $type, string $content = ''): void
    {
        $id = str_repeat($digit, 32);
        $this->items[$id] = $this->item($id, $title, $parent, $type, $content);
    }

    private function item(string $id, string $title, string $parent, int $type, string $content): array
    {
        return [
            'id' => $id, 'title' => $title, 'parent_id' => $parent, 'type' => $type, 'content' => $content,
            'created_time' => '2026-09-19T12:00:00.000Z', 'updated_time' => '2026-09-19T12:00:00.000Z', 'metadata' => [],
        ];
    }
}
