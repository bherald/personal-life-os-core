<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::insert("
                INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description, permissions, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'config')
            ", [
                'code_quality_check',
                'CodeQualityService',
                'checkPatternCompliance',
                'Grep-based static analysis checking PLOS-specific rules: Eloquent/QueryBuilder violations, SQL injection risks, wrong DB connections, constructor injection, hardcoded credentials. No AI dependency — pure pattern matching.',
                json_encode([
                    'path' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional path to scan (default: all app/ directories)',
                        'default' => null,
                    ],
                ]),
                'Array with violations (file, line, rule, severity, snippet per finding), summary string, files_scanned count, and clean_files count',
                json_encode(['system:read']),
            ]);
        } catch (\Exception $e) {
            // Skip if already exists (idempotent)
            if (!str_contains($e->getMessage(), 'Duplicate entry')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name = 'code_quality_check'");
    }
};
