<?php

use App\Services\UpstreamPrivatePreparationAdmissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }
        DB::table('agent_tool_registry')->insertOrIgnore([
            'name' => 'upstream_worker_preview',
            'service_class' => UpstreamPrivatePreparationAdmissionService::class,
            'method' => 'previewWorkerAdmission',
            'description' => 'Read exact server-owned upstream work/assignment/custody and actual DEV workspace bindings; never publish intent or authorize execution.',
            'parameters' => json_encode([
                'workKey' => ['type' => 'string', 'required' => true],
                'taskId' => ['type' => 'string', 'required' => true],
                'attempt' => ['type' => 'integer', 'required' => true],
                'generation' => ['type' => 'integer', 'required' => true],
            ], JSON_THROW_ON_ERROR),
            'permissions' => '[]', 'risk_level' => 'read', 'category' => 'operations',
            'requires_confirmation' => false, 'enabled' => false, 'availability_status' => 'disabled',
            'privacy_class' => 'private_internal', 'allows_private_data' => true,
            'source' => 'config', 'notes' => 'Source-only preview, disabled. No DEV target, worker, journal, grant, schedule or execution activation.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve operator overrides and all owner/UNKNOWN evidence. The
        // default-disabled registration is not execution authority on rollback.
    }
};
