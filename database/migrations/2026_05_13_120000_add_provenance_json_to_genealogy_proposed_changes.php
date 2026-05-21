<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('genealogy_proposed_changes')
            || Schema::hasColumn('genealogy_proposed_changes', 'provenance_json')) {
            return;
        }

        Schema::table('genealogy_proposed_changes', function (Blueprint $table): void {
            $table->json('provenance_json')->nullable()->after('evidence_summary');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('genealogy_proposed_changes')
            || ! Schema::hasColumn('genealogy_proposed_changes', 'provenance_json')) {
            return;
        }

        Schema::table('genealogy_proposed_changes', function (Blueprint $table): void {
            $table->dropColumn('provenance_json');
        });
    }
};
