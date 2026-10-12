<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_configs')->insertOrIgnore($this->definition() + ['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('system_configs')->where($this->definition())->delete();
    }

    private function definition(): array
    {
        return ['section' => 'cli', 'config_key' => 'tls_policy', 'data_type' => 'json', 'config_value' => '{}',
            'description' => 'Optional exact-origin private CA files and SHA-256 trust identities; require_lan_tls rejects plaintext in every network mode. Host-local CA files must be user-private. Empty policy preserves existing connections. Tokens remain local binding references.'];
    }
};
