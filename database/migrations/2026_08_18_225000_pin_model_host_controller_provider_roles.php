<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'ai';

    private const KEY = 'model_host_controller_automatic_selection_policy';

    private const BEFORE_SHA256 = '66fda764ffa570abebf151af63ef9cb67dd80f47050fcb9d00f7d383b96621bd';

    private const AFTER_SHA256 = 'a07ebe9561eba9d76544525fb768eaeec199a3e8995fe4de0df5398a591ce360';

    private const BEFORE_DESCRIPTION = 'Inactive MHC-06 automatic serial selection; primary live gate required before activation.';

    private const AFTER_DESCRIPTION = 'Inactive MHC-06 automatic serial selection with exact provider-role bridging; primary live gate required before activation.';

    /** @var array<string,string> */
    private const PROVIDER_ROLES = [
        'qwen38_87' => 'deep_offline',
        'ollama_qwen3_8b_87' => 'fast',
        'bitnet_87' => 'privacy_deny_prefilter',
    ];

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
                throw new RuntimeException('MHC-06 provider-role policy authority is missing.');
            }
            $json = (string) $row->config_value;
            $expectedHash = $rollback ? self::AFTER_SHA256 : self::BEFORE_SHA256;
            $expectedDescription = $rollback ? self::AFTER_DESCRIPTION : self::BEFORE_DESCRIPTION;
            if (! hash_equals($expectedHash, hash('sha256', $json))
                || (string) ($row->description ?? '') !== $expectedDescription) {
                throw new RuntimeException('MHC-06 provider-role policy authority drifted.');
            }

            $policy = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            foreach (self::PROVIDER_ROLES as $target => $role) {
                if (! is_array($policy['targets'][$target] ?? null)) {
                    throw new RuntimeException('MHC-06 provider-role target catalog drifted.');
                }
                if ($rollback) {
                    if (($policy['targets'][$target]['provider_model_role'] ?? null) !== $role) {
                        throw new RuntimeException('MHC-06 provider-role binding drifted.');
                    }
                    unset($policy['targets'][$target]['provider_model_role']);
                } else {
                    if (array_key_exists('provider_model_role', $policy['targets'][$target])) {
                        throw new RuntimeException('MHC-06 provider-role binding already exists.');
                    }
                    $policy['targets'][$target]['provider_model_role'] = $role;
                }
            }
            $nextJson = json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $nextHash = $rollback ? self::BEFORE_SHA256 : self::AFTER_SHA256;
            if (! hash_equals($nextHash, hash('sha256', $nextJson))) {
                throw new RuntimeException('MHC-06 provider-role policy transition is inexact.');
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
                throw new RuntimeException('MHC-06 provider-role policy transition lost authority.');
            }
        });
    }
};
