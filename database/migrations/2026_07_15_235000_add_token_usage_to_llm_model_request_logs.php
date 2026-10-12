<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'input_tokens',
        'cached_input_tokens',
        'cache_write_input_tokens',
        'output_tokens',
        'reasoning_output_tokens',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('llm_model_request_logs')) {
            return;
        }

        Schema::table('llm_model_request_logs', function (Blueprint $table): void {
            $after = 'tokens_per_second';
            foreach (self::COLUMNS as $column) {
                if (! Schema::hasColumn('llm_model_request_logs', $column)) {
                    $table->unsignedBigInteger($column)->nullable()->after($after);
                }
                $after = $column;
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
