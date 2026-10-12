<?php

use App\Services\UnifiedCli\CliFrameworkServicePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TOOL = 'file_face_coverage_preview_execute';

    private const STATE_KEY = 'native_face_preview_execute_timeout_20261008';

    private const TIMEOUT_MS = 600000;

    public function up(): void
    {
        DB::transaction(function (): void {
            $frameworkRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')
                ->lockForUpdate()->first();
            if ($frameworkRow === null) {
                throw new RuntimeException('Native face preview timeout requires the existing framework service policy.');
            }
            $framework = json_decode((string) $frameworkRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($framework);
            $stateRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)
                ->lockForUpdate()->first();
            if ($stateRow !== null) {
                return;
            }

            $tool = DB::table('agent_tool_registry')->where('name', self::TOOL)->lockForUpdate()->first();
            if ($tool === null || $tool->service_class !== 'App\\Services\\FaceEmbeddingService'
                || $tool->method !== 'prepareAnonymousCoveragePreview') {
                throw new RuntimeException('Native face preview execute registration does not match the timeout migration.');
            }

            $rule = $framework['tools'][self::TOOL] ?? null;
            if (! is_array($rule) && (int) $tool->enabled === 1) {
                throw new RuntimeException('Enabled native face preview execute tool requires its existing framework rule.');
            }
            $prior = ['rule_present' => is_array($rule), 'timeout_present' => is_array($rule) && array_key_exists('timeout_ms', $rule),
                'timeout_ms' => is_array($rule) ? ($rule['timeout_ms'] ?? null) : null, 'timeout_added' => false];
            if (is_array($rule)) {
                $expected = ['target' => 'local', 'tree_scoped' => false, 'references' => []];
                if (array_diff_key($rule, ['timeout_ms' => true, 'durable_receipts' => true]) !== $expected) {
                    throw new RuntimeException('Native face preview execute policy differs from its registered local route.');
                }
                if (! array_key_exists('timeout_ms', $rule)) {
                    $framework['tools'][self::TOOL]['timeout_ms'] = self::TIMEOUT_MS;
                    $prior['timeout_added'] = true;
                }
            }

            DB::table('system_configs')->insert([
                'section' => 'cli', 'config_key' => self::STATE_KEY,
                'config_value' => json_encode($prior, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'data_type' => 'json', 'description' => 'Rollback ownership for native face preview execute timeout.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($prior['timeout_added']) {
                CliFrameworkServicePolicy::validate($framework);
                DB::table('system_configs')->where('id', $frameworkRow->id)->update([
                    'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $stateRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)
                ->lockForUpdate()->first();
            if ($stateRow === null) {
                return;
            }
            $frameworkRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')
                ->lockForUpdate()->first();
            if ($frameworkRow === null) {
                throw new RuntimeException('Cannot roll back native face preview timeout without the existing framework service policy.');
            }
            $prior = json_decode((string) $stateRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $framework = json_decode((string) $frameworkRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($framework);
            $changed = false;
            $rule = $framework['tools'][self::TOOL] ?? null;
            if (($prior['timeout_added'] ?? false) === true && is_array($rule)
                && ($rule['timeout_ms'] ?? null) === self::TIMEOUT_MS) {
                unset($framework['tools'][self::TOOL]['timeout_ms']);
                $changed = true;
            }
            if ($changed) {
                CliFrameworkServicePolicy::validate($framework);
                DB::table('system_configs')->where('id', $frameworkRow->id)->update([
                    'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                ]);
            }
            DB::table('system_configs')->where('id', $stateRow->id)->delete();
        });
    }
};
