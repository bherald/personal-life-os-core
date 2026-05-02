<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix update_hint_status tool — document valid ENUM values
        // Valid statuses: pending, accepted, rejected, deferred
        try {
            DB::update("
                UPDATE agent_tool_registry
                SET parameters = ?, updated_at = NOW()
                WHERE name = 'update_hint_status'
            ", [
                json_encode([
                    'hint_id' => ['type' => 'integer', 'description' => 'Research hint ID', 'required' => true],
                    'status' => ['type' => 'string', 'description' => 'New status. MUST be one of: pending, accepted, rejected, deferred', 'required' => true],
                ]),
            ]);
        } catch (\Exception $e) {
            // Non-fatal
        }
    }

    public function down(): void
    {
        // No rollback needed
    }
};
