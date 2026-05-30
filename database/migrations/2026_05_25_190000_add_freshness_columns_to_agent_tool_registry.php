<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }

        $this->addColumnIfMissing('max_result_bytes', function (Blueprint $table): void {
            $table->unsignedInteger('max_result_bytes')->nullable()->after('max_tokens_per_call');
        });
        $this->addColumnIfMissing('availability_status', function (Blueprint $table): void {
            $table->string('availability_status', 32)->default('unknown')->after('enabled');
        });
        $this->addColumnIfMissing('last_checked_at', function (Blueprint $table): void {
            $table->timestamp('last_checked_at')->nullable()->after('availability_status');
        });
        $this->addColumnIfMissing('last_error', function (Blueprint $table): void {
            $table->text('last_error')->nullable()->after('last_checked_at');
        });
        $this->addColumnIfMissing('schema_generation', function (Blueprint $table): void {
            $table->unsignedInteger('schema_generation')->default(1)->after('last_error');
        });
        $this->addColumnIfMissing('privacy_class', function (Blueprint $table): void {
            $table->string('privacy_class', 50)->default('unspecified')->after('schema_generation');
        });
        $this->addColumnIfMissing('allows_private_data', function (Blueprint $table): void {
            $table->boolean('allows_private_data')->default(false)->after('privacy_class');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }

        foreach ([
            'allows_private_data',
            'privacy_class',
            'schema_generation',
            'last_error',
            'last_checked_at',
            'availability_status',
            'max_result_bytes',
        ] as $column) {
            if (Schema::hasColumn('agent_tool_registry', $column)) {
                Schema::table('agent_tool_registry', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function addColumnIfMissing(string $column, callable $callback): void
    {
        if (Schema::hasColumn('agent_tool_registry', $column)) {
            return;
        }

        Schema::table('agent_tool_registry', $callback);
    }
};
