<?php

use App\Services\NextcloudFileApiService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs') || DB::table('system_configs')
            ->where('section', 'files')->where('config_key', 'registry_scan_root')->exists()) {
            return;
        }
        $root = config('services.nextcloud.library_root', '/Library');
        $description = 'Operator-owned file registry scan scope; case resolved against the configured local Nextcloud root.';
        try {
            $root = app(NextcloudFileApiService::class)->configuredLibraryRoot();
        } catch (RuntimeException) {
            $description = 'Operator-owned file registry scan scope; local resolution unavailable or ambiguous, scans remain held.';
        }
        DB::table('system_configs')->insertOrIgnore([
            'section' => 'files',
            'config_key' => 'registry_scan_root',
            'config_value' => is_string($root) ? $root : '',
            'data_type' => 'string',
            'description' => $description,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Retain the operator-owned scope on rollback; never erase a later override.
    }
};
