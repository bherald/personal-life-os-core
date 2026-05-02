<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add instance_id to ollama_models for multi-instance support
     *
     * After this migration:
     * - Each model is tracked per-instance
     * - Same model on 2 Ollama instances = 2 rows
     * - Existing models assigned to primary instance
     */
    public function up(): void
    {
        // Add instance_id column
        Schema::table('ollama_models', function (Blueprint $table) {
            $table->unsignedBigInteger('instance_id')->nullable()->after('id')
                  ->comment('FK to llm_instances - which Ollama instance has this model');

            // Drop unique constraint on model_name alone
            $table->dropUnique('ollama_models_model_name_unique');
        });

        // Assign existing models to primary instance
        $primaryInstance = DB::table('llm_instances')
            ->where('instance_id', 'ollama_primary')
            ->first();

        if ($primaryInstance) {
            DB::table('ollama_models')
                ->whereNull('instance_id')
                ->update(['instance_id' => $primaryInstance->id]);
        }

        // Add new composite unique constraint and foreign key
        Schema::table('ollama_models', function (Blueprint $table) {
            // Unique per instance (same model can exist on multiple instances)
            $table->unique(['instance_id', 'model_name'], 'ollama_models_instance_model_unique');

            // Foreign key
            $table->foreign('instance_id')
                  ->references('id')
                  ->on('llm_instances')
                  ->onDelete('cascade');

            // Index for lookups
            $table->index('instance_id');
        });
    }

    public function down(): void
    {
        Schema::table('ollama_models', function (Blueprint $table) {
            // Remove foreign key and indexes
            $table->dropForeign(['instance_id']);
            $table->dropUnique('ollama_models_instance_model_unique');
            $table->dropIndex(['instance_id']);

            // Restore original unique constraint
            $table->unique('model_name', 'ollama_models_model_name_unique');

            // Remove column
            $table->dropColumn('instance_id');
        });
    }
};
