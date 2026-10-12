<?php

use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SERVICES = [
        'App\\Services\\AIAutoTagService' => [
            'file_ai_tag_stats' => 'getStats', 'file_tag_quality_report' => 'getTagQualityReport',
            'file_review_ai_tags' => 'reviewTagQuality', 'file_tag_consistency_check' => 'checkTagConsistency',
        ],
        'App\\Services\\ThumbnailService' => [
            'file_thumbnail_stats' => 'getStats', 'file_thumbnail_cleanup' => 'cleanupOrphaned',
        ],
        'App\\Services\\FaceEmbeddingService' => [
            'file_face_stats' => 'getStats', 'file_face_clusters_review' => 'getClustersForReview',
        ],
        'App\\Services\\ExifWritebackService' => ['file_exif_writeback_stats' => 'getStats'],
        'App\\Services\\FileCategorizationRAGService' => [
            'file_rag_index_stats' => 'getStats', 'file_rag_sync' => 'syncWithRegistry',
        ],
        'App\\Services\\FileRegistryService' => [
            'file_verify_batch' => 'verifyBatch', 'file_detect_removed' => 'detectRemovedFiles',
            'file_cleanup_orphaned' => 'cleanupOrphanedFiles',
        ],
        'App\\Services\\AIOperationsService' => ['file_gpu_contention_status' => 'getGPUStatus'],
    ];

    public function up(): void
    {
        $this->updateBindings(true);
    }

    public function down(): void
    {
        // Retain accurate cleanup classification and explicit error reporting.
        // Release rollback restores the preceding service implementation.
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
                        // Bind only the existing implementation. Permissions, availability
                        // and operator target overrides remain table-owned.
                        $registered = DB::table('agent_tool_registry')->where('name', $name)
                            ->where('service_class', $service)->where('method', $method)->lockForUpdate()->first();
                        if ($registered !== null) {
                            $this->configureContract($registered);
                        }
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

    private function configureContract(object $tool): void
    {
        $changes = [];
        if (in_array($tool->name, ['file_cleanup_orphaned', 'file_thumbnail_cleanup'], true)
            && in_array($tool->risk_level, ['read', 'write'], true)) {
            // Both existing methods can delete derived files/artifacts.
            $changes = ['risk_level' => 'destructive', 'requires_confirmation' => true];
        }
        if ($tool->name === 'file_face_clusters_review') {
            $parameters = json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR);
            $parameter = ['type' => 'boolean', 'default' => true, 'x_context_only' => true];
            if (($parameters['type'] ?? null) === 'object') {
                if (! array_key_exists('fail_on_error', $parameters['properties'] ?? [])) {
                    $parameters['properties']['fail_on_error'] = $parameter;
                }
            } elseif (array_is_list($parameters) && $parameters !== []) {
                if (! in_array('fail_on_error', array_column($parameters, 'name'), true)) {
                    $parameters[] = ['name' => 'fail_on_error'] + $parameter;
                }
            } elseif (! array_key_exists('fail_on_error', $parameters)) {
                $parameters['fail_on_error'] = $parameter;
            }
            if ($parameters !== json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR)) {
                $changes['parameters'] = json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        }
        if ($changes === []) {
            return;
        }
        if (DB::table('agent_tool_registry')->where('id', $tool->id)
            ->where('risk_level', $tool->risk_level)->where('requires_confirmation', $tool->requires_confirmation)
            ->when($tool->parameters === null, fn ($query) => $query->whereNull('parameters'),
                fn ($query) => $query->whereRaw('CAST(parameters AS CHAR) = ?', [$tool->parameters]))
            ->update($changes + ['updated_at' => now()]) !== 1) {
            throw new RuntimeException('File tool contract changed during migration; no new bindings were installed.');
        }
    }
};
