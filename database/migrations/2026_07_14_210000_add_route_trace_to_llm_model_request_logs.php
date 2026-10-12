<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('llm_model_request_logs')) {
            return;
        }

        Schema::table('llm_model_request_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('llm_model_request_logs', 'route_trace_id')) {
                $table->char('route_trace_id', 32)->nullable()->after('request_kind');
            }
            if (! Schema::hasColumn('llm_model_request_logs', 'route_attempt_index')) {
                $table->unsignedSmallInteger('route_attempt_index')->nullable()->after('route_trace_id');
            }
        });

        if (Schema::hasColumns('llm_model_request_logs', ['route_trace_id', 'route_attempt_index'])) {
            Schema::table('llm_model_request_logs', function (Blueprint $table): void {
                $table->index(
                    ['route_trace_id', 'route_attempt_index'],
                    'llm_model_req_route_trace_attempt_idx'
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_model_request_logs')) {
            return;
        }

        if (Schema::hasColumns('llm_model_request_logs', ['route_trace_id', 'route_attempt_index'])) {
            Schema::table('llm_model_request_logs', function (Blueprint $table): void {
                $table->dropIndex('llm_model_req_route_trace_attempt_idx');
            });
        }

        Schema::table('llm_model_request_logs', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['route_trace_id', 'route_attempt_index'],
                static fn (string $column): bool => Schema::hasColumn('llm_model_request_logs', $column)
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
