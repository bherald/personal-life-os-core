<?php

namespace App\Console\Commands;

use App\Services\NextcloudContactsService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Priority 4.6: Sync living genealogy persons to Nextcloud Contacts
 *
 * Syncs living family members from genealogy database to Nextcloud Contacts
 * for birthday reminders and family contact management.
 *
 * Usage:
 *   php artisan genealogy:sync-contacts --tree-id=4
 *   php artisan genealogy:sync-contacts --tree-id=4 --dry-run
 *   php artisan genealogy:sync-contacts --tree-id=4 --address-book="Family Tree"
 */
class SyncGenealogyContacts extends Command
{
    protected $signature = 'genealogy:sync-contacts
                            {--tree-id= : Tree ID to sync (required)}
                            {--address-book= : Nextcloud address book name (optional)}
                            {--dry-run : Preview changes without making them}
                            {--remove-deceased : Remove contacts for newly deceased persons}';

    protected $description = 'Sync living genealogy persons to Nextcloud Contacts for birthday reminders';

    private NextcloudContactsService $contactsService;

    private array $stats = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'removed' => 0,
        'errors' => 0,
    ];

    public function handle(): int
    {
        $treeId = $this->option('tree-id');
        if (! $treeId) {
            $this->error('--tree-id is required');

            return 1;
        }

        $addressBook = $this->option('address-book');
        $dryRun = $this->option('dry-run');
        $removeDeceased = $this->option('remove-deceased');

        $this->contactsService = new NextcloudContactsService;

        $this->info('Genealogy to Nextcloud Contacts Sync');
        $this->info('=====================================');
        $this->line("Tree ID: {$treeId}");
        $this->line('Address Book: '.($addressBook ?: '(default)'));
        $this->line('Dry Run: '.($dryRun ? 'Yes' : 'No'));
        $this->newLine();

        // Get tree info
        $tree = DB::selectOne('SELECT * FROM genealogy_trees WHERE id = ?', [$treeId]);
        if (! $tree) {
            $this->error("Tree ID {$treeId} not found");

            return 1;
        }

        $this->info("Tree: {$tree->name}");
        $this->newLine();

        // Get living persons with birth dates
        $living = $this->getLivingPersons($treeId);
        $this->info('Found '.count($living).' living persons with birth dates');
        $this->newLine();

        if (count($living) === 0) {
            $this->warn('No living persons with birth dates found to sync');

            return 0;
        }

        // Get existing family tree contacts from Nextcloud to detect updates vs creates
        $existingContacts = $this->getExistingFamilyTreeContacts($tree->name, $addressBook);
        $this->line('Found '.count($existingContacts).' existing Family Tree contacts in Nextcloud');
        $this->newLine();

        // Sync each living person
        $this->info('Syncing contacts...');
        $bar = $this->output->createProgressBar(count($living));

        foreach ($living as $person) {
            try {
                $this->syncPerson($person, $tree->name, $addressBook, $existingContacts, $dryRun);
            } catch (Exception $e) {
                $this->stats['errors']++;
                $this->newLine();
                $this->error("Error syncing {$this->formatPersonLabel($person)}: ".$e->getMessage());
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Always remove presumed deceased (over 105 years old) from contacts
        $this->info('Checking for presumed deceased (over '.self::MAX_LIVING_AGE.' years old) to remove...');
        $this->removePresumedDeceasedContacts($treeId, $existingContacts, $addressBook, $dryRun);

        // Remove contacts without birth dates (not useful for birthday reminders)
        $this->info('Checking for contacts without birth dates to remove...');
        $this->removeNoBirthdateContacts($treeId, $existingContacts, $addressBook, $dryRun);

        // Remove deceased persons from contacts if requested
        if ($removeDeceased) {
            $this->info('Checking for deceased persons to remove...');
            $this->removeDeceasedContacts($treeId, $tree->name, $existingContacts, $dryRun);
        }

        // Print summary
        $this->info('Sync Complete!');
        $this->table(
            ['Action', 'Count'],
            [
                ['Created', $this->stats['created']],
                ['Updated', $this->stats['updated']],
                ['Skipped', $this->stats['skipped']],
                ['Removed', $this->stats['removed']],
                ['Errors', $this->stats['errors']],
            ]
        );

        return $this->stats['errors'] > 0 ? 1 : 0;
    }

    // Maximum realistic age - persons older than this without death date are presumed deceased
    private const MAX_LIVING_AGE = 105;

    /**
     * Get living persons with birth dates from genealogy database
     * Handles GEDCOM date formats (e.g., "26 AUG 1965", "1965", "ABT 1965")
     * Excludes persons over MAX_LIVING_AGE (presumed deceased with missing death date)
     * Includes married surname for currently married persons
     */
    private function getLivingPersons(int $treeId): array
    {
        $cutoffYear = (int) date('Y') - self::MAX_LIVING_AGE;

        return DB::select("
            SELECT
                p.id,
                p.given_name,
                p.surname as maiden_name,
                p.birth_date,
                p.birth_place,
                p.sex,
                p.primary_photo_id,
                pm.nextcloud_path as photo_path,
                CAST(REGEXP_SUBSTR(p.birth_date, '[0-9]{4}') AS UNSIGNED) as birth_year,
                -- Get married surname via subquery (most recent current marriage)
                (
                    SELECT spouse.surname
                    FROM genealogy_families f
                    INNER JOIN genealogy_persons spouse ON f.husband_id = spouse.id
                    WHERE f.wife_id = p.id
                      AND f.tree_id = p.tree_id
                      AND p.sex = 'F'
                      AND (f.divorce_date IS NULL OR f.divorce_date = '')
                      AND (spouse.death_date IS NULL OR spouse.death_date = '')
                    ORDER BY f.id DESC
                    LIMIT 1
                ) as married_surname
            FROM genealogy_persons p
            LEFT JOIN genealogy_media pm ON p.primary_photo_id = pm.id
            WHERE p.tree_id = ?
              AND (p.death_date IS NULL OR p.death_date = '')
              AND p.birth_date IS NOT NULL
              AND p.birth_date != ''
              AND (
                  -- GEDCOM format: extract year (e.g., '26 AUG 1965')
                  -- Must be born after cutoff year (not older than MAX_LIVING_AGE)
                  CAST(REGEXP_SUBSTR(p.birth_date, '[0-9]{4}') AS UNSIGNED) > ?
              )
            ORDER BY p.surname, p.given_name
        ", [$treeId, $cutoffYear]);
    }

    /**
     * Get existing Family Tree contacts from Nextcloud
     */
    private function getExistingFamilyTreeContacts(string $treeName, ?string $addressBook): array
    {
        try {
            $contacts = $this->contactsService->getContacts($addressBook, 1000);
            $familyContacts = [];

            $groupName = "Family Tree - {$treeName}";

            foreach ($contacts as $contact) {
                // Check if contact belongs to this family tree group
                // Look in the raw vCard data or parsed groups
                if (isset($contact['note']) && str_contains($contact['note'], 'Genealogy Person ID:')) {
                    // Extract person ID from note
                    if (preg_match('/Genealogy Person ID: (\d+)/', $contact['note'], $matches)) {
                        $familyContacts[$matches[1]] = $contact;
                    }
                }
            }

            return $familyContacts;

        } catch (Exception $e) {
            $this->warn('Could not fetch existing contacts: '.$e->getMessage());

            return [];
        }
    }

    private function formatPersonLabel(object $person): string
    {
        $surname = '';

        foreach (['surname', 'married_surname', 'maiden_name'] as $field) {
            if (property_exists($person, $field) && ! empty($person->{$field})) {
                $surname = (string) $person->{$field};
                break;
            }
        }

        return trim(($person->given_name ?? 'Unknown').' '.$surname);
    }

    /**
     * Sync a single person to Nextcloud Contacts
     */
    private function syncPerson(
        object $person,
        string $treeName,
        ?string $addressBook,
        array $existingContacts,
        bool $dryRun
    ): void {
        $personId = (string) $person->id;
        $groupName = "Family Tree - {$treeName}";

        // Determine surname: use married name if married, include maiden name
        $maidenName = $person->maiden_name;
        $marriedSurname = $person->married_surname ?? null;

        if ($marriedSurname && $marriedSurname !== $maidenName) {
            // Married: use "MarriedName (née MaidenName)" format
            $displaySurname = $marriedSurname;
            $fullName = trim($person->given_name.' '.$marriedSurname.' (née '.$maidenName.')');
        } else {
            // Not married or male: use maiden/birth name
            $displaySurname = $maidenName;
            $fullName = trim($person->given_name.' '.$maidenName);
        }

        // Build contact data
        $contactData = [
            'givenName' => $person->given_name,
            'familyName' => $displaySurname,
            'name' => $fullName,
            'birthday' => $this->normalizeBirthDate($person->birth_date),
            'groups' => [$groupName],
            'note' => "Genealogy Person ID: {$personId}\nBirth Place: ".($person->birth_place ?? 'Unknown').
                      ($marriedSurname && $marriedSurname !== $maidenName ? "\nMaiden Name: {$maidenName}" : ''),
        ];

        // Check if contact already exists
        $existingContact = $existingContacts[$personId] ?? null;

        if ($existingContact) {
            // Update existing contact
            $uid = $existingContact['uid'] ?? $existingContact['vcardUid'] ?? null;

            if ($dryRun) {
                $this->line("  [DRY RUN] Would update: {$fullName}");
                $this->stats['updated']++;
            } else {
                $this->contactsService->createOrUpdateContact($contactData, $addressBook, $uid);
                $this->stats['updated']++;
            }
        } else {
            // Create new contact
            // Generate a stable UID based on person ID
            $uid = 'genealogy-person-'.$personId;

            if ($dryRun) {
                $this->line("  [DRY RUN] Would create: {$fullName} (born {$person->birth_date})");
                $this->stats['created']++;
            } else {
                $this->contactsService->createOrUpdateContact($contactData, $addressBook, $uid);
                $this->stats['created']++;
            }
        }
    }

    /**
     * Normalize birth date to YYYY-MM-DD format
     * Handles GEDCOM date formats including:
     * - DD MMM YYYY (e.g., "26 AUG 1965")
     * - YYYY (e.g., "1965")
     * - ABT YYYY, BEF YYYY, AFT YYYY, CAL YYYY, EST YYYY (approximate dates)
     * - ABT DD MMM YYYY (approximate with full date)
     */
    private function normalizeBirthDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

        // Already in YYYY-MM-DD format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        // Strip GEDCOM date qualifiers (ABT, BEF, AFT, CAL, EST, etc.)
        $cleanDate = preg_replace('/^(ABT|BEF|AFT|CAL|EST|FROM|TO|BET|AND)\s+/i', '', trim($date));

        // Year only - use Jan 1
        if (preg_match('/^\d{4}$/', $cleanDate)) {
            return $cleanDate.'-01-01';
        }

        // GEDCOM format: DD MMM YYYY or similar
        try {
            $parsed = date_parse($cleanDate);
            if ($parsed['year'] && $parsed['month'] && $parsed['day']) {
                return sprintf('%04d-%02d-%02d', $parsed['year'], $parsed['month'], $parsed['day']);
            }
            if ($parsed['year']) {
                return sprintf('%04d-01-01', $parsed['year']);
            }
        } catch (Exception $e) {
            // Ignore parse errors
        }

        return null;
    }

    /**
     * Remove contacts for deceased persons
     */
    private function removeDeceasedContacts(
        int $treeId,
        string $treeName,
        array $existingContacts,
        bool $dryRun
    ): void {
        // Get recently deceased persons (have death_date and were in contacts)
        $deceased = DB::select("
            SELECT id, given_name, surname, death_date
            FROM genealogy_persons
            WHERE tree_id = ?
              AND death_date IS NOT NULL
              AND death_date != ''
        ", [$treeId]);

        foreach ($deceased as $person) {
            $personId = (string) $person->id;

            if (isset($existingContacts[$personId])) {
                $fullName = $this->formatPersonLabel($person);
                $uid = $existingContacts[$personId]['uid'] ?? $existingContacts[$personId]['vcardUid'] ?? null;

                if ($uid) {
                    if ($dryRun) {
                        $this->line("  [DRY RUN] Would remove deceased: {$fullName} (died {$person->death_date})");
                        $this->stats['removed']++;
                    } else {
                        if ($this->contactsService->deleteContact($uid)) {
                            $this->line("  Removed deceased: {$fullName}");
                            $this->stats['removed']++;
                        }
                    }
                }
            }
        }
    }

    /**
     * Remove contacts for persons without birth dates (not useful for birthday reminders)
     */
    private function removeNoBirthdateContacts(
        int $treeId,
        array $existingContacts,
        ?string $addressBook,
        bool $dryRun
    ): void {
        // Get persons without valid birth dates
        $noBirthdate = DB::select("
            SELECT id, given_name, surname
            FROM genealogy_persons
            WHERE tree_id = ?
              AND (birth_date IS NULL OR birth_date = '')
        ", [$treeId]);

        foreach ($noBirthdate as $person) {
            $personId = (string) $person->id;

            if (isset($existingContacts[$personId])) {
                $fullName = $this->formatPersonLabel($person);
                $uid = $existingContacts[$personId]['uid'] ?? $existingContacts[$personId]['vcardUid'] ?? null;

                if ($uid) {
                    if ($dryRun) {
                        $this->line("  [DRY RUN] Would remove (no birthdate): {$fullName}");
                        $this->stats['removed']++;
                    } else {
                        if ($this->contactsService->deleteContact($uid, $addressBook)) {
                            $this->line("  Removed (no birthdate): {$fullName}");
                            $this->stats['removed']++;
                        }
                    }
                }
            }
        }
    }

    /**
     * Remove contacts for persons presumed deceased (over MAX_LIVING_AGE with no death date)
     * These were synced previously but now exceed the age threshold
     */
    private function removePresumedDeceasedContacts(
        int $treeId,
        array $existingContacts,
        ?string $addressBook,
        bool $dryRun
    ): void {
        $cutoffYear = (int) date('Y') - self::MAX_LIVING_AGE;

        // Get persons who are too old to be living (no death date but over MAX_LIVING_AGE)
        $presumedDeceased = DB::select("
            SELECT
                id,
                given_name,
                surname,
                birth_date,
                CAST(REGEXP_SUBSTR(birth_date, '[0-9]{4}') AS UNSIGNED) as birth_year
            FROM genealogy_persons
            WHERE tree_id = ?
              AND (death_date IS NULL OR death_date = '')
              AND birth_date IS NOT NULL
              AND birth_date != ''
              AND CAST(REGEXP_SUBSTR(birth_date, '[0-9]{4}') AS UNSIGNED) <= ?
        ", [$treeId, $cutoffYear]);

        foreach ($presumedDeceased as $person) {
            $personId = (string) $person->id;

            if (isset($existingContacts[$personId])) {
                $fullName = $this->formatPersonLabel($person);
                $age = (int) date('Y') - $person->birth_year;
                $uid = $existingContacts[$personId]['uid'] ?? $existingContacts[$personId]['vcardUid'] ?? null;

                if ($uid) {
                    if ($dryRun) {
                        $this->line("  [DRY RUN] Would remove presumed deceased: {$fullName} (born {$person->birth_year}, would be {$age})");
                        $this->stats['removed']++;
                    } else {
                        if ($this->contactsService->deleteContact($uid, $addressBook)) {
                            $this->line("  Removed presumed deceased: {$fullName} (born {$person->birth_year}, would be {$age})");
                            $this->stats['removed']++;
                        }
                    }
                }
            }
        }
    }
}
