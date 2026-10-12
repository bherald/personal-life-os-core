<?php

use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Existing report services only. File content/OS operations keep their native
    // bindings; maintenance writes and identity decisions are not enabled here.
    private const SERVICES = [
        'App\\Services\\FileRegistryService' => [
            'file_registry_stats' => 'getStatistics',
            'file_maintenance_stats' => 'getMaintenanceStats',
            'file_duplicates_stats' => 'getDuplicatesStats',
            'file_duplicates_pending' => 'getDuplicatesStats',
            'file_visual_duplicates' => 'getVisualDuplicatesReport',
            'file_uncategorized_files' => 'getUncategorizedFiles',
            'file_folder_distribution' => 'getFolderDistribution',
            'file_recent_ingestions' => 'getRecentIngestions',
            'file_suggest_categories' => 'suggestCategories',
            'file_duplicates_recommend' => 'recommendDuplicateResolutions',
        ],
        'App\\Services\\FileQuarantineService' => [
            'file_quarantine_stats' => 'getStats',
            'file_quarantine_pending' => 'getPendingReview',
            'file_scan_suspicious' => 'scanForSuspicious',
        ],
        'App\\Services\\PerceptualHashService' => ['file_phash_stats' => 'getStatistics'],
    ];

    public function up(): void
    {
        $this->updateBindings(true);
    }

    public function down(): void
    {
        $this->updateBindings(false);
    }

    private function updateBindings(bool $install): void
    {
        $changed = DB::transaction(function () use ($install): bool {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row === null) {
                return false;
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $before = $policy;
            $rule = ['target' => 'workflow', 'tree_scoped' => false, 'references' => []];
            foreach (self::SERVICES as $service => $methods) {
                foreach ($methods as $name => $method) {
                    if ($install) {
                        // Match the existing implementation; never replace operator rules
                        // or alter registry permissions, confirmation, or availability.
                        $registered = DB::table('agent_tool_registry')->where('name', $name)
                            ->where('service_class', $service)->where('method', $method)->exists();
                        if ($registered && ! array_key_exists($name, $policy['tools'])) {
                            $policy['tools'][$name] = $rule;
                        }
                    } elseif (($policy['tools'][$name] ?? null) === $rule) {
                        unset($policy['tools'][$name]);
                    }
                }
            }
            if ($policy === $before) {
                return false;
            }
            if (DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)
                ->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('Framework service configuration changed during migration.');
            }

            return true;
        });
        if ($changed) {
            app(SystemConfigService::class)->forget('cli.framework_services');
        }
    }
};
