<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const EXECUTABLE = './storage/app/private/plos-cli/native/codex';

    public function up(): void
    {
        $this->update(false);
    }

    public function down(): void
    {
        $this->update(true);
    }

    private function update(bool $rollback): void
    {
        DB::transaction(function () use ($rollback): void {
            $row = DB::table('llm_instances')->where('instance_id', 'codex_exec')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $config = json_decode($row->config ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($config)) {
                throw new RuntimeException('Codex provider configuration must be a JSON object.');
            }
            if ($rollback) {
                if (($config['native_executable'] ?? null) !== self::EXECUTABLE) {
                    return;
                }
                unset($config['native_executable']);
            } elseif (! array_key_exists('native_executable', $config)) {
                $config['native_executable'] = self::EXECUTABLE;
            } else {
                return;
            }
            DB::table('llm_instances')->where('id', $row->id)->update([
                'config' => json_encode($config, JSON_THROW_ON_ERROR),
            ]);
        });
    }
};
