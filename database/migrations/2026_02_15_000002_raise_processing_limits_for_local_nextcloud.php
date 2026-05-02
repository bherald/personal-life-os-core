<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Nextcloud moved to local prod machine — no network bottleneck, reduce delays and raise batches
        DB::update(
            'UPDATE scheduled_jobs SET command = ? WHERE id = ?',
            ['genealogy:face-sync --tree-id=4 --folder=/Library/Media --batch=2000 --delay=100', 32]
        );

        DB::update(
            'UPDATE scheduled_jobs SET command = ? WHERE id = ?',
            ['genealogy:media-consolidate --tree-id=4 --batch=2500 --delay=100', 33]
        );

        DB::update(
            'UPDATE scheduled_jobs SET command = ? WHERE id = ?',
            ['genealogy:face-sync --tree-id=4 --folder=/Library/Genealogy --batch=2000 --delay=100', 34]
        );

        // Joplin queue — WebDAV now local, can process more per run
        DB::update(
            'UPDATE scheduled_jobs SET command = ? WHERE id = ?',
            ['joplin:process-queue --limit=50', 3]
        );

        // File registry verify — file reads now local
        DB::update(
            'UPDATE scheduled_jobs SET command = ? WHERE id = ?',
            ['files:registry --verify --limit=500', 12]
        );

        // KeyPointsPostProcessor node — Claude fallback provides more AI capacity
        $workflow = DB::selectOne('SELECT id FROM workflows WHERE name = ?', ['youtube_watch_later']);
        if ($workflow) {
            $node = DB::selectOne(
                'SELECT id FROM workflow_nodes WHERE workflow_id = ? AND node_type LIKE ?',
                [$workflow->id, '%KeyPointsPostProcessor%']
            );
            if ($node) {
                DB::update(
                    'UPDATE workflow_node_configs SET config_value = ? WHERE workflow_node_id = ? AND config_key = ?',
                    ['50', $node->id, 'limit']
                );
            }
        }
    }

    public function down(): void
    {
        // Revert to original limits
        DB::update(
            'UPDATE scheduled_jobs SET command = ? WHERE id = ?',
            ['genealogy:face-sync --tree-id=4 --folder=/Library/Media --batch=800 --delay=500', 32]
        );

        DB::update(
            'UPDATE scheduled_jobs SET command = ? WHERE id = ?',
            ['genealogy:media-consolidate --tree-id=4 --batch=1100 --delay=500', 33]
        );

        DB::update(
            'UPDATE scheduled_jobs SET command = ? WHERE id = ?',
            ['genealogy:face-sync --tree-id=4 --folder=/Library/Genealogy --batch=800 --delay=500', 34]
        );

        DB::update(
            'UPDATE scheduled_jobs SET command = ? WHERE id = ?',
            ['joplin:process-queue', 3]
        );

        DB::update(
            'UPDATE scheduled_jobs SET command = ? WHERE id = ?',
            ['files:registry --verify', 12]
        );

        $workflow = DB::selectOne('SELECT id FROM workflows WHERE name = ?', ['youtube_watch_later']);
        if ($workflow) {
            $node = DB::selectOne(
                'SELECT id FROM workflow_nodes WHERE workflow_id = ? AND node_type LIKE ?',
                [$workflow->id, '%KeyPointsPostProcessor%']
            );
            if ($node) {
                DB::update(
                    'UPDATE workflow_node_configs SET config_value = ? WHERE workflow_node_id = ? AND config_key = ?',
                    ['20', $node->id, 'limit']
                );
            }
        }
    }
};
