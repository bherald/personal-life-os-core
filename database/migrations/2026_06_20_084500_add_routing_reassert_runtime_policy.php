<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'stale_confirmation_minutes' => 10,
            'policy' => 'Default stale pending-confirmation cleanup threshold for routing:reassert. CLI --stale remains an explicit one-run operator override.',
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'routing', 'config_key' => 'reassert_runtime_policy'],
            [
                'config_value' => json_encode($this->policy(), JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for routing:reassert restart/reconnect stale confirmation cleanup.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', 'routing')
            ->where('config_key', 'reassert_runtime_policy')
            ->delete();
    }
};
