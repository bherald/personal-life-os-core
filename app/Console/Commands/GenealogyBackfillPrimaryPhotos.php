<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenealogyBackfillPrimaryPhotos extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'genealogy:backfill-primary-photos
                            {--tree= : Optional tree ID to limit to a specific tree}
                            {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Backfill primary photos for persons who have linked media but no primary photo set';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $treeId = $this->option('tree');
        $dryRun = $this->option('dry-run');

        $this->info('Genealogy Primary Photo Backfill');
        $this->info('================================');

        if ($dryRun) {
            $this->warn('DRY RUN - No changes will be made');
        }

        // Find persons without primary_photo_id who have linked image media
        $query = "
            SELECT p.id as person_id, p.given_name, p.surname,
                   MIN(pm.media_id) as first_media_id
            FROM genealogy_persons p
            JOIN genealogy_person_media pm ON pm.person_id = p.id
            JOIN genealogy_media m ON m.id = pm.media_id
            WHERE p.primary_photo_id IS NULL
              AND m.media_type = 'photo'
        ";

        if ($treeId) {
            $query .= " AND p.tree_id = ?";
        }

        $query .= " GROUP BY p.id, p.given_name, p.surname";

        $params = $treeId ? [$treeId] : [];
        $personsToUpdate = DB::select($query, $params);

        $count = count($personsToUpdate);
        $this->info("Found {$count} persons without primary photos who have linked image media.");

        if ($count === 0) {
            $this->info('Nothing to update.');
            return Command::SUCCESS;
        }

        $updated = 0;
        $junctionUpdated = 0;

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach ($personsToUpdate as $person) {
            if (!$dryRun) {
                // Update person's primary_photo_id
                DB::update(
                    "UPDATE genealogy_persons SET primary_photo_id = ? WHERE id = ?",
                    [$person->first_media_id, $person->person_id]
                );

                // Also set is_primary = 1 in junction table
                DB::update(
                    "UPDATE genealogy_person_media SET is_primary = 1 WHERE person_id = ? AND media_id = ?",
                    [$person->person_id, $person->first_media_id]
                );

                $junctionUpdated++;
            }

            $updated++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("Would update {$updated} persons with primary photos.");
        } else {
            $this->info("Updated {$updated} persons with primary photos.");
            $this->info("Updated {$junctionUpdated} junction table records with is_primary = 1.");
        }

        // Show statistics
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_persons,
                SUM(CASE WHEN primary_photo_id IS NOT NULL THEN 1 ELSE 0 END) as with_primary_photo
            FROM genealogy_persons
        ");

        $this->newLine();
        $this->info('Statistics:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Persons', $stats->total_persons],
                ['With Primary Photo', $stats->with_primary_photo],
                ['Coverage', round(($stats->with_primary_photo / max(1, $stats->total_persons)) * 100, 1) . '%'],
            ]
        );

        return Command::SUCCESS;
    }
}
