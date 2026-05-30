<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('genealogy_external_service_registry')) {
            return;
        }

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
            DB::table('genealogy_external_service_registry')->updateOrInsert(
                ['field_alias' => $alias],
                [
                    'service_type' => $type,
                    'url_pattern' => $pattern,
                    'display_name' => $name,
                    'is_active' => 1,
                ]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('genealogy_external_service_registry')) {
            return;
        }

        DB::table('genealogy_external_service_registry')
            ->whereIn('field_alias', [
                'wikitree_id',
                'findagrave_id',
                'ancestry_id',
                'familysearch_id',
                'geni_id',
                'myheritage_id',
                'geneanet_id',
                'findmypast_id',
                'nara_id',
            ])
            ->delete();
    }
};
