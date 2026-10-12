<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'ai';

    private const KEY = 'model_host_controller_automatic_selection_policy';

    private const BEFORE_SHA256 = 'a07ebe9561eba9d76544525fb768eaeec199a3e8995fe4de0df5398a591ce360';

    private const AFTER_SHA256 = '2976bd9f5379a0451c96bc05da1b37c7062169ea3a8fc11edb51d0749c5069c1';

    private const BEFORE_DESCRIPTION = 'Inactive MHC-06 automatic serial selection with exact provider-role bridging; primary live gate required before activation.';

    private const AFTER_DESCRIPTION = 'Inactive MHC-06 automatic serial selection with exact provider-admission roles; primary live gate required before activation.';

    public function up(): void
    {
        $this->transition(false);
    }

    public function down(): void
    {
        $this->transition(true);
    }

    private function transition(bool $rollback): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::transaction(function () use ($rollback): void {
            $row = DB::table('system_configs')
                ->where('section', self::SECTION)
                ->where('config_key', self::KEY)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                throw new RuntimeException('MHC-06 Ollama provider-role authority is missing.');
            }
            $json = (string) $row->config_value;
            $expectedHash = $rollback ? self::AFTER_SHA256 : self::BEFORE_SHA256;
            $expectedDescription = $rollback ? self::AFTER_DESCRIPTION : self::BEFORE_DESCRIPTION;
            if (! hash_equals($expectedHash, hash('sha256', $json))
                || (string) ($row->description ?? '') !== $expectedDescription) {
                throw new RuntimeException('MHC-06 Ollama provider-role authority drifted.');
            }
            $policy = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $expected = $rollback ? 'standard' : 'fast';
            if (($policy['targets']['ollama_qwen3_8b_87']['provider_model_role'] ?? null) !== $expected) {
                throw new RuntimeException('MHC-06 Ollama provider-role binding drifted.');
            }
            $policy['targets']['ollama_qwen3_8b_87']['provider_model_role'] = $rollback ? 'fast' : 'standard';
            $nextJson = json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $nextHash = $rollback ? self::BEFORE_SHA256 : self::AFTER_SHA256;
            if (! hash_equals($nextHash, hash('sha256', $nextJson))) {
                throw new RuntimeException('MHC-06 Ollama provider-role transition is inexact.');
            }
            $updated = DB::table('system_configs')
                ->where('id', $row->id)
                ->where('config_value', $json)
                ->update([
                    'config_value' => $nextJson,
                    'description' => $rollback ? self::BEFORE_DESCRIPTION : self::AFTER_DESCRIPTION,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('MHC-06 Ollama provider-role transition lost authority.');
            }
        });
    }
};
