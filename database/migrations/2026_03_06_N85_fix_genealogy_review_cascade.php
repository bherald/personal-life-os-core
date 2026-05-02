<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * N85: Wire genealogy_finding rejection to cascade-reject pending proposed changes.
     *
     * Bug: Rejecting a genealogy_finding in the UI only marked the queue item as rejected.
     * Any pending genealogy_proposed_changes for the same person remained orphaned as pending.
     *
     * Fix: Add service_class + reject_method to the genealogy_finding registry entry so
     * PersonService::rejectGenealogyFinding() handles rejection with cascade.
     */
    public function up(): void
    {
        DB::statement("
            UPDATE review_type_registry
            SET service_class = 'App\\\\Services\\\\Genealogy\\\\PersonService',
                reject_method = 'rejectGenealogyFinding',
                updated_at = NOW()
            WHERE name = 'genealogy_finding'
        ");
    }

    public function down(): void
    {
        DB::statement("
            UPDATE review_type_registry
            SET service_class = NULL,
                reject_method = NULL,
                updated_at = NOW()
            WHERE name = 'genealogy_finding'
        ");
    }
};
