<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the default-off AWO operator-decision recording flag.
 *
 * When enabled, completed agent-review decisions store a compact
 * awo_decision envelope inside agent_review_queue.details. The envelope is
 * evidence for observe/replay scoring only; it does not approve, reject, or
 * promote agents.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::selectOne(
            'SELECT id FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
            ['awo', 'recording_enabled']
        );

        if ($existing === null) {
            DB::insert(
                'INSERT INTO system_configs (section, config_key, config_value, data_type, description, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    'awo',
                    'recording_enabled',
                    'false',
                    'bool',
                    'Default-off AWO operator-decision evidence recording. Stores compact awo_decision envelopes in agent_review_queue.details after final operator approve/reject only.',
                ]
            );
        }
    }

    public function down(): void
    {
        DB::delete(
            'DELETE FROM system_configs WHERE section = ? AND config_key = ?',
            ['awo', 'recording_enabled']
        );
    }
};
