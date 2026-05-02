<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "UPDATE llm_instances
             SET routability = 'allowed',
                 gpu_target = 'pascal_6gb',
                 host_affinity = 'local-primary',
                 compat_runtime_family = 'ollama_0_17',
                 compat_backend = 'llama_cpp',
                 compat_status = 'authoritative',
                 updated_at = NOW()
             WHERE instance_type = 'ollama'
               AND compat_status = 'provisional'
               AND base_url LIKE '%127.0.0.1%'"
        );

        $secondaryHost = trim((string) env('PLOS_SECONDARY_OLLAMA_HOST', ''));
        if ($secondaryHost !== '') {
            DB::update(
                "UPDATE llm_instances
                 SET routability = 'allowed',
                     gpu_target = 'ada_12gb',
                     host_affinity = 'local-secondary',
                     compat_runtime_family = 'ollama_0_18+',
                     compat_backend = 'llama_cpp',
                     compat_status = 'authoritative',
                     updated_at = NOW()
                 WHERE instance_type = 'ollama'
                   AND compat_status = 'provisional'
                   AND base_url LIKE ?",
                ['%'.$secondaryHost.'%']
            );
        }
    }

    public function down(): void {}
};
