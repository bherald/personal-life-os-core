<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Engine\DatabaseLayer;
use App\Services\ScheduledJobService;
use App\Services\WorkflowTemplateService;
use App\Services\WorkflowApprovalService;
use App\Services\WorkflowMetricsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Exception;

class WorkflowController extends Controller
{
    private DatabaseLayer $db;
    private ScheduledJobService $scheduledJobService;

    public function __construct(ScheduledJobService $scheduledJobService)
    {
        $this->db = new DatabaseLayer();
        $this->scheduledJobService = $scheduledJobService;
    }

    public function index(Request $request): JsonResponse
    {
        $workflows = $request->query('active')
            ? $this->db->getActiveWorkflows()
            : $this->db->getAllWorkflows();

        if ($search = $request->query('search')) {
            $workflows = array_filter($workflows, function($w) use ($search) {
                return stripos($w->name, $search) !== false
                    || stripos($w->description ?? '', $search) !== false;
            });
        }

        return response()->json([
            'success' => true,
            'data' => array_values($workflows)
        ]);
    }

    public function show(int $id): JsonResponse
    {
        // Get workflow using raw SQL
        $sql = "SELECT * FROM workflows WHERE id = ? LIMIT 1";
        $workflows = DB::select($sql, [$id]);
        $workflow = $workflows[0] ?? null;

        if (!$workflow) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Workflow not found']
            ], 404);
        }

        $nodes = $this->db->getWorkflowNodes($id);

        // Attach configs to each node using raw SQL
        foreach ($nodes as $node) {
            $sql = "SELECT * FROM workflow_node_configs WHERE workflow_node_id = ?";
            $configs = DB::select($sql, [$node->id]);
            $node->configs = $configs;
        }

        $retryConfig = $this->db->getRetryConfig($id);

        // Include backoff intervals if retry config exists
        if ($retryConfig) {
            $retryConfig->intervals = $this->db->getRetryBackoffIntervals($retryConfig->id);
        }

        $defaults = $this->db->getWorkflowDefaults($id);

        return response()->json([
            'success' => true,
            'data' => [
                'workflow' => $workflow,
                'nodes' => $nodes,
                'retry_config' => $retryConfig,
                'defaults' => $defaults
            ]
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|unique:workflows,name',
            'description' => 'nullable|string',
            'schedule' => 'nullable|string',
            'error_handling' => 'in:stop,continue',
            'active' => 'boolean'
        ]);

        try {
            DB::insert("INSERT INTO workflows (name, description, schedule, active, error_handling, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)", [
                $request->name,
                $request->description,
                $request->schedule,
                $request->active ?? true,
                $request->error_handling ?? 'stop',
                now(),
                now(),
            ]);
            $workflowId = (int) DB::getPdo()->lastInsertId();

            // Sync schedule to scheduled_jobs table
            if (!empty($request->schedule)) {
                $this->scheduledJobService->syncWorkflowSchedule(
                    $workflowId,
                    $request->name,
                    $request->schedule,
                    $request->active ?? true
                );
            }

            return response()->json([
                'success' => true,
                'data' => ['id' => $workflowId]
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'CREATE_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        \Log::info('Workflow update request received', [
            'workflow_id' => $id,
            'has_nodes' => $request->has('nodes'),
            'nodes_count' => $request->has('nodes') ? count($request->nodes) : 0,
            'has_retry_config' => $request->has('retry_config'),
            'request_data' => $request->all()
        ]);

        $request->validate([
            'name' => 'string|unique:workflows,name,' . $id,
            'description' => 'nullable|string',
            'schedule' => 'nullable|string',
            'error_handling' => 'in:stop,continue',
            'active' => 'boolean',
            'nodes' => 'nullable|array',
            'nodes.*.node_type' => 'required_with:nodes|string',
            'nodes.*.node_order' => 'required_with:nodes|integer',
            'nodes.*.config' => 'nullable|string',
            'retry_config' => 'nullable|array',
            'retry_config.enabled' => 'boolean',
            'retry_config.max_attempts' => 'integer|min:1|max:10',
            'retry_config.notify_on_failure' => 'nullable|string',
            'retry_config.backoff_strategy' => 'in:exponential,linear,fixed'
        ]);

        try {
            DB::beginTransaction();

            // Check if workflow exists first using raw SQL
            $sql = "SELECT * FROM workflows WHERE id = ? LIMIT 1";
            $workflows = DB::select($sql, [$id]);
            $workflow = $workflows[0] ?? null;
            if (!$workflow) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'Workflow not found']
                ], 404);
            }

            // Update workflow basic info
            $updateData = array_merge(
                $request->only(['name', 'description', 'schedule', 'error_handling', 'active']),
                ['updated_at' => now()]
            );
            $setClauses = array_map(fn($k) => "{$k} = ?", array_keys($updateData));
            $bindings = array_merge(array_values($updateData), [$id]);
            DB::update("UPDATE workflows SET " . implode(', ', $setClauses) . " WHERE id = ?", $bindings);

            // Update nodes if provided
            if ($request->has('nodes')) {
                // Delete existing nodes using raw SQL
                $sql = "SELECT id FROM workflow_nodes WHERE workflow_id = ?";
                $existingNodes = DB::select($sql, [$id]);
                foreach ($existingNodes as $node) {
                    DB::delete("DELETE FROM workflow_node_configs WHERE workflow_node_id = ?", [$node->id]);
                }
                DB::delete("DELETE FROM workflow_nodes WHERE workflow_id = ?", [$id]);

                // Insert new nodes
                foreach ($request->nodes as $nodeData) {
                    DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
                        $id,
                        $nodeData['node_type'],
                        $nodeData['node_order'],
                        now(),
                    ]);
                    $nodeId = (int) DB::getPdo()->lastInsertId();

                    // Parse and insert config if provided
                    if (!empty($nodeData['config'])) {
                        $config = json_decode($nodeData['config'], true);

                        // Validate JSON decode
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $errorMsg = sprintf(
                                'Invalid JSON in config for node %s (order %d): %s',
                                $nodeData['node_type'],
                                $nodeData['node_order'],
                                json_last_error_msg()
                            );
                            \Log::error('Workflow node config error', [
                                'workflow_id' => $id,
                                'node_type' => $nodeData['node_type'],
                                'error' => $errorMsg,
                                'config_received' => $nodeData['config']
                            ]);
                            throw new Exception($errorMsg);
                        }

                        if (!is_array($config)) {
                            $errorMsg = sprintf(
                                'Config for node %s (order %d) must be a JSON object, got: %s',
                                $nodeData['node_type'],
                                $nodeData['node_order'],
                                gettype($config)
                            );
                            \Log::error('Workflow node config error', [
                                'workflow_id' => $id,
                                'node_type' => $nodeData['node_type'],
                                'error' => $errorMsg
                            ]);
                            throw new Exception($errorMsg);
                        }

                        if (empty($config)) {
                            \Log::warning('Empty config detected for node', [
                                'workflow_id' => $id,
                                'node_type' => $nodeData['node_type'],
                                'node_order' => $nodeData['node_order']
                            ]);
                        }

                        foreach ($config as $key => $value) {
                            DB::insert("INSERT INTO workflow_node_configs (workflow_node_id, config_key, config_value) VALUES (?, ?, ?)", [
                                $nodeId,
                                $key,
                                is_array($value) ? json_encode($value) : $value,
                            ]);
                        }
                    } else {
                        // Log warning if config is expected but not provided
                        \Log::warning('No config provided for node', [
                            'workflow_id' => $id,
                            'node_type' => $nodeData['node_type'],
                            'node_order' => $nodeData['node_order']
                        ]);
                    }
                }
            }

            // Handle retry configuration
            if ($request->has('retry_config')) {
                $retryConfig = $request->retry_config;
                if ($retryConfig['enabled'] ?? false) {
                    $this->db->saveRetryConfig($id, $retryConfig);
                } else {
                    $this->db->deleteRetryConfig($id);
                }
            }

            DB::commit();
            \Log::info('Workflow update successful', ['workflow_id' => $id]);

            // Sync schedule to scheduled_jobs table (after commit)
            $workflowName = $request->input('name', $workflow->name);
            $schedule = $request->input('schedule', $workflow->schedule);
            $active = $request->input('active', $workflow->active);
            $this->scheduledJobService->syncWorkflowSchedule(
                $id,
                $workflowName,
                $schedule,
                (bool) $active
            );

            return response()->json(['success' => true]);
        } catch (Exception $e) {
            DB::rollBack();
            \Log::error('Workflow update failed', [
                'workflow_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'UPDATE_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            // Get workflow name before deletion for scheduled job cleanup
            $sql = "SELECT name FROM workflows WHERE id = ? LIMIT 1";
            $workflows = DB::select($sql, [$id]);
            $workflow = $workflows[0] ?? null;

            $deleted = DB::delete("DELETE FROM workflows WHERE id = ?", [$id]);

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'Workflow not found']
                ], 404);
            }

            // Delete associated scheduled job
            if ($workflow) {
                $this->scheduledJobService->deleteWorkflowSchedule($id, $workflow->name);
            }

            return response()->json(['success' => true]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'DELETE_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    public function run(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'input' => 'nullable|array',
        ]);

        // Get workflow using raw SQL
        $sql = "SELECT * FROM workflows WHERE id = ? LIMIT 1";
        $workflows = DB::select($sql, [$id]);
        $workflow = $workflows[0] ?? null;

        if (!$workflow) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Workflow not found']
            ], 404);
        }

        try {
            $input = $request->input('input', []);

            \App\Jobs\ExecuteWorkflow::dispatch($workflow->name, $workflow->id, $input);

            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'queued',
                    'message' => 'Workflow queued for execution',
                    'workflow_id' => $workflow->id,
                    'input_keys' => array_keys($input),
                ]
            ], 202);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'EXECUTION_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    public function toggle(int $id): JsonResponse
    {
        // Get workflow using raw SQL
        $sql = "SELECT * FROM workflows WHERE id = ? LIMIT 1";
        $workflows = DB::select($sql, [$id]);
        $workflow = $workflows[0] ?? null;

        if (!$workflow) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Workflow not found']
            ], 404);
        }

        $newActive = !$workflow->active;
        DB::update("UPDATE workflows SET active = ?, updated_at = ? WHERE id = ?", [$newActive, now(), $id]);

        // Sync schedule enabled state to scheduled_jobs table
        if (!empty($workflow->schedule)) {
            $this->scheduledJobService->syncWorkflowSchedule(
                $id,
                $workflow->name,
                $workflow->schedule,
                (bool) $newActive
            );
        }

        return response()->json([
            'success' => true,
            'data' => ['active' => $newActive]
        ]);
    }

    public function clone(int $id): JsonResponse
    {
        // Get workflow using raw SQL
        $sql = "SELECT * FROM workflows WHERE id = ? LIMIT 1";
        $workflows = DB::select($sql, [$id]);
        $workflow = $workflows[0] ?? null;

        if (!$workflow) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Workflow not found']
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Create cloned workflow
            $newName = $workflow->name . '_copy';
            $counter = 1;
            // Check if name exists using raw SQL
            $sql = "SELECT COUNT(*) as count FROM workflows WHERE name = ?";
            while ((DB::select($sql, [$newName])[0]->count ?? 0) > 0) {
                $newName = $workflow->name . '_copy_' . $counter++;
            }

            DB::insert("INSERT INTO workflows (name, description, schedule, active, error_handling, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)", [
                $newName,
                $workflow->description,
                null,
                false,
                $workflow->error_handling,
                now(),
                now(),
            ]);
            $newWorkflowId = (int) DB::getPdo()->lastInsertId();

            // Clone nodes using raw SQL
            $sql = "SELECT * FROM workflow_nodes WHERE workflow_id = ?";
            $nodes = DB::select($sql, [$id]);
            foreach ($nodes as $node) {
                DB::insert("INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)", [
                    $newWorkflowId,
                    $node->node_type,
                    $node->node_order,
                    now(),
                ]);
                $newNodeId = (int) DB::getPdo()->lastInsertId();

                // Clone configs using raw SQL
                $sql = "SELECT * FROM workflow_node_configs WHERE workflow_node_id = ?";
                $configs = DB::select($sql, [$node->id]);
                foreach ($configs as $config) {
                    DB::insert("INSERT INTO workflow_node_configs (workflow_node_id, config_key, config_value) VALUES (?, ?, ?)", [
                        $newNodeId,
                        $config->config_key,
                        $config->config_value,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => ['id' => $newWorkflowId, 'name' => $newName]
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => ['code' => 'CLONE_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    /**
     * Create a backup of a workflow (auto-backup on edit or manual)
     */
    public function createBackup(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'backup_type' => 'in:auto,manual,pre_edit',
            'description' => 'nullable|string|max:500'
        ]);

        try {
            // Get complete workflow snapshot using raw SQL
            $sql = "SELECT * FROM workflows WHERE id = ? LIMIT 1";
            $workflows = DB::select($sql, [$id]);
            $workflow = $workflows[0] ?? null;

            if (!$workflow) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'Workflow not found']
                ], 404);
            }

            // Get all nodes
            $sql = "SELECT * FROM workflow_nodes WHERE workflow_id = ? ORDER BY node_order";
            $nodes = DB::select($sql, [$id]);

            // Get configs for each node
            foreach ($nodes as $node) {
                $sql = "SELECT * FROM workflow_node_configs WHERE workflow_node_id = ?";
                $node->configs = DB::select($sql, [$node->id]);
            }

            // Get retry config (if tables exist)
            $retryConfig = null;
            try {
                $sql = "SELECT * FROM retry_configs WHERE workflow_id = ? LIMIT 1";
                $retryConfigs = DB::select($sql, [$id]);
                $retryConfig = $retryConfigs[0] ?? null;

                if ($retryConfig) {
                    $sql = "SELECT * FROM retry_backoff_intervals WHERE retry_config_id = ? ORDER BY attempt_number";
                    $retryConfig->intervals = DB::select($sql, [$retryConfig->id]);
                }
            } catch (\Exception $e) {
                // Tables don't exist, skip retry config
                \Log::info('Retry config tables not found, skipping');
            }

            // Get workflow defaults
            $sql = "SELECT * FROM workflow_defaults WHERE workflow_id = ?";
            $defaults = DB::select($sql, [$id]);

            // Build backup data
            $backupData = [
                'workflow' => $workflow,
                'nodes' => $nodes,
                'retry_config' => $retryConfig,
                'defaults' => $defaults,
                'backed_up_at' => now()->toIso8601String()
            ];

            // Insert backup using raw SQL
            $sql = "INSERT INTO workflow_backups
                    (workflow_id, backup_data, backup_type, description, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())";

            DB::insert($sql, [
                $id,
                json_encode($backupData),
                $request->input('backup_type', 'auto'),
                $request->input('description'),
                auth()->id() // null if not authenticated
            ]);

            // Get the ID of the inserted backup
            $backupId = DB::getPdo()->lastInsertId();

            return response()->json([
                'success' => true,
                'data' => ['backup_id' => $backupId]
            ], 201);

        } catch (Exception $e) {
            \Log::error('Backup creation failed', [
                'workflow_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'BACKUP_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    /**
     * List all backups for a workflow
     */
    public function listBackups(int $id): JsonResponse
    {
        try {
            // Verify workflow exists
            $sql = "SELECT id FROM workflows WHERE id = ? LIMIT 1";
            $workflows = DB::select($sql, [$id]);
            if (empty($workflows)) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'Workflow not found']
                ], 404);
            }

            // Get backups (without full backup_data for list view)
            $sql = "SELECT id, workflow_id, backup_type, description, created_by, created_at
                    FROM workflow_backups
                    WHERE workflow_id = ?
                    ORDER BY created_at DESC";
            $backups = DB::select($sql, [$id]);

            return response()->json([
                'success' => true,
                'data' => $backups
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'LIST_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    /**
     * Restore workflow from a backup
     */
    public function restoreBackup(int $id, int $backupId): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Get backup using raw SQL
            $sql = "SELECT * FROM workflow_backups WHERE id = ? AND workflow_id = ? LIMIT 1";
            $backups = DB::select($sql, [$backupId, $id]);
            $backup = $backups[0] ?? null;

            if (!$backup) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'Backup not found']
                ], 404);
            }

            $backupData = json_decode($backup->backup_data, true);

            // Restore workflow basic info
            $sql = "UPDATE workflows
                    SET name = ?, description = ?, schedule = ?, active = ?, error_handling = ?, updated_at = NOW()
                    WHERE id = ?";
            DB::update($sql, [
                $backupData['workflow']['name'] ?? '',
                $backupData['workflow']['description'] ?? null,
                $backupData['workflow']['schedule'] ?? null,
                $backupData['workflow']['active'] ?? 1,
                $backupData['workflow']['error_handling'] ?? 'stop',
                $id
            ]);

            // Delete existing nodes and configs
            $sql = "SELECT id FROM workflow_nodes WHERE workflow_id = ?";
            $existingNodes = DB::select($sql, [$id]);
            foreach ($existingNodes as $node) {
                DB::delete("DELETE FROM workflow_node_configs WHERE workflow_node_id = ?", [$node->id]);
            }
            DB::delete("DELETE FROM workflow_nodes WHERE workflow_id = ?", [$id]);

            // Restore nodes
            foreach ($backupData['nodes'] as $nodeData) {
                $sql = "INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at)
                        VALUES (?, ?, ?, NOW())";
                DB::insert($sql, [$id, $nodeData['node_type'], $nodeData['node_order']]);
                $nodeId = DB::getPdo()->lastInsertId();

                // Restore configs
                foreach ($nodeData['configs'] ?? [] as $config) {
                    $sql = "INSERT INTO workflow_node_configs (workflow_node_id, config_key, config_value)
                            VALUES (?, ?, ?)";
                    DB::insert($sql, [$nodeId, $config['config_key'], $config['config_value']]);
                }
            }

            // Restore retry config if exists
            if (!empty($backupData['retry_config'])) {
                $retryConfig = $backupData['retry_config'];
                $this->db->saveRetryConfig($id, (array) $retryConfig);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => ['message' => 'Workflow restored successfully']
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            \Log::error('Backup restore failed', [
                'workflow_id' => $id,
                'backup_id' => $backupId,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'RESTORE_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    /**
     * Delete a specific backup
     */
    public function deleteBackup(int $backupId): JsonResponse
    {
        try {
            $sql = "DELETE FROM workflow_backups WHERE id = ?";
            $deleted = DB::delete($sql, [$backupId]);

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'Backup not found']
                ], 404);
            }

            return response()->json(['success' => true]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'DELETE_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    // =========================================================================
    // TEMPLATES
    // =========================================================================

    public function listTemplates(Request $request): JsonResponse
    {
        try {
            $service = app(WorkflowTemplateService::class);
            $category = $request->query('category');
            $templates = $service->listTemplates($category);

            return response()->json(['success' => true, 'data' => $templates]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FETCH_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    public function createTemplate(Request $request): JsonResponse
    {
        try {
            $service = app(WorkflowTemplateService::class);
            $id = $service->createTemplate(
                $request->input('name'),
                $request->input('description'),
                $request->input('category'),
                $request->input('workflow_id')
            );

            return response()->json(['success' => true, 'data' => ['id' => $id]], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'CREATE_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    public function instantiateTemplate(int $id, Request $request): JsonResponse
    {
        try {
            $service = app(WorkflowTemplateService::class);
            $result = $service->instantiateTemplate($id, $request->input('overrides'));

            return response()->json(['success' => true, 'data' => $result], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INSTANTIATE_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    // =========================================================================
    // APPROVALS
    // =========================================================================

    public function pendingApprovals(): JsonResponse
    {
        try {
            $service = app(WorkflowApprovalService::class);
            $pending = $service->getPendingApprovals();

            return response()->json(['success' => true, 'data' => $pending]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FETCH_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    // =========================================================================
    // DRY RUN & METRICS
    // =========================================================================

    public function dryRun(int $id): JsonResponse
    {
        try {
            $workflow = DB::select('SELECT * FROM workflows WHERE id = ?', [$id]);
            if (empty($workflow)) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'Workflow not found']
                ], 404);
            }

            $nodes = DB::select(
                'SELECT id, node_type, node_order, compensation_config FROM workflow_nodes WHERE workflow_id = ? ORDER BY node_order',
                [$id]
            );

            $results = [];
            foreach ($nodes as $node) {
                $config = json_decode($node->compensation_config, true) ?: [];
                $validation = ['valid' => true, 'warnings' => []];

                // Basic config validation per node type
                if (empty($config)) {
                    $validation['warnings'][] = 'No configuration set';
                }

                $results[] = [
                    'node_id' => $node->id,
                    'name' => $node->node_type,
                    'type' => $node->node_type,
                    'validation' => $validation,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'workflow_id' => $id,
                    'node_count' => count($nodes),
                    'results' => $results,
                    'dry_run' => true,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'DRY_RUN_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    public function workflowMetrics(int $id): JsonResponse
    {
        try {
            $service = app(WorkflowMetricsService::class);
            $stats = $service->getWorkflowStats($id);
            $slowNodes = $service->getSlowNodes();

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => $stats,
                    'slow_nodes' => $slowNodes,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'METRICS_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    public function cacheStats(int $id): JsonResponse
    {
        try {
            $nodes = DB::select(
                'SELECT node_type, COUNT(*) as count FROM workflow_nodes WHERE workflow_id = ? GROUP BY node_type',
                [$id]
            );

            $cacheKeys = Cache::get("workflow_{$id}_cache_keys", []);
            $hitCount = Cache::get("workflow_{$id}_cache_hits", 0);
            $missCount = Cache::get("workflow_{$id}_cache_misses", 0);
            $total = $hitCount + $missCount;

            return response()->json([
                'success' => true,
                'data' => [
                    'workflow_id' => $id,
                    'node_types' => $nodes,
                    'cache_keys' => count($cacheKeys),
                    'hit_rate' => $total > 0 ? round($hitCount / $total * 100, 1) : 0,
                    'hits' => $hitCount,
                    'misses' => $missCount,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'CACHE_STATS_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }
}
