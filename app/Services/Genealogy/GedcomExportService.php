<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GEDCOM Export Service
 *
 * Generates GEDCOM 5.5.1 compatible files from the genealogy database.
 * Implements Priority 3.2 from genealogy-module-review.md.
 *
 * GEDCOM 5.5.1 Structure:
 * - HEAD (header)
 * - SUBM (submitter)
 * - INDI (individuals)
 * - FAM (families)
 * - OBJE (media objects) - optional
 * - SOUR (sources)
 * - REPO (repositories)
 * - TRLR (trailer)
 *
 * @see https://www.gedcom.org/gedcom.html
 * @see /docs/genealogy-module-review.md Priority 3.2
 */
class GedcomExportService
{
    protected PrivacyService $privacyService;

    /**
     * GEDCOM line length limit (255 characters)
     */
    protected const MAX_LINE_LENGTH = 255;

    /**
     * GEDCOM character encoding
     */
    protected const ENCODING = 'UTF-8';

    public function __construct(PrivacyService $privacyService)
    {
        $this->privacyService = $privacyService;
    }

    /**
     * Export a tree to GEDCOM format
     *
     * @param  int|null  $userId  User performing export (for privacy filtering)
     * @param  array  $options  Export options
     * @return string GEDCOM content
     */
    public function exportTree(int $treeId, ?int $userId = null, array $options = []): string
    {
        $options = array_merge([
            'include_living' => false, // Exclude living persons by default
            'include_media' => true,
            'include_sources' => true,
            'include_notes' => true,
            'submitter_name' => 'PLOS Genealogy Export',
            'submitter_address' => null,
            'gedcom_version' => '5.5.1', // GEN-4: '5.5.1' or '7.0'
        ], $options);

        $tree = DB::selectOne('SELECT * FROM genealogy_trees WHERE id = ?', [$treeId]);
        if (! $tree) {
            throw new \InvalidArgumentException("Tree not found: {$treeId}");
        }

        $lines = [];

        // Build GEDCOM structure
        $lines = array_merge($lines, $this->buildHeader($tree, $options));
        $lines = array_merge($lines, $this->buildSubmitter($options));
        $lines = array_merge($lines, $this->buildIndividuals($treeId, $userId, $options));
        $lines = array_merge($lines, $this->buildFamilies($treeId));

        if ($options['include_sources']) {
            $lines = array_merge($lines, $this->buildSources($treeId));
            $lines = array_merge($lines, $this->buildRepositories($treeId));
        }

        if ($options['include_media']) {
            $lines = array_merge($lines, $this->buildMediaObjects($treeId));
        }

        $lines[] = '0 TRLR';

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * Build GEDCOM header section
     */
    protected function buildHeader(object $tree, array $options): array
    {
        $now = date('d M Y');
        $time = date('H:i:s');

        $version = $options['gedcom_version'] ?? '5.5.1';

        if ($version === '7.0') {
            // GEN-4: GEDCOM 7.0 header per FamilySearch GEDCOM 7.0.x spec
            return [
                '0 HEAD',
                '1 GEDC',
                '2 VERS 7.0',
                '1 SOUR PLOS',
                '2 VERS 1.0',
                '2 NAME PLOS Genealogy',
                "1 DATE {$now}",
                "2 TIME {$time}",
                '1 SUBM @SUBM@',
                '1 SCHMA',
                '2 TAG _PLOS https://plos.local/gedcom/ext',
                '1 LANG en',
            ];
        }

        // GEDCOM 5.5.1 header (backward compatible)
        return [
            '0 HEAD',
            '1 SOUR PLOS',
            '2 VERS 1.0',
            '2 NAME PLOS Genealogy',
            '2 CORP Personal',
            '1 DEST ANY',
            "1 DATE {$now}",
            "2 TIME {$time}",
            '1 SUBM @SUBM@',
            '1 FILE '.$this->sanitizeFileName($tree->name).'.ged',
            '1 GEDC',
            '2 VERS 5.5.1',
            '2 FORM LINEAGE-LINKED',
            '1 CHAR '.self::ENCODING,
            '1 LANG English',
        ];
    }

    /**
     * Build GEDCOM submitter record
     */
    protected function buildSubmitter(array $options): array
    {
        $lines = [
            '0 @SUBM@ SUBM',
            '1 NAME '.$this->sanitize($options['submitter_name']),
        ];

        if (! empty($options['submitter_address'])) {
            $lines[] = '1 ADDR '.$this->sanitize($options['submitter_address']);
        }

        return $lines;
    }

    /**
     * Build GEDCOM individual records
     */
    protected function buildIndividuals(int $treeId, ?int $userId, array $options): array
    {
        $lines = [];

        $persons = DB::select('
            SELECT p.*
            FROM genealogy_persons p
            WHERE p.tree_id = ?
            ORDER BY p.id
        ', [$treeId]);

        foreach ($persons as $person) {
            // Check privacy - skip living persons if not included
            $isLiving = $this->privacyService->isPersonLiving($person->id);

            if ($isLiving && ! $options['include_living']) {
                // Add minimal record for living person
                $lines = array_merge($lines, $this->buildLivingPersonStub($person));

                continue;
            }

            $lines = array_merge($lines, $this->buildIndividualRecord($person, $options));
        }

        return $lines;
    }

    /**
     * Build a minimal stub for a living person
     */
    protected function buildLivingPersonStub(object $person): array
    {
        $xref = '@I'.$person->id.'@';

        return [
            "0 {$xref} INDI",
            '1 NAME Living /'.$this->sanitize($person->surname ?? 'Person').'/',
            '1 RESN privacy',
        ];
    }

    /**
     * Build full individual record
     */
    protected function buildIndividualRecord(object $person, array $options): array
    {
        $xref = '@I'.$person->id.'@';
        $lines = ["0 {$xref} INDI"];

        // Name
        $givenName = $this->sanitize($person->given_name ?? '');
        $surname = $this->sanitize($person->surname ?? '');
        $lines[] = "1 NAME {$givenName} /{$surname}/";
        if ($givenName) {
            $lines[] = '2 GIVN '.$givenName;
        }
        if ($surname) {
            $lines[] = '2 SURN '.$surname;
        }
        if (! empty($person->name_prefix)) {
            $lines[] = '2 NPFX '.$this->sanitize($person->name_prefix);
        }
        if (! empty($person->name_suffix)) {
            $lines[] = '2 NSFX '.$this->sanitize($person->name_suffix);
        }

        // Sex
        if (! empty($person->sex)) {
            $lines[] = '1 SEX '.strtoupper($person->sex);
        }

        // Birth
        if (! empty($person->birth_date) || ! empty($person->birth_place)) {
            $lines[] = '1 BIRT';
            if (! empty($person->birth_date)) {
                $lines[] = '2 DATE '.$this->formatGedcomDate($person->birth_date);
            }
            if (! empty($person->birth_place)) {
                $lines[] = '2 PLAC '.$this->sanitize($person->birth_place);
            }
        }

        // Death
        if (! empty($person->death_date) || ! empty($person->death_place)) {
            $lines[] = '1 DEAT';
            if (! empty($person->death_date)) {
                $lines[] = '2 DATE '.$this->formatGedcomDate($person->death_date);
            }
            if (! empty($person->death_place)) {
                $lines[] = '2 PLAC '.$this->sanitize($person->death_place);
            }
        }

        // Burial
        if (! empty($person->burial_date) || ! empty($person->burial_place)) {
            $lines[] = '1 BURI';
            if (! empty($person->burial_date)) {
                $lines[] = '2 DATE '.$this->formatGedcomDate($person->burial_date);
            }
            if (! empty($person->burial_place)) {
                $lines[] = '2 PLAC '.$this->sanitize($person->burial_place);
            }
        }

        // Occupation
        if (! empty($person->occupation)) {
            $lines[] = '1 OCCU '.$this->sanitize($person->occupation);
        }

        // Events
        $lines = array_merge($lines, $this->buildPersonEvents($person->id));

        // Family links (as child)
        $childFamilies = DB::select('
            SELECT fc.family_id
            FROM genealogy_children fc
            WHERE fc.person_id = ?
        ', [$person->id]);

        foreach ($childFamilies as $fam) {
            $lines[] = '1 FAMC @F'.$fam->family_id.'@';
        }

        // Family links (as spouse)
        $spouseFamilies = DB::select('
            SELECT f.id
            FROM genealogy_families f
            WHERE f.husband_id = ? OR f.wife_id = ?
        ', [$person->id, $person->id]);

        foreach ($spouseFamilies as $fam) {
            $lines[] = '1 FAMS @F'.$fam->id.'@';
        }

        // Notes
        if ($options['include_notes'] && ! empty($person->notes)) {
            $lines = array_merge($lines, $this->buildNote($person->notes));
        }

        // Source citations
        $citations = DB::select('
            SELECT c.*, s.title as source_title
            FROM genealogy_citations c
            JOIN genealogy_sources s ON c.source_id = s.id
            WHERE c.person_id = ?
        ', [$person->id]);

        foreach ($citations as $citation) {
            $lines[] = '1 SOUR @S'.$citation->source_id.'@';
            if (! empty($citation->page)) {
                $lines[] = '2 PAGE '.$this->sanitize($citation->page);
            }
        }

        // Media links
        if ($options['include_media']) {
            $mediaLinks = DB::select('
                SELECT m.id
                FROM genealogy_media m
                JOIN genealogy_person_media pm ON m.id = pm.media_id
                WHERE pm.person_id = ?
            ', [$person->id]);

            foreach ($mediaLinks as $media) {
                $lines[] = '1 OBJE @M'.$media->id.'@';
            }
        }

        return $lines;
    }

    /**
     * Build person event records
     */
    protected function buildPersonEvents(int $personId): array
    {
        $lines = [];

        $events = DB::select('
            SELECT * FROM genealogy_events
            WHERE person_id = ?
            ORDER BY event_date
        ', [$personId]);

        foreach ($events as $event) {
            $tag = $this->mapEventTypeToGedcom($event->event_type);
            $lines[] = "1 {$tag}";

            if (! empty($event->event_date)) {
                $lines[] = '2 DATE '.$this->formatGedcomDate($event->event_date);
            }
            if (! empty($event->event_place)) {
                $lines[] = '2 PLAC '.$this->sanitize($event->event_place);
            }
            if (! empty($event->description)) {
                $lines = array_merge($lines, $this->buildContinuedText('2 NOTE', $event->description));
            }
        }

        return $lines;
    }

    /**
     * Build GEDCOM family records
     */
    protected function buildFamilies(int $treeId): array
    {
        $lines = [];

        $families = DB::select('
            SELECT f.*
            FROM genealogy_families f
            WHERE f.tree_id = ?
            ORDER BY f.id
        ', [$treeId]);

        foreach ($families as $family) {
            $xref = '@F'.$family->id.'@';
            $lines[] = "0 {$xref} FAM";

            // Husband
            if (! empty($family->husband_id)) {
                $lines[] = '1 HUSB @I'.$family->husband_id.'@';
            }

            // Wife
            if (! empty($family->wife_id)) {
                $lines[] = '1 WIFE @I'.$family->wife_id.'@';
            }

            // Marriage
            if (! empty($family->marriage_date) || ! empty($family->marriage_place)) {
                $lines[] = '1 MARR';
                if (! empty($family->marriage_date)) {
                    $lines[] = '2 DATE '.$this->formatGedcomDate($family->marriage_date);
                }
                if (! empty($family->marriage_place)) {
                    $lines[] = '2 PLAC '.$this->sanitize($family->marriage_place);
                }
            }

            // Divorce
            if (! empty($family->divorce_date)) {
                $lines[] = '1 DIV';
                $lines[] = '2 DATE '.$this->formatGedcomDate($family->divorce_date);
            }

            // Children
            $children = DB::select('
                SELECT person_id FROM genealogy_children
                WHERE family_id = ?
                ORDER BY birth_order
            ', [$family->id]);

            foreach ($children as $child) {
                $lines[] = '1 CHIL @I'.$child->person_id.'@';
            }

            // Family events
            $lines = array_merge($lines, $this->buildFamilyEvents($family->id));
        }

        return $lines;
    }

    /**
     * Build family event records
     */
    protected function buildFamilyEvents(int $familyId): array
    {
        $lines = [];

        $events = DB::select('
            SELECT * FROM genealogy_family_events
            WHERE family_id = ?
            ORDER BY event_date
        ', [$familyId]);

        foreach ($events as $event) {
            $tag = $this->mapFamilyEventTypeToGedcom($event->event_type);
            $lines[] = "1 {$tag}";

            if (! empty($event->event_date)) {
                $lines[] = '2 DATE '.$this->formatGedcomDate($event->event_date);
            }
            if (! empty($event->event_place)) {
                $lines[] = '2 PLAC '.$this->sanitize($event->event_place);
            }
        }

        return $lines;
    }

    /**
     * Build GEDCOM source records
     */
    protected function buildSources(int $treeId): array
    {
        $lines = [];

        $sources = DB::select('
            SELECT * FROM genealogy_sources
            WHERE tree_id = ?
            ORDER BY id
        ', [$treeId]);

        foreach ($sources as $source) {
            $xref = '@S'.$source->id.'@';
            $lines[] = "0 {$xref} SOUR";

            if (! empty($source->title)) {
                $lines[] = '1 TITL '.$this->sanitize($source->title);
            }
            if (! empty($source->author)) {
                $lines[] = '1 AUTH '.$this->sanitize($source->author);
            }
            if (! empty($source->publication)) {
                $lines[] = '1 PUBL '.$this->sanitize($source->publication);
            }
            if (! empty($source->abbreviation)) {
                $lines[] = '1 ABBR '.$this->sanitize($source->abbreviation);
            }
            if (! empty($source->repository_id)) {
                $lines[] = '1 REPO @R'.$source->repository_id.'@';
                if (! empty($source->call_number)) {
                    $lines[] = '2 CALN '.$this->sanitize($source->call_number);
                }
            }
            if (! empty($source->text)) {
                $lines = array_merge($lines, $this->buildContinuedText('1 TEXT', $source->text));
            }
            if (! empty($source->notes)) {
                $lines = array_merge($lines, $this->buildNote($source->notes));
            }
        }

        return $lines;
    }

    /**
     * Build GEDCOM repository records
     */
    protected function buildRepositories(int $treeId): array
    {
        $lines = [];

        $repositories = DB::select('
            SELECT * FROM genealogy_repositories
            WHERE tree_id = ?
            ORDER BY id
        ', [$treeId]);

        foreach ($repositories as $repo) {
            $xref = '@R'.$repo->id.'@';
            $lines[] = "0 {$xref} REPO";

            if (! empty($repo->name)) {
                $lines[] = '1 NAME '.$this->sanitize($repo->name);
            }
            if (! empty($repo->address) || ! empty($repo->city) || ! empty($repo->state)) {
                $lines[] = '1 ADDR '.$this->sanitize($repo->address ?? '');
                if (! empty($repo->city)) {
                    $lines[] = '2 CITY '.$this->sanitize($repo->city);
                }
                if (! empty($repo->state)) {
                    $lines[] = '2 STAE '.$this->sanitize($repo->state);
                }
                if (! empty($repo->postal_code)) {
                    $lines[] = '2 POST '.$this->sanitize($repo->postal_code);
                }
                if (! empty($repo->country)) {
                    $lines[] = '2 CTRY '.$this->sanitize($repo->country);
                }
            }
            if (! empty($repo->phone)) {
                $lines[] = '1 PHON '.$this->sanitize($repo->phone);
            }
            if (! empty($repo->email)) {
                $lines[] = '1 EMAIL '.$this->sanitize($repo->email);
            }
            if (! empty($repo->website)) {
                $lines[] = '1 WWW '.$this->sanitize($repo->website);
            }
        }

        return $lines;
    }

    /**
     * Build GEDCOM media object records
     */
    protected function buildMediaObjects(int $treeId): array
    {
        $lines = [];

        $media = DB::select('
            SELECT * FROM genealogy_media
            WHERE tree_id = ?
            ORDER BY id
        ', [$treeId]);

        foreach ($media as $item) {
            $xref = '@M'.$item->id.'@';
            $lines[] = "0 {$xref} OBJE";

            // File reference
            $lines[] = '1 FILE '.$this->sanitize($item->nextcloud_path ?? $item->file_path ?? 'unknown');

            // Format
            $format = $this->mapMimeToGedcomFormat($item->mime_type ?? '');
            if ($format) {
                $lines[] = '2 FORM '.$format;
            }

            // Title
            if (! empty($item->title)) {
                $lines[] = '1 TITL '.$this->sanitize($item->title);
            }

            // Date
            if (! empty($item->date_taken)) {
                $lines[] = '1 DATE '.$this->formatGedcomDate($item->date_taken);
            }

            // Note/description
            if (! empty($item->description)) {
                $lines = array_merge($lines, $this->buildNote($item->description));
            }
        }

        return $lines;
    }

    /**
     * Build a NOTE structure with continuation lines
     */
    protected function buildNote(string $text): array
    {
        return $this->buildContinuedText('1 NOTE', $text);
    }

    /**
     * Build text with CONC/CONT continuation lines
     */
    protected function buildContinuedText(string $prefix, string $text): array
    {
        $lines = [];
        $text = $this->sanitize($text);

        // Split by newlines first
        $paragraphs = explode("\n", $text);
        $isFirst = true;
        $level = (int) substr($prefix, 0, 1);

        foreach ($paragraphs as $paragraph) {
            if ($isFirst) {
                // First line uses the prefix
                $line = $prefix.' '.substr($paragraph, 0, self::MAX_LINE_LENGTH - strlen($prefix) - 1);
                $lines[] = $line;
                $remaining = substr($paragraph, self::MAX_LINE_LENGTH - strlen($prefix) - 1);
                $isFirst = false;
            } else {
                // New paragraph uses CONT
                $line = ($level + 1).' CONT '.substr($paragraph, 0, self::MAX_LINE_LENGTH - 7);
                $lines[] = $line;
                $remaining = substr($paragraph, self::MAX_LINE_LENGTH - 7);
            }

            // Handle long lines with CONC
            while (strlen($remaining) > 0) {
                $chunk = substr($remaining, 0, self::MAX_LINE_LENGTH - 7);
                $lines[] = ($level + 1).' CONC '.$chunk;
                $remaining = substr($remaining, self::MAX_LINE_LENGTH - 7);
            }
        }

        return $lines;
    }

    /**
     * Map event type to GEDCOM tag
     */
    protected function mapEventTypeToGedcom(string $type): string
    {
        $map = [
            'BIRT' => 'BIRT',
            'CHR' => 'CHR',
            'DEAT' => 'DEAT',
            'BURI' => 'BURI',
            'CREM' => 'CREM',
            'ADOP' => 'ADOP',
            'BAPM' => 'BAPM',
            'BARM' => 'BARM',
            'BASM' => 'BASM',
            'BLES' => 'BLES',
            'CHRA' => 'CHRA',
            'CONF' => 'CONF',
            'FCOM' => 'FCOM',
            'ORDN' => 'ORDN',
            'NATU' => 'NATU',
            'EMIG' => 'EMIG',
            'IMMI' => 'IMMI',
            'CENS' => 'CENS',
            'PROB' => 'PROB',
            'WILL' => 'WILL',
            'GRAD' => 'GRAD',
            'RETI' => 'RETI',
            'EVEN' => 'EVEN',
            'RESI' => 'RESI',
            'OCCU' => 'OCCU',
            'EDUC' => 'EDUC',
            'TITL' => 'TITL',
            'NATI' => 'NATI',
            'RELI' => 'RELI',
            'SSN' => 'SSN',
            'CAST' => 'CAST',
            'DSCR' => 'DSCR',
            'IDNO' => 'IDNO',
            'PROP' => 'PROP',
        ];

        return $map[strtoupper($type)] ?? 'EVEN';
    }

    /**
     * Map family event type to GEDCOM tag
     */
    protected function mapFamilyEventTypeToGedcom(string $type): string
    {
        $map = [
            'MARR' => 'MARR',
            'ANUL' => 'ANUL',
            'CENS' => 'CENS',
            'DIV' => 'DIV',
            'DIVF' => 'DIVF',
            'ENGA' => 'ENGA',
            'MARB' => 'MARB',
            'MARC' => 'MARC',
            'MARL' => 'MARL',
            'MARS' => 'MARS',
            'EVEN' => 'EVEN',
        ];

        return $map[strtoupper($type)] ?? 'EVEN';
    }

    /**
     * Map MIME type to GEDCOM format
     */
    protected function mapMimeToGedcomFormat(?string $mime): ?string
    {
        if (! $mime) {
            return null;
        }

        $map = [
            'image/jpeg' => 'jpeg',
            'image/jpg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/tiff' => 'tiff',
            'image/bmp' => 'bmp',
            'application/pdf' => 'pdf',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'video/mp4' => 'mp4',
            'video/avi' => 'avi',
        ];

        return $map[$mime] ?? null;
    }

    /**
     * Format date to GEDCOM format
     */
    protected function formatGedcomDate(?string $date): string
    {
        if (! $date) {
            return '';
        }

        // Already in GEDCOM format (has month abbreviation)
        if (preg_match('/[A-Z]{3}/', $date)) {
            return strtoupper($date);
        }

        // Try to parse standard date formats
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return strtoupper(date('d M Y', $timestamp));
        }

        // Return as-is (may be approximate date like "ABT 1850")
        return strtoupper($date);
    }

    /**
     * Sanitize text for GEDCOM output
     */
    protected function sanitize(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        // Remove control characters except newlines
        $text = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Replace @ with @@ (GEDCOM escape)
        $text = str_replace('@', '@@', $text);

        return trim($text);
    }

    /**
     * Sanitize filename
     */
    protected function sanitizeFileName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }

    /**
     * Export tree to file and return the file path
     *
     * @return string File path
     */
    public function exportToFile(int $treeId, ?int $userId = null, array $options = []): string
    {
        $tree = DB::selectOne('SELECT name FROM genealogy_trees WHERE id = ?', [$treeId]);
        if (! $tree) {
            throw new \InvalidArgumentException("Tree not found: {$treeId}");
        }

        $content = $this->exportTree($treeId, $userId, $options);

        $filename = $this->sanitizeFileName($tree->name).'_'.date('Y-m-d_His').'.ged';
        $path = storage_path('app/genealogy/exports/'.$filename);

        // Ensure directory exists
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $content);

        Log::info('GEDCOM export completed', [
            'tree_id' => $treeId,
            'file' => $filename,
            'size' => strlen($content),
        ]);

        return $path;
    }

    /**
     * N76: Export tree as GEDZip (.gdz) — bundled GEDCOM + media files.
     *
     * GEDZip is a ZIP archive containing:
     * - {tree_name}.ged — the GEDCOM file
     * - media/ — directory of referenced media files
     *
     * Per GEDCOM 7.0 spec, .gdz is the standard portable format.
     *
     * @param  int  $treeId  Tree to export
     * @param  int|null  $userId  User for privacy filtering
     * @param  array  $options  Export options
     * @return string Path to the generated .gdz file
     */
    public function exportToGedZip(int $treeId, ?int $userId = null, array $options = []): string
    {
        $tree = DB::selectOne('SELECT name FROM genealogy_trees WHERE id = ?', [$treeId]);
        if (! $tree) {
            throw new \InvalidArgumentException("Tree not found: {$treeId}");
        }

        // Generate GEDCOM content
        $gedcomContent = $this->exportTree($treeId, $userId, $options);
        $treeName = $this->sanitizeFileName($tree->name);
        $zipFilename = $treeName.'_'.date('Y-m-d_His').'.gdz';
        $zipPath = storage_path('app/genealogy/exports/'.$zipFilename);

        $dir = dirname($zipPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create GEDZip file: {$zipPath}");
        }

        // Add GEDCOM file
        $zip->addFromString($treeName.'.ged', $gedcomContent);

        $mediaCount = 0;
        if ($options['include_media'] ?? true) {
            // Add media files referenced by this tree
            $mediaFiles = DB::select(
                'SELECT gm.nextcloud_path, gm.local_filename
                 FROM genealogy_media gm
                 WHERE gm.tree_id = ? AND gm.nextcloud_path IS NOT NULL',
                [$treeId]
            );

            $nextcloudPath = config('services.nextcloud.data_path');

            foreach ($mediaFiles as $media) {
                $fullPath = null;

                // Filesystem-first (Nextcloud data path)
                if ($nextcloudPath && $media->nextcloud_path) {
                    $candidate = $nextcloudPath.'/'.ltrim($media->nextcloud_path, '/');
                    if (file_exists($candidate)) {
                        $fullPath = $candidate;
                    }
                }

                if ($fullPath) {
                    $archiveName = 'media/'.($media->local_filename ?: basename($media->nextcloud_path));
                    $zip->addFile($fullPath, $archiveName);
                    $mediaCount++;
                }
            }
        }

        $zip->close();

        Log::info('GEDZip export completed', [
            'tree_id' => $treeId,
            'file' => $zipFilename,
            'gedcom_size' => strlen($gedcomContent),
            'media_files' => $mediaCount,
            'zip_size' => filesize($zipPath),
        ]);

        return $zipPath;
    }
}
