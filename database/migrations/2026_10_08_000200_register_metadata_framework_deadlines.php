<?php

use App\Services\UnifiedCli\CliFrameworkServicePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Physical backup, verified copyback, vector receipts and CLI result publication need one owned budget.
    private const DEADLINES = [
        'file_exact_capture_date_write' => 120000,
        'file_metadata_preimage_restore' => 120000,
        'file_reviewed_face_metadata_replace' => 120000,
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Metadata deadlines require the existing framework service policy.');
            }
            $policy = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($policy);
            foreach (self::DEADLINES as $tool => $timeout) {
                $rule = $policy['tools'][$tool] ?? null;
                if (! is_array($rule) || ($rule['target'] ?? null) !== 'local' || ($rule['tree_scoped'] ?? null) !== false) {
                    throw new RuntimeException('Metadata deadline requires its exact registered local execution rule.');
                }
                // Existing reviewed operator deadlines remain authoritative.
                $policy['tools'][$tool]['timeout_ms'] ??= $timeout;
            }
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $policy = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($policy);
            foreach (self::DEADLINES as $tool => $timeout) {
                if (($policy['tools'][$tool]['timeout_ms'] ?? null) === $timeout) {
                    unset($policy['tools'][$tool]['timeout_ms']);
                }
            }
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
        });
    }
};
