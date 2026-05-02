<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create table to link approved research facts to genealogy persons.
     * This enables the "Apply to Tree" feature when approving genealogy-related facts.
     */
    public function up(): void
    {
        Schema::create('genealogy_research_fact_links', function (Blueprint $table) {
            $table->id();

            // UUID from research_facts table (PostgreSQL pgsql_rag database)
            $table->uuid('research_fact_id');

            // Foreign key to genealogy_persons table
            $table->unsignedBigInteger('genealogy_person_id');

            // Type of fact being linked (birth_date, death_date, marriage, residence, occupation, etc.)
            $table->string('fact_type', 50);

            // The actual value that was applied to the person record
            $table->text('applied_value')->nullable();

            // When this link was created
            $table->timestamp('applied_at')->useCurrent();

            // Optional notes about this link
            $table->text('notes')->nullable();

            // Indexes for efficient queries
            $table->index('genealogy_person_id', 'idx_grfl_person');
            $table->index('research_fact_id', 'idx_grfl_fact');
            $table->index(['genealogy_person_id', 'fact_type'], 'idx_grfl_person_type');

            // Prevent duplicate links
            $table->unique(['research_fact_id', 'genealogy_person_id', 'fact_type'], 'uniq_grfl_fact_person_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('genealogy_research_fact_links');
    }
};
