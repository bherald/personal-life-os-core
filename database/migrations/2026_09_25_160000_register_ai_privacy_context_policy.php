<?php

use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $query = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'service_runtime_policy');
            $row = (clone $query)->lockForUpdate()->first();
            $policy = $row === null ? [] : json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($policy)) {
                throw new RuntimeException('AI runtime policy must be a JSON object; existing settings were not changed.');
            }
            if (array_key_exists('sensitive_context_terms', $policy)) {
                return;
            }

            // Operator-approved initial categories. Future choices live in this
            // table field; genealogy alone does not classify a request as sensitive.
            $policy['sensitive_context_terms'] = [
                'rag', 'email', 'mail', 'joplin', 'file', 'document',
                'attachment', 'private', 'personal', 'health', 'finance',
            ];
            $values = ['config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'data_type' => 'json', 'updated_at' => now()];
            if ($row === null) {
                DB::table('system_configs')->insert($values + ['section' => 'ai', 'config_key' => 'service_runtime_policy',
                    'description' => 'Operator-owned AI runtime policy, including automatic sensitive-context categories.',
                    'created_at' => now()]);
            } else {
                $query->update($values);
            }
        });
        app(SystemConfigService::class)->forget('ai.service_runtime_policy');
    }

    public function down(): void
    {
        // Retain operator-owned classifications and unrelated runtime settings.
    }
};
