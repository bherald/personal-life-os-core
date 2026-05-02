<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create log_analysis_snapshots table
        if (!Schema::hasTable('log_analysis_snapshots')) {
            Schema::create('log_analysis_snapshots', function (Blueprint $table) {
                $table->id();
                $table->timestamp('scanned_at')->useCurrent()->index();
                $table->unsignedSmallInteger('files_scanned')->default(0);
                $table->unsignedInteger('total_errors')->default(0);
                $table->unsignedInteger('unique_signatures')->default(0);
                $table->unsignedSmallInteger('bugs_found')->default(0);
                $table->unsignedSmallInteger('config_issues_found')->default(0);
                $table->unsignedSmallInteger('transient_count')->default(0);
                $table->unsignedSmallInteger('alert_by_design_count')->default(0);
                $table->enum('status', ['completed', 'partial', 'failed'])->default('completed');
                $table->json('signature_details')->nullable();
                $table->text('findings_summary')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index(['scanned_at', 'status']);
            });
        }

        // 2. Register 7 tools in agent_tool_registry
        $tools = [
            [
                'name' => 'log_scan_files',
                'service_class' => 'App\\Services\\LogAnalysisService',
                'method' => 'scanLogFiles',
                'description' => 'Inventory all known log files with sizes, modification times, and estimated error counts. Use this first to identify which logs need deeper analysis.',
                'parameters' => json_encode([
                    'hours_back' => ['type' => 'integer', 'required' => false, 'description' => 'Hours to look back for activity (default 24)'],
                ]),
                'returns_description' => 'Array with file list including size_kb, modified timestamp, in_window flag, and estimated_errors count',
                'permissions' => '["system:read"]',
                'risk_level' => 'read',
                'category' => 'log_analysis',
            ],
            [
                'name' => 'log_parse_errors',
                'service_class' => 'App\\Services\\LogAnalysisService',
                'method' => 'parseLogErrors',
                'description' => 'Extract error entries from a specific log file with multiline stack trace grouping. Supports Laravel log format and Horizon FAIL lines.',
                'parameters' => json_encode([
                    'file' => ['type' => 'string', 'required' => false, 'description' => 'Log filename (default: laravel.log)'],
                    'max_lines' => ['type' => 'integer', 'required' => false, 'description' => 'Max lines to read from tail (default 5000)'],
                    'severity' => ['type' => 'string', 'required' => false, 'description' => 'Filter: all|error|critical|warning (default: all)'],
                    'hours_back' => ['type' => 'integer', 'required' => false, 'description' => 'Hours to look back (default 2)'],
                ]),
                'returns_description' => 'Array with parsed errors including timestamp, level, message, stack_trace, and severity counts',
                'permissions' => '["system:read"]',
                'risk_level' => 'read',
                'category' => 'log_analysis',
            ],
            [
                'name' => 'log_cluster_signatures',
                'service_class' => 'App\\Services\\LogAnalysisService',
                'method' => 'clusterErrorSignatures',
                'description' => 'Deduplicate errors by stripping dynamic values (UUIDs, timestamps, IDs, IPs, vendor paths) and hashing normalized message. Groups identical error types.',
                'parameters' => json_encode([
                    'errors' => ['type' => 'array', 'required' => false, 'description' => 'Array of error objects from log_parse_errors. If empty, will parse from file params.'],
                    'file' => ['type' => 'string', 'required' => false, 'description' => 'Log filename if errors not provided'],
                    'hours_back' => ['type' => 'integer', 'required' => false, 'description' => 'Hours to look back if parsing from file'],
                ]),
                'returns_description' => 'Array with unique signature clusters including count, first/last seen, sample message, and dedup ratio',
                'permissions' => '["system:read"]',
                'risk_level' => 'read',
                'category' => 'log_analysis',
            ],
            [
                'name' => 'log_error_timeline',
                'service_class' => 'App\\Services\\LogAnalysisService',
                'method' => 'getErrorTimeline',
                'description' => 'Time-bucketed error frequency with trend direction (rising/falling/stable). Shows error distribution over time.',
                'parameters' => json_encode([
                    'file' => ['type' => 'string', 'required' => false, 'description' => 'Log filename (default: laravel.log)'],
                    'hours_back' => ['type' => 'integer', 'required' => false, 'description' => 'Hours to look back (default 24)'],
                    'bucket_minutes' => ['type' => 'integer', 'required' => false, 'description' => 'Bucket size in minutes (default 30)'],
                ]),
                'returns_description' => 'Array with time buckets, error counts per bucket, trend direction, and average rates',
                'permissions' => '["system:read"]',
                'risk_level' => 'read',
                'category' => 'log_analysis',
            ],
            [
                'name' => 'log_correlate_across',
                'service_class' => 'App\\Services\\LogAnalysisService',
                'method' => 'correlateAcrossLogs',
                'description' => 'Find errors within N seconds of each other across different log files. Correlated errors often share a root cause.',
                'parameters' => json_encode([
                    'files' => ['type' => 'array', 'required' => false, 'description' => 'List of log filenames to scan (default: all known files)'],
                    'window_seconds' => ['type' => 'integer', 'required' => false, 'description' => 'Correlation window in seconds (default 30)'],
                    'hours_back' => ['type' => 'integer', 'required' => false, 'description' => 'Hours to look back (default 2)'],
                ]),
                'returns_description' => 'Array with correlation groups showing timestamp, files involved, and error summaries',
                'permissions' => '["system:read"]',
                'risk_level' => 'read',
                'category' => 'log_analysis',
            ],
            [
                'name' => 'log_compare_baseline',
                'service_class' => 'App\\Services\\LogAnalysisService',
                'method' => 'compareToBaseline',
                'description' => 'Compare current error window against historical baseline. Identifies new errors, spikes (3x+), and resolved signatures.',
                'parameters' => json_encode([
                    'file' => ['type' => 'string', 'required' => false, 'description' => 'Log filename (default: laravel.log)'],
                    'current_hours' => ['type' => 'integer', 'required' => false, 'description' => 'Current window hours (default 2)'],
                    'baseline_hours' => ['type' => 'integer', 'required' => false, 'description' => 'Baseline window hours (default 48)'],
                ]),
                'returns_description' => 'Array with new_errors, spikes, resolved lists and summary counts',
                'permissions' => '["system:read"]',
                'risk_level' => 'read',
                'category' => 'log_analysis',
            ],
            [
                'name' => 'log_save_snapshot',
                'service_class' => 'App\\Services\\LogAnalysisService',
                'method' => 'saveAnalysisSnapshot',
                'description' => 'Persist structured analysis findings to log_analysis_snapshots table for trend tracking across runs.',
                'parameters' => json_encode([
                    'scan_result' => ['type' => 'object', 'required' => true, 'description' => 'Scan result from log_scan_files'],
                    'classifications' => ['type' => 'object', 'required' => true, 'description' => 'Classification counts: {bug, config_issue, transient, alert_by_design}'],
                    'signature_details' => ['type' => 'array', 'required' => false, 'description' => 'Array of signature cluster details'],
                    'findings_summary' => ['type' => 'string', 'required' => false, 'description' => 'Human-readable summary of findings'],
                    'status' => ['type' => 'string', 'required' => false, 'description' => 'Snapshot status: completed|partial|failed'],
                ]),
                'returns_description' => 'Array with snapshot_id of saved record',
                'permissions' => '["system:write"]',
                'risk_level' => 'write',
                'category' => 'log_analysis',
            ],
        ];

        foreach ($tools as $tool) {
            try {
                $columns = 'name, service_class, method, description, parameters, returns_description, permissions, risk_level, category, enabled, source';
                $placeholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, 1, \'config\'';
                $values = [
                    $tool['name'],
                    $tool['service_class'],
                    $tool['method'],
                    $tool['description'],
                    $tool['parameters'],
                    $tool['returns_description'],
                    $tool['permissions'],
                    $tool['risk_level'],
                    $tool['category'],
                ];

                DB::insert("
                    INSERT INTO agent_tool_registry ({$columns})
                    VALUES ({$placeholders})
                ", $values);
            } catch (\Exception $e) {
                // Skip duplicates (idempotent)
            }
        }

        // 3. Add scheduled job
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'log_analyst_agent'");
        if (!$exists) {
            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, command, cron_expression, job_type, enabled, category,
                 timeout_minutes, run_in_background, without_overlapping, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'log_analyst_agent',
                'Log analysis agent: scans log files for errors, clusters signatures, detects new bugs, config issues, and spikes vs 48h baseline',
                'log:analyst',
                '15 */2 * * *',
                'agent_task',
                1,
                'Agent',
                15,
                1,
                1,
                json_encode(['notify' => true]),
            ]);
        }

        // 4. Register handoff agent
        $existingAgent = DB::selectOne("SELECT id FROM agent_handoff_agents WHERE agent_id = 'log-analyst'");
        if (!$existingAgent) {
            DB::insert("
                INSERT INTO agent_handoff_agents
                (agent_id, name, description, capabilities, max_concurrent_handoffs, timeout_seconds, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'log-analyst',
                'Log Analyst',
                'Log file analysis — parses, clusters, and classifies production log errors to detect bugs and config issues',
                json_encode(['log_analysis', 'error_detection', 'signature_clustering', 'baseline_comparison', 'cross_log_correlation']),
                3,
                300,
                1,
            ]);
        }

        // 5. Add routing rule
        $existingRule = DB::selectOne("SELECT id FROM agent_handoff_routing_rules WHERE task_pattern = 'log_analysis'");
        if (!$existingRule) {
            DB::insert("
                INSERT INTO agent_handoff_routing_rules
                (name, task_pattern, target_agent_id, conditions, confidence, reason, priority, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'Log analysis routing',
                'log_analysis',
                'log-analyst',
                json_encode(['keywords' => ['log', 'error', 'stack trace', 'exception', 'log file']]),
                0.90,
                'Route log file analysis tasks to log-analyst agent',
                0,
                1,
            ]);
        }
    }

    public function down(): void
    {
        $toolNames = [
            'log_scan_files', 'log_parse_errors', 'log_cluster_signatures',
            'log_error_timeline', 'log_correlate_across', 'log_compare_baseline',
            'log_save_snapshot',
        ];

        $placeholders = implode(',', array_fill(0, count($toolNames), '?'));
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ($placeholders)", $toolNames);
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'log_analyst_agent'");
        DB::delete("DELETE FROM agent_handoff_routing_rules WHERE task_pattern = 'log_analysis'");
        DB::delete("DELETE FROM agent_handoff_agents WHERE agent_id = 'log-analyst'");
        Schema::dropIfExists('log_analysis_snapshots');
    }
};
