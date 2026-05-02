<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // N143: DB-driven external service registry for proposal approval
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_external_service_registry (
                id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
                service_type  VARCHAR(50)      NOT NULL,
                field_alias   VARCHAR(100)     NULL,
                url_pattern   VARCHAR(255)     NULL,
                display_name  VARCHAR(100)     NOT NULL,
                is_active     TINYINT(1)       NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                UNIQUE KEY uk_field_alias (field_alias),
                KEY idx_service_type (service_type)
            ) ENGINE=InnoDB
        ");

        // Seed registry rows
        $services = [
            ['wikitree', 'wikitree_id', '%wikitree.com%', 'WikiTree'],
            ['findagrave', 'findagrave_id', '%findagrave.com%', 'Find A Grave'],
            ['ancestry', 'ancestry_id', '%ancestry.com%', 'Ancestry'],
            ['familysearch', 'familysearch_id', '%familysearch.org%', 'FamilySearch'],
            ['geni', 'geni_id', '%geni.com%', 'Geni'],
            ['myheritage', 'myheritage_id', '%myheritage.com%', 'MyHeritage'],
            ['geneanet', 'geneanet_id', '%geneanet.org%', 'Geneanet'],
            ['findmypast', 'findmypast_id', '%findmypast.com%', 'FindMyPast'],
            ['nara', 'nara_id', '%catalog.archives.gov%', 'National Archives (NARA)'],
        ];

        foreach ($services as [$type, $alias, $pattern, $name]) {
            DB::insert(
                "INSERT IGNORE INTO genealogy_external_service_registry (service_type, field_alias, url_pattern, display_name) VALUES (?, ?, ?, ?)",
                [$type, $alias, $pattern, $name]
            );
        }

        // N144: Add 'nara' to genealogy_external_records service_type enum
        DB::statement("
            ALTER TABLE genealogy_external_records
            MODIFY COLUMN service_type ENUM('familysearch','ancestry','findmypast','myheritage','geneanet','wikitree','findagrave','nara')
        ");

        // Also add 'nara' to genealogy_external_connections and genealogy_person_external_links
        DB::statement("
            ALTER TABLE genealogy_external_connections
            MODIFY COLUMN service_type ENUM('familysearch','ancestry','findmypast','myheritage','geneanet','wikitree','findagrave','nara')
        ");

        DB::statement("
            ALTER TABLE genealogy_person_external_links
            MODIFY COLUMN service_type ENUM('familysearch','ancestry','findmypast','myheritage','geneanet','wikitree','findagrave','nara')
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS genealogy_external_service_registry");
    }
};
