<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'queue_wait_ms',
        'time_to_first_content_ms',
        'response_bytes',
        'transport_chunk_count',
        'finish_reason',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('llm_model_request_logs')) {
            return;
        }

        Schema::table('llm_model_request_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('llm_model_request_logs', 'queue_wait_ms')) {
                $table->unsignedBigInteger('queue_wait_ms')->nullable()->after('reasoning_output_tokens');
            }
            if (! Schema::hasColumn('llm_model_request_logs', 'time_to_first_content_ms')) {
                $table->unsignedBigInteger('time_to_first_content_ms')->nullable()->after('queue_wait_ms');
            }
            if (! Schema::hasColumn('llm_model_request_logs', 'response_bytes')) {
                $table->unsignedBigInteger('response_bytes')->nullable()->after('time_to_first_content_ms');
            }
            if (! Schema::hasColumn('llm_model_request_logs', 'transport_chunk_count')) {
                $table->unsignedInteger('transport_chunk_count')->nullable()->after('response_bytes');
            }
            if (! Schema::hasColumn('llm_model_request_logs', 'finish_reason')) {
                $table->string('finish_reason', 64)->nullable()->after('transport_chunk_count');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_model_request_logs')) {
            return;
        }

        Schema::table('llm_model_request_logs', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                self::COLUMNS,
                static fn (string $column): bool => Schema::hasColumn('llm_model_request_logs', $column)
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
