<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemConfigSecretService;
use App\Services\SystemConfigService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

class ConfigurationController extends Controller
{
    public function __construct(
        private readonly SystemConfigSecretService $secretService,
        private readonly SystemConfigService $systemConfigService
    ) {}

    /**
     * Get all configurations grouped by section
     */
    public function index(): JsonResponse
    {
        try {
            // Get all configs using raw SQL
            $sql = 'SELECT * FROM system_configs ORDER BY section, config_key';
            $configs = DB::select($sql);

            // Group by section manually
            $grouped = [];
            foreach ($configs as $item) {
                if (! isset($grouped[$item->section])) {
                    $grouped[$item->section] = [];
                }
                $grouped[$item->section][] = $this->projectConfig($item);
            }

            return response()->json([
                'success' => true,
                'data' => $grouped,
            ]);
        } catch (Throwable $e) {
            $this->logFailure('fetch_all', $e);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'FETCH_FAILED', 'message' => 'Unable to fetch configuration.'],
            ], 500);
        }
    }

    /**
     * Get configurations for a specific section
     */
    public function getBySection(string $section): JsonResponse
    {
        try {
            // Get configs by section using raw SQL
            $sql = 'SELECT * FROM system_configs WHERE section = ? ORDER BY config_key';
            $configRecords = DB::select($sql, [$section]);

            $configs = array_map(fn ($item): array => $this->projectConfig($item), $configRecords);

            return response()->json([
                'success' => true,
                'data' => $configs,
            ]);
        } catch (Throwable $e) {
            $this->logFailure('fetch_section', $e, ['section' => $section]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'FETCH_FAILED', 'message' => 'Unable to fetch configuration.'],
            ], 500);
        }
    }

    /**
     * Update multiple configuration values
     */
    public function updateMultiple(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'configs' => 'required|array',
            'configs.*.id' => 'required|integer|exists:system_configs,id',
            'configs.*.value' => 'present|nullable',
            'configs.*.clear_secret' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_FAILED', 'message' => $validator->errors()->first()],
            ], 422);
        }

        try {
            DB::beginTransaction();

            $changedKeys = [];
            $updated = 0;
            $preserved = 0;

            foreach ($request->configs as $configData) {
                // Get config using raw SQL
                $sql = 'SELECT * FROM system_configs WHERE id = ? LIMIT 1 FOR UPDATE';
                $configs = DB::select($sql, [$configData['id']]);
                $config = $configs[0] ?? null;

                if (! $config) {
                    continue;
                }

                $existingValue = $this->parseValue($config->config_value, $config->data_type);
                $merge = $this->secretService->mergeForUpdate(
                    $config->config_key,
                    $configData['value'],
                    $existingValue,
                    (bool) ($configData['clear_secret'] ?? false)
                );

                if ($merge['preserved']) {
                    $preserved++;
                }

                if ($merge['preserved'] && $this->secretService->isSecretKey($config->config_key)) {
                    continue;
                }

                $value = $this->formatValue($merge['value'], $config->data_type);

                DB::update(
                    'UPDATE system_configs SET config_value = ?, updated_at = ? WHERE id = ?',
                    [$value, now(), $configData['id']]
                );
                $changedKeys[] = $config->section.'.'.$config->config_key;
                $updated++;
            }

            DB::commit();
            foreach (array_unique($changedKeys) as $key) {
                $this->systemConfigService->forget($key);
            }

            Log::info('Configuration bulk update completed', [
                'action' => 'bulk_update',
                'operator_id' => $request->user()?->getAuthIdentifier(),
                'config_keys' => array_values(array_unique($changedKeys)),
                'updated' => $updated,
                'preserved' => $preserved,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Configuration updated successfully',
                'data' => ['updated' => $updated, 'preserved' => $preserved],
            ]);
        } catch (InvalidArgumentException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'error' => ['code' => 'SECRET_PLACEHOLDER_INVALID', 'message' => $e->getMessage()],
            ], 422);
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->logFailure('bulk_update', $e);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'UPDATE_FAILED', 'message' => 'Unable to update configuration.'],
            ], 500);
        }
    }

    /**
     * Update a single configuration value
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'value' => 'present|nullable',
            'clear_secret' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_FAILED', 'message' => $validator->errors()->first()],
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Get config using raw SQL
            $sql = 'SELECT * FROM system_configs WHERE id = ? LIMIT 1 FOR UPDATE';
            $configs = DB::select($sql, [$id]);
            $config = $configs[0] ?? null;

            if (! $config) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'Configuration not found'],
                ], 404);
            }

            $merge = $this->secretService->mergeForUpdate(
                $config->config_key,
                $request->input('value'),
                $this->parseValue($config->config_value, $config->data_type),
                $request->boolean('clear_secret')
            );

            if (! ($merge['preserved'] && $this->secretService->isSecretKey($config->config_key))) {
                $value = $this->formatValue($merge['value'], $config->data_type);
                DB::update(
                    'UPDATE system_configs SET config_value = ?, updated_at = ? WHERE id = ?',
                    [$value, now(), $id]
                );
            }

            DB::commit();
            if (! ($merge['preserved'] && $this->secretService->isSecretKey($config->config_key))) {
                $this->systemConfigService->forget($config->section.'.'.$config->config_key);
            }

            Log::info('Configuration update completed', [
                'action' => 'update',
                'operator_id' => $request->user()?->getAuthIdentifier(),
                'section' => $config->section,
                'config_key' => $config->config_key,
                'preserved' => $merge['preserved'],
                'cleared' => $merge['cleared'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Configuration updated successfully',
                'data' => ['preserved' => $merge['preserved'], 'cleared' => $merge['cleared']],
            ]);
        } catch (InvalidArgumentException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'error' => ['code' => 'SECRET_PLACEHOLDER_INVALID', 'message' => $e->getMessage()],
            ], 422);
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->logFailure('update', $e, ['config_id' => $id]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'UPDATE_FAILED', 'message' => 'Unable to update configuration.'],
            ], 500);
        }
    }

    /**
     * Create a new configuration entry
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'section' => 'required|string|max:255',
            'config_key' => 'required|string|max:255|unique:system_configs,config_key',
            'config_value' => 'nullable',
            'data_type' => 'required|in:string,number,boolean,json',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_FAILED', 'message' => $validator->errors()->first()],
            ], 422);
        }

        try {
            $value = $this->formatValue($request->config_value, $request->data_type);

            DB::insert(
                'INSERT INTO system_configs (section, config_key, config_value, data_type, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$request->section, $request->config_key, $value, $request->data_type, $request->description, now(), now()]
            );
            $id = DB::getPdo()->lastInsertId();
            $this->systemConfigService->forget($request->section.'.'.$request->config_key);

            Log::info('Configuration created', [
                'action' => 'create',
                'operator_id' => $request->user()?->getAuthIdentifier(),
                'section' => $request->section,
                'config_key' => $request->config_key,
            ]);

            return response()->json([
                'success' => true,
                'data' => ['id' => $id],
                'message' => 'Configuration created successfully',
            ], 201);
        } catch (Throwable $e) {
            $this->logFailure('create', $e);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'CREATE_FAILED', 'message' => 'Unable to create configuration.'],
            ], 500);
        }
    }

    /**
     * Delete a configuration entry
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $config = DB::selectOne(
                'SELECT section, config_key FROM system_configs WHERE id = ? LIMIT 1',
                [$id]
            );
            $deleted = DB::delete('DELETE FROM system_configs WHERE id = ?', [$id]);

            if (! $deleted) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'Configuration not found'],
                ], 404);
            }

            $this->systemConfigService->forget($config->section.'.'.$config->config_key);

            Log::info('Configuration deleted', [
                'action' => 'delete',
                'operator_id' => $request->user()?->getAuthIdentifier(),
                'section' => $config->section,
                'config_key' => $config->config_key,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Configuration deleted successfully',
            ]);
        } catch (Throwable $e) {
            $this->logFailure('delete', $e, ['config_id' => $id]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'DELETE_FAILED', 'message' => 'Unable to delete configuration.'],
            ], 500);
        }
    }

    /**
     * Initialize default configurations
     */
    public function initializeDefaults(Request $request): JsonResponse
    {
        try {
            $defaults = [
                // System section
                [
                    'section' => 'system',
                    'config_key' => 'app_name',
                    'config_value' => 'AI Workflow Platform',
                    'data_type' => 'string',
                    'description' => 'Application name',
                ],
                [
                    'section' => 'system',
                    'config_key' => 'debug_mode',
                    'config_value' => 'false',
                    'data_type' => 'boolean',
                    'description' => 'Enable debug mode',
                ],
                [
                    'section' => 'system',
                    'config_key' => 'max_concurrent_workflows',
                    'config_value' => '5',
                    'data_type' => 'number',
                    'description' => 'Maximum concurrent workflow executions',
                ],

                // Workflow Defaults section
                [
                    'section' => 'workflow_defaults',
                    'config_key' => 'default_timeout',
                    'config_value' => '3600',
                    'data_type' => 'number',
                    'description' => 'Default workflow timeout in seconds',
                ],
                [
                    'section' => 'workflow_defaults',
                    'config_key' => 'default_error_handling',
                    'config_value' => 'stop',
                    'data_type' => 'string',
                    'description' => 'Default error handling strategy (stop, continue)',
                ],
                [
                    'section' => 'workflow_defaults',
                    'config_key' => 'auto_retry_enabled',
                    'config_value' => 'true',
                    'data_type' => 'boolean',
                    'description' => 'Enable automatic retry on failure',
                ],
                [
                    'section' => 'workflow_defaults',
                    'config_key' => 'max_retry_attempts',
                    'config_value' => '3',
                    'data_type' => 'number',
                    'description' => 'Maximum retry attempts',
                ],

                // AI Settings section
                [
                    'section' => 'ai_settings',
                    'config_key' => 'openai_api_key',
                    'config_value' => '',
                    'data_type' => 'string',
                    'description' => 'OpenAI API key',
                ],
                [
                    'section' => 'ai_settings',
                    'config_key' => 'default_model',
                    'config_value' => $this->resolveDefaultOllamaModel(),
                    'data_type' => 'string',
                    'description' => 'Default AI model',
                ],
                [
                    'section' => 'ai_settings',
                    'config_key' => 'max_tokens',
                    'config_value' => '2000',
                    'data_type' => 'number',
                    'description' => 'Maximum tokens per request',
                ],
                [
                    'section' => 'ai_settings',
                    'config_key' => 'temperature',
                    'config_value' => '0.7',
                    'data_type' => 'number',
                    'description' => 'AI temperature setting (0-1)',
                ],

                // Notifications section
                [
                    'section' => 'notifications',
                    'config_key' => 'email_enabled',
                    'config_value' => 'false',
                    'data_type' => 'boolean',
                    'description' => 'Enable email notifications',
                ],
                [
                    'section' => 'notifications',
                    'config_key' => 'email_on_failure',
                    'config_value' => 'true',
                    'data_type' => 'boolean',
                    'description' => 'Send email on workflow failure',
                ],
                [
                    'section' => 'notifications',
                    'config_key' => 'email_on_success',
                    'config_value' => 'false',
                    'data_type' => 'boolean',
                    'description' => 'Send email on workflow success',
                ],
                [
                    'section' => 'notifications',
                    'config_key' => 'notification_email',
                    'config_value' => '',
                    'data_type' => 'string',
                    'description' => 'Email address for notifications',
                ],
                [
                    'section' => 'notifications',
                    'config_key' => 'pushover_enabled',
                    'config_value' => 'true',
                    'data_type' => 'boolean',
                    'description' => 'Enable Pushover notifications',
                ],
                [
                    'section' => 'notifications',
                    'config_key' => 'pushover_user_key',
                    'config_value' => '',
                    'data_type' => 'string',
                    'description' => 'Pushover user key',
                ],
                [
                    'section' => 'notifications',
                    'config_key' => 'pushover_api_token',
                    'config_value' => '',
                    'data_type' => 'string',
                    'description' => 'Pushover API token',
                ],

                // Integration Settings section
                [
                    'section' => 'integrations',
                    'config_key' => 'weather_api_key',
                    'config_value' => '',
                    'data_type' => 'string',
                    'description' => 'WeatherAPI.com API key',
                ],
                [
                    'section' => 'integrations',
                    'config_key' => 'ollama_base_url',
                    'config_value' => config('services.ollama.api_url', 'http://127.0.0.1:11434'),
                    'data_type' => 'string',
                    'description' => 'Ollama API base URL',
                ],
                [
                    'section' => 'integrations',
                    'config_key' => 'ollama_model',
                    'config_value' => $this->resolveDefaultOllamaModel(),
                    'data_type' => 'string',
                    'description' => 'Default Ollama model',
                ],

                // Performance Settings section
                [
                    'section' => 'performance',
                    'config_key' => 'queue_workers',
                    'config_value' => '1',
                    'data_type' => 'number',
                    'description' => 'Target number of queue executors',
                ],
                [
                    'section' => 'performance',
                    'config_key' => 'cache_enabled',
                    'config_value' => 'true',
                    'data_type' => 'boolean',
                    'description' => 'Enable caching',
                ],
                [
                    'section' => 'performance',
                    'config_key' => 'cache_ttl',
                    'config_value' => '3600',
                    'data_type' => 'number',
                    'description' => 'Cache time-to-live in seconds',
                ],

                // Security Settings section
                [
                    'section' => 'security',
                    'config_key' => 'api_rate_limit',
                    'config_value' => '60',
                    'data_type' => 'number',
                    'description' => 'API requests per minute limit',
                ],
                [
                    'section' => 'security',
                    'config_key' => 'session_timeout',
                    'config_value' => '7200',
                    'data_type' => 'number',
                    'description' => 'Session timeout in seconds',
                ],
                [
                    'section' => 'security',
                    'config_key' => 'require_authentication',
                    'config_value' => 'false',
                    'data_type' => 'boolean',
                    'description' => 'Require authentication for API access',
                ],
            ];

            DB::beginTransaction();

            $inserted = 0;

            foreach ($defaults as $default) {
                // Check if already exists
                $existsResult = DB::select(
                    'SELECT COUNT(*) as cnt FROM system_configs WHERE config_key = ?',
                    [$default['config_key']]
                );
                $exists = $existsResult[0]->cnt > 0;

                if (! $exists) {
                    DB::insert(
                        'INSERT INTO system_configs (section, config_key, config_value, data_type, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$default['section'], $default['config_key'], $default['config_value'], $default['data_type'], $default['description'], now(), now()]
                    );
                    $inserted++;
                }
            }

            DB::commit();
            $this->systemConfigService->flushCache();

            Log::info('Default configuration initialized', [
                'action' => 'initialize_defaults',
                'operator_id' => $request->user()?->getAuthIdentifier(),
                'inserted' => $inserted,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Default configurations initialized successfully',
            ]);
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->logFailure('initialize_defaults', $e);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'INIT_FAILED', 'message' => 'Unable to initialize configuration.'],
            ], 500);
        }
    }

    /**
     * Parse value based on data type
     */
    private function parseValue($value, string $dataType): mixed
    {
        if ($value === null) {
            return null;
        }

        switch ($dataType) {
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'number':
                return is_numeric($value) ? (float) $value : $value;
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }

    /**
     * Format value for storage based on data type
     */
    private function formatValue($value, string $dataType): string
    {
        switch ($dataType) {
            case 'boolean':
                return $value ? 'true' : 'false';
            case 'number':
                return (string) $value;
            case 'json':
                return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
            default:
                return (string) $value;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function projectConfig(object $item): array
    {
        $projection = $this->secretService->project(
            $item->config_key,
            $this->parseValue($item->config_value, $item->data_type)
        );

        return [
            'id' => $item->id,
            'key' => $item->config_key,
            'value' => $projection['value'],
            'data_type' => $item->data_type,
            'description' => $item->description,
            'is_secret' => $projection['is_secret'],
            'is_configured' => $projection['is_configured'],
            'has_redacted_fields' => $projection['has_redacted_fields'],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logFailure(string $action, Throwable $exception, array $context = []): void
    {
        Log::error('Configuration operation failed', array_merge($context, [
            'action' => $action,
            'exception' => $exception::class,
            'code' => $exception->getCode(),
        ]));
    }

    private function resolveDefaultOllamaModel(): string
    {
        try {
            $profile = DB::selectOne(
                "SELECT model_name
                 FROM llm_model_profiles
                 WHERE enabled = 1 AND profile_name IN ('default', 'standard')
                 ORDER BY CASE profile_name WHEN 'default' THEN 0 ELSE 1 END
                 LIMIT 1"
            );

            if ($profile?->model_name) {
                return (string) $profile->model_name;
            }

            $instance = DB::selectOne(
                "SELECT config
                 FROM llm_instances
                 WHERE instance_type = 'ollama' AND is_active = 1
                 ORDER BY priority ASC, id ASC
                 LIMIT 1"
            );

            if ($instance) {
                $config = json_decode($instance->config ?? '{}', true);
                if (is_array($config)) {
                    return (string) ($config['models']['standard'] ?? $config['default_model'] ?? config('services.ollama.model') ?? '');
                }
            }
        } catch (Exception $e) {
            // Fall through to config bootstrap below.
        }

        return (string) (config('services.ollama.model') ?? '');
    }
}
