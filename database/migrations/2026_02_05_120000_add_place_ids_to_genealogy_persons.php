<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add place_id columns to genealogy_persons table for linking
     * birth_place, death_place, burial_place to normalized place records.
     */
    public function up(): void
    {
        // Add place_id columns to genealogy_persons
        DB::statement("
            ALTER TABLE genealogy_persons
            ADD COLUMN birth_place_id INT UNSIGNED NULL AFTER birth_lon,
            ADD COLUMN death_place_id INT UNSIGNED NULL AFTER death_lon,
            ADD COLUMN burial_place_id INT UNSIGNED NULL AFTER burial_lon,
            ADD INDEX idx_birth_place_id (birth_place_id),
            ADD INDEX idx_death_place_id (death_place_id),
            ADD INDEX idx_burial_place_id (burial_place_id),
            ADD CONSTRAINT fk_person_birth_place FOREIGN KEY (birth_place_id) REFERENCES genealogy_places(id) ON DELETE SET NULL,
            ADD CONSTRAINT fk_person_death_place FOREIGN KEY (death_place_id) REFERENCES genealogy_places(id) ON DELETE SET NULL,
            ADD CONSTRAINT fk_person_burial_place FOREIGN KEY (burial_place_id) REFERENCES genealogy_places(id) ON DELETE SET NULL
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE genealogy_persons DROP FOREIGN KEY fk_person_birth_place");
        DB::statement("ALTER TABLE genealogy_persons DROP FOREIGN KEY fk_person_death_place");
        DB::statement("ALTER TABLE genealogy_persons DROP FOREIGN KEY fk_person_burial_place");
        DB::statement("ALTER TABLE genealogy_persons DROP COLUMN birth_place_id, DROP COLUMN death_place_id, DROP COLUMN burial_place_id");
    }
};
