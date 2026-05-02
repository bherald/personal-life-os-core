<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pipeline_metrics_snapshots')) {
            return;
        }

        $after = 'delta_from_prev';
        foreach ($this->kgColumns() as $column) {
            if (! Schema::hasColumn('pipeline_metrics_snapshots', $column)) {
                Schema::table('pipeline_metrics_snapshots', function (Blueprint $table) use ($column, $after): void {
                    $table->unsignedInteger($column)->default(0)->after($after);
                });
            }

            $after = $column;
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pipeline_metrics_snapshots')) {
            return;
        }

        Schema::table('pipeline_metrics_snapshots', function (Blueprint $table): void {
            foreach ($this->kgColumns() as $column) {
                if (Schema::hasColumn('pipeline_metrics_snapshots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * @return list<string>
     */
    private function kgColumns(): array
    {
        return [
            'kg_triples_total',
            'kg_triples_active',
            'kg_triples_missing_source_document',
            'kg_triples_orphan_source_document',
            'kg_active_missing_either_entity',
            'kg_triples_stale_source_hash',
            'kg_extracted_documents_without_triples',
            'kg_pending_fresh_documents',
            'kg_stale_documents',
            'kg_hyperedges_total',
            'kg_hyperedges_orphan_source_document',
        ];
    }
};
