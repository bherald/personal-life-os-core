<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\Log;

/**
 * Gedcom7ParserService - GEDCOM 7.0 (FamilySearch) File Parser
 *
 * Parses GEDCOM 7.0 format files into structured PHP arrays.
 * Maintains backward compatibility by normalizing to the same internal format
 * as GedcomParserService (5.5.1).
 *
 * Key GEDCOM 7.0 differences handled:
 * - UTF-8 only encoding (no ANSEL)
 * - SCHMA for extension tag definitions
 * - SNOTE for shared notes (reusable note records)
 * - EXID for external identifiers with TYPE
 * - LANG tags for multilingual content
 * - Media: OBJE with required FILE/FORM structure
 * - CROP for image cropping
 * - TRAN for translated content
 *
 * @see https://gedcom.io/specifications/FamilySearchGEDCOMv7.html
 * @see https://github.com/FamilySearch/GEDCOM
 */
class Gedcom7ParserService
{
    protected string $filePath;
    protected array $lines = [];
    protected int $currentLine = 0;

    // Parsed records
    protected array $persons = [];
    protected array $families = [];
    protected array $media = [];
    protected array $sources = [];
    protected array $repositories = [];
    protected array $sharedNotes = [];
    protected array $header = [];

    // GEDCOM 7.0 specific
    protected array $schemaExtensions = [];
    protected string $defaultLang = 'en';

    // Current parsing state
    protected ?array $currentRecord = null;
    protected ?string $currentId = null;
    protected ?string $currentType = null;
    protected array $contextStack = [];

    // Source citation tracking
    protected ?string $currentSourceCitation = null;
    protected ?string $currentSourceCitationPage = null;
    protected ?string $currentSourceCitationLink = null;
    protected ?string $currentSourceCitationNote = null;
    protected ?string $currentSourceCitationText = null;
    protected array $sourceCitationMedia = [];
    protected array $sourceCitations = [];

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * Parse the GEDCOM 7.0 file
     *
     * @return array Parsed data normalized to internal format
     * @throws \Exception If file cannot be read
     */
    public function parse(): array
    {
        if (!file_exists($this->filePath)) {
            throw new \Exception("GEDCOM file not found: {$this->filePath}");
        }

        // GEDCOM 7.0 is always UTF-8
        $content = file_get_contents($this->filePath);
        $content = $this->removeBom($content);
        $this->lines = explode("\n", $content);

        Log::info('Gedcom7Parser: Starting parse', [
            'file' => basename($this->filePath),
            'lines' => count($this->lines)
        ]);

        // First pass: collect SCHMA definitions
        $this->parseSchemaDefinitions();

        // Second pass: parse all records
        foreach ($this->lines as $lineNum => $line) {
            $this->currentLine = $lineNum + 1;
            $this->parseLine(rtrim($line, "\r\n"));
        }

        // Save final record
        $this->saveCurrentRecord();

        // Resolve shared note references
        $this->resolveSharedNoteReferences();

        $stats = $this->getStatistics();
        Log::info('Gedcom7Parser: Parse complete', $stats);

        return [
            'header' => $this->header,
            'persons' => $this->persons,
            'families' => $this->families,
            'media' => $this->media,
            'sources' => $this->sources,
            'repositories' => $this->repositories,
            'shared_notes' => $this->sharedNotes,
            'schema_extensions' => $this->schemaExtensions,
            'source_citation_media' => $this->sourceCitationMedia,
            'source_citations' => $this->sourceCitations,
            'stats' => $stats,
        ];
    }

    /**
     * First pass: extract SCHMA definitions from header
     */
    protected function parseSchemaDefinitions(): void
    {
        $inHeader = false;
        $inSchema = false;

        foreach ($this->lines as $line) {
            $line = rtrim($line, "\r\n");

            if (preg_match('/^0\s+HEAD/', $line)) {
                $inHeader = true;
                continue;
            }

            if ($inHeader && preg_match('/^0\s+/', $line)) {
                break; // End of header
            }

            if ($inHeader && preg_match('/^1\s+SCHMA/', $line)) {
                $inSchema = true;
                continue;
            }

            if ($inSchema && preg_match('/^1\s+/', $line)) {
                $inSchema = false;
            }

            // Parse TAG definitions: "2 TAG _CUSTOM http://example.com/terms/custom"
            if ($inSchema && preg_match('/^2\s+TAG\s+(\S+)\s+(\S+)/', $line, $matches)) {
                $extensionTag = $matches[1];
                $uri = $matches[2];
                $this->schemaExtensions[$extensionTag] = $uri;
            }
        }

        if (!empty($this->schemaExtensions)) {
            Log::debug('Gedcom7Parser: Found schema extensions', [
                'count' => count($this->schemaExtensions),
                'tags' => array_keys($this->schemaExtensions)
            ]);
        }
    }

    /**
     * Parse a single line
     */
    protected function parseLine(string $line): void
    {
        if (empty(trim($line))) {
            return;
        }

        if (!preg_match('/^(\d+)\s+(.*)$/', $line, $matches)) {
            return;
        }

        $level = (int) $matches[1];
        $content = $matches[2];

        if ($level === 0) {
            $this->saveCurrentRecord();
            $this->startNewRecord($content);
            $this->contextStack = [[$level, $this->currentType ?? 'ROOT']];
        } else {
            $this->processSubRecord($level, $content);
        }
    }

    /**
     * Start a new level-0 record
     */
    protected function startNewRecord(string $content): void
    {
        $this->currentRecord = null;
        $this->currentId = null;
        $this->currentType = null;

        if ($content === 'HEAD') {
            $this->currentRecord = [];
            $this->currentType = 'HEAD';
            return;
        }

        if ($content === 'TRLR') {
            return;
        }

        // Parse record with ID: @I123@ INDI or @N1@ SNOTE Text here
        // SNOTE can have payload text on the same line
        if (preg_match('/^@([^@]+)@\s+(\w+)(?:\s+(.*))?$/', $content, $matches)) {
            $this->currentId = $matches[1];
            $this->currentType = $matches[2];
            $payload = $matches[3] ?? '';

            switch ($this->currentType) {
                case 'INDI':
                    $this->currentRecord = $this->newPersonRecord($this->currentId);
                    break;
                case 'FAM':
                    $this->currentRecord = $this->newFamilyRecord($this->currentId);
                    break;
                case 'OBJE':
                    $this->currentRecord = $this->newMediaRecord($this->currentId);
                    break;
                case 'SOUR':
                    $this->currentRecord = $this->newSourceRecord($this->currentId);
                    break;
                case 'REPO':
                    $this->currentRecord = $this->newRepositoryRecord($this->currentId);
                    break;
                case 'SNOTE':
                    $this->currentRecord = $this->newSharedNoteRecord($this->currentId);
                    // SNOTE has payload text on the record line itself
                    if (!empty($payload)) {
                        $this->currentRecord['text'] = $payload;
                    }
                    break;
                case 'SUBM':
                    $this->currentRecord = ['id' => $this->currentId, 'type' => 'SUBM'];
                    break;
            }
        }
    }

    /**
     * Process a sub-level record (level > 0)
     */
    protected function processSubRecord(int $level, string $content): void
    {
        if ($this->currentRecord === null) {
            return;
        }

        // Parse tag and value
        $parts = explode(' ', $content, 2);
        $tag = $parts[0];
        $value = $parts[1] ?? '';

        // Handle pointer values: @I123@
        if (preg_match('/^@([^@]+)@$/', $value, $matches)) {
            $value = $matches[1];
        }

        // Update context stack
        while (!empty($this->contextStack) && end($this->contextStack)[0] >= $level) {
            $popped = array_pop($this->contextStack);
            if ($popped[1] === 'SOUR') {
                $this->saveCurrentCitation();
                $this->currentSourceCitation = null;
                $this->currentSourceCitationPage = null;
                $this->currentSourceCitationLink = null;
                $this->currentSourceCitationNote = null;
                $this->currentSourceCitationText = null;
            }
        }
        $this->contextStack[] = [$level, $tag];

        $parentTag = count($this->contextStack) > 1
            ? $this->contextStack[count($this->contextStack) - 2][1]
            : null;

        // Process based on record type
        switch ($this->currentType) {
            case 'HEAD':
                $this->processHeaderField($tag, $value, $parentTag, $level);
                break;
            case 'INDI':
                $this->processPersonField($tag, $value, $parentTag, $level);
                break;
            case 'FAM':
                $this->processFamilyField($tag, $value, $parentTag, $level);
                break;
            case 'OBJE':
                $this->processMediaField($tag, $value, $parentTag, $level);
                break;
            case 'SOUR':
                $this->processSourceField($tag, $value, $parentTag, $level);
                break;
            case 'REPO':
                $this->processRepositoryField($tag, $value, $parentTag);
                break;
            case 'SNOTE':
                $this->processSharedNoteField($tag, $value, $parentTag);
                break;
        }
    }

    /**
     * Create new person record structure with GEDCOM 7.0 fields
     */
    protected function newPersonRecord(string $id): array
    {
        return [
            'gedcom_id' => $id,
            'name' => '',
            'given_name' => '',
            'surname' => '',
            'suffix' => '',
            'nickname' => '',
            'sex' => '',
            'birth_date' => '',
            'birth_place' => '',
            'birth_lat' => null,
            'birth_lon' => null,
            'death_date' => '',
            'death_place' => '',
            'death_lat' => null,
            'death_lon' => null,
            'burial_date' => '',
            'burial_place' => '',
            'burial_lat' => null,
            'burial_lon' => null,
            'occupation' => '',
            'education' => '',
            'religion' => '',
            'families_as_spouse' => [],
            'family_as_child' => '',
            'media_refs' => [],
            'primary_photo' => '',
            'residences' => [],
            'events' => [],
            'sources' => [],
            'notes' => '',
            // GEDCOM 7.0 specific
            'external_ids' => [],    // EXID with TYPE
            'uid' => '',             // UID
            'languages' => [],       // LANG tags
            'shared_note_refs' => [], // SNOTE references
            'name_translations' => [], // TRAN for names
        ];
    }

    /**
     * Create new family record structure
     */
    protected function newFamilyRecord(string $id): array
    {
        return [
            'gedcom_id' => $id,
            'husband_id' => '',
            'wife_id' => '',
            'children' => [],
            'marriage_date' => '',
            'marriage_place' => '',
            'marriage_lat' => null,
            'marriage_lon' => null,
            'divorce_date' => '',
            'divorce_place' => '',
            'annulment_date' => '',
            'media_refs' => [],
            'sources' => [],
            'notes' => '',
            // GEDCOM 7.0 specific
            'external_ids' => [],
            'uid' => '',
            'shared_note_refs' => [],
        ];
    }

    /**
     * Create new media record structure (GEDCOM 7.0 OBJE)
     */
    protected function newMediaRecord(string $id): array
    {
        return [
            'gedcom_id' => $id,
            'file_path' => '',
            'file_format' => '',
            'media_type' => '',      // GEDCOM 7.0: FORM.TYPE
            'title' => '',
            'media_date' => '',
            'description' => '',
            // GEDCOM 7.0 specific
            'files' => [],           // Multiple FILE structures supported
            'crop' => null,          // CROP with TOP/LEFT/HEIGHT/WIDTH
            'translations' => [],    // TRAN structures
            'uid' => '',
            'external_ids' => [],
        ];
    }

    /**
     * Create new source record structure
     */
    protected function newSourceRecord(string $id): array
    {
        return [
            'gedcom_id' => $id,
            'author' => '',
            'title' => '',
            'publication' => '',
            'repository_id' => '',
            'notes' => '',
            // GEDCOM 7.0 specific
            'uid' => '',
            'external_ids' => [],
            'shared_note_refs' => [],
        ];
    }

    /**
     * Create new repository record structure
     */
    protected function newRepositoryRecord(string $id): array
    {
        return [
            'gedcom_id' => $id,
            'name' => '',
            'address' => '',
            // GEDCOM 7.0 specific
            'uid' => '',
            'external_ids' => [],
            'www' => '',
            'email' => '',
            'phone' => '',
        ];
    }

    /**
     * Create new shared note record structure (GEDCOM 7.0 SNOTE)
     */
    protected function newSharedNoteRecord(string $id): array
    {
        return [
            'gedcom_id' => $id,
            'text' => '',
            'mime_type' => '',       // Optional MIME
            'lang' => '',            // LANG tag
            'translations' => [],    // TRAN structures
            'sources' => [],
        ];
    }

    /**
     * Process header fields (GEDCOM 7.0 specific)
     */
    protected function processHeaderField(string $tag, string $value, ?string $parentTag, int $level): void
    {
        switch ($tag) {
            case 'SOUR':
                $this->header['source_software'] = $value;
                break;
            case 'NAME':
                if ($parentTag === 'SOUR') {
                    $this->header['source_name'] = $value;
                }
                break;
            case 'VERS':
                if ($parentTag === 'SOUR') {
                    $this->header['source_version'] = $value;
                } elseif ($parentTag === 'GEDC') {
                    $this->header['gedcom_version'] = $value;
                }
                break;
            case 'GEDC':
                // Container for version info
                break;
            case 'SCHMA':
                // Schema container - parsed in first pass
                break;
            case 'TAG':
                // Extension tag definition - parsed in first pass
                break;
            case 'LANG':
                $this->header['default_language'] = $value;
                $this->defaultLang = $value;
                break;
            case 'DATE':
                $this->header['export_date'] = $value;
                break;
            case 'DEST':
                $this->header['destination'] = $value;
                break;
            case 'COPR':
                $this->header['copyright'] = $value;
                break;
            case 'SUBM':
                $this->header['submitter'] = $value;
                break;
            case 'NOTE':
                $this->header['note'] = ($this->header['note'] ?? '') . $value;
                break;
        }
    }

    /**
     * Process person (INDI) fields including GEDCOM 7.0 additions
     */
    protected function processPersonField(string $tag, string $value, ?string $parentTag, int $level): void
    {
        switch ($tag) {
            case 'NAME':
                if ($parentTag === 'INDI' || $level === 1) {
                    $this->currentRecord['name'] = $value;
                    if (preg_match('/\/([^\/]*)\//', $value, $matches)) {
                        $this->currentRecord['surname'] = $matches[1];
                        $beforeSurname = trim(preg_split('/\//', $value)[0]);
                        if (preg_match('/^([^(]+)\s*\(([^)]+)\)/', $beforeSurname, $nickMatch)) {
                            $this->currentRecord['given_name'] = trim($nickMatch[1]);
                            $this->currentRecord['nickname'] = trim($nickMatch[2]);
                        } else {
                            $this->currentRecord['given_name'] = $beforeSurname;
                        }
                    }
                    if (preg_match('/\/[^\/]*\/\s*(.+)$/', $value, $suffixMatch)) {
                        $this->currentRecord['suffix'] = trim($suffixMatch[1]);
                    }
                }
                break;

            case 'TRAN':
                // Translation of name (GEDCOM 7.0)
                if ($parentTag === 'NAME') {
                    $this->currentRecord['name_translations'][] = ['text' => $value, 'lang' => ''];
                }
                break;

            case 'SEX':
                $this->currentRecord['sex'] = $value;
                break;

            case 'BIRT':
            case 'DEAT':
            case 'BURI':
            case 'RESI':
                // Event containers
                if ($tag === 'RESI') {
                    $this->currentRecord['residences'][] = [
                        'date' => '', 'place' => '', 'lat' => null, 'lon' => null
                    ];
                }
                break;

            case 'DATE':
                $this->applyDateToContext($value);
                break;

            case 'PLAC':
                $this->applyPlaceToContext($value);
                break;

            case 'LATI':
                $lat = $this->parseCoordinate($value);
                $this->applyCoordinateToContext('lat', $lat);
                break;

            case 'LONG':
                $lon = $this->parseCoordinate($value);
                $this->applyCoordinateToContext('lon', $lon);
                break;

            case 'OCCU':
                $this->currentRecord['occupation'] = $value;
                break;

            case 'EDUC':
                $this->currentRecord['education'] = $value;
                break;

            case 'RELI':
                $this->currentRecord['religion'] = $value;
                break;

            case 'FAMS':
                $this->currentRecord['families_as_spouse'][] = $value;
                break;

            case 'FAMC':
                $this->currentRecord['family_as_child'] = $value;
                break;

            case 'OBJE':
                $this->currentRecord['media_refs'][] = $value;
                break;

            case 'NOTE':
                if (!empty($value)) {
                    $this->currentRecord['notes'] .= $value . "\n";
                }
                break;

            case 'SNOTE':
                // GEDCOM 7.0: Shared note reference
                $this->currentRecord['shared_note_refs'][] = $value;
                break;

            case 'CONC':
            case 'CONT':
                if ($parentTag === 'NOTE') {
                    $separator = ($tag === 'CONT') ? "\n" : '';
                    $this->currentRecord['notes'] .= $separator . $value;
                }
                break;

            case 'SOUR':
                $this->currentRecord['sources'][] = $value;
                $this->saveCurrentCitation();
                $this->currentSourceCitation = $value;
                $this->currentSourceCitationPage = null;
                $this->currentSourceCitationLink = null;
                $this->currentSourceCitationNote = null;
                $this->currentSourceCitationText = null;
                break;

            case 'PAGE':
                if ($this->currentSourceCitation) {
                    $this->currentSourceCitationPage = $value;
                }
                break;

            case 'UID':
                // GEDCOM 7.0: Unique identifier
                $this->currentRecord['uid'] = $value;
                break;

            case 'EXID':
                // GEDCOM 7.0: External identifier (will get TYPE in substructure)
                $this->currentRecord['external_ids'][] = ['value' => $value, 'type' => ''];
                break;

            case 'TYPE':
                // TYPE for EXID
                if ($parentTag === 'EXID' && !empty($this->currentRecord['external_ids'])) {
                    $idx = count($this->currentRecord['external_ids']) - 1;
                    $this->currentRecord['external_ids'][$idx]['type'] = $value;
                }
                break;

            case 'LANG':
                // GEDCOM 7.0: Language tag
                $this->currentRecord['languages'][] = $value;
                break;

            case '_PHOTO':
                $this->currentRecord['primary_photo'] = $value;
                break;
        }

        // Handle OBJE nested under SOUR
        if ($tag === 'OBJE' && $this->currentSourceCitation) {
            $inSourceContext = false;
            foreach (array_reverse($this->contextStack) as [$stackLevel, $stackTag]) {
                if ($stackTag === 'SOUR') {
                    $inSourceContext = true;
                    break;
                }
                if ($stackTag === 'INDI' || $stackLevel === 1) break;
            }

            if ($inSourceContext) {
                $this->sourceCitationMedia[] = [
                    'source_gedcom_id' => $this->currentSourceCitation,
                    'media_gedcom_id' => $value,
                    'person_gedcom_id' => $this->currentRecord['gedcom_id'] ?? null,
                    'citation_page' => $this->currentSourceCitationPage,
                ];
            }
        }
    }

    /**
     * Apply date value to appropriate context field
     */
    protected function applyDateToContext(string $value): void
    {
        foreach (array_reverse($this->contextStack) as [$level, $tag]) {
            switch ($tag) {
                case 'BIRT':
                    $this->currentRecord['birth_date'] = $value;
                    return;
                case 'DEAT':
                    $this->currentRecord['death_date'] = $value;
                    return;
                case 'BURI':
                    $this->currentRecord['burial_date'] = $value;
                    return;
                case 'MARR':
                    $this->currentRecord['marriage_date'] = $value;
                    return;
                case 'DIV':
                    $this->currentRecord['divorce_date'] = $value;
                    return;
                case 'ANUL':
                    $this->currentRecord['annulment_date'] = $value;
                    return;
                case 'RESI':
                    if (!empty($this->currentRecord['residences'])) {
                        $idx = count($this->currentRecord['residences']) - 1;
                        $this->currentRecord['residences'][$idx]['date'] = $value;
                    }
                    return;
            }
        }
    }

    /**
     * Apply place value to appropriate context field
     */
    protected function applyPlaceToContext(string $value): void
    {
        foreach (array_reverse($this->contextStack) as [$level, $tag]) {
            switch ($tag) {
                case 'BIRT':
                    $this->currentRecord['birth_place'] = $value;
                    return;
                case 'DEAT':
                    $this->currentRecord['death_place'] = $value;
                    return;
                case 'BURI':
                    $this->currentRecord['burial_place'] = $value;
                    return;
                case 'MARR':
                    $this->currentRecord['marriage_place'] = $value;
                    return;
                case 'DIV':
                    $this->currentRecord['divorce_place'] = $value;
                    return;
                case 'RESI':
                    if (!empty($this->currentRecord['residences'])) {
                        $idx = count($this->currentRecord['residences']) - 1;
                        $this->currentRecord['residences'][$idx]['place'] = $value;
                    }
                    return;
            }
        }
    }

    /**
     * Apply coordinate to appropriate context field
     */
    protected function applyCoordinateToContext(string $type, ?float $value): void
    {
        foreach (array_reverse($this->contextStack) as [$level, $tag]) {
            switch ($tag) {
                case 'BIRT':
                    $this->currentRecord["birth_{$type}"] = $value;
                    return;
                case 'DEAT':
                    $this->currentRecord["death_{$type}"] = $value;
                    return;
                case 'BURI':
                    $this->currentRecord["burial_{$type}"] = $value;
                    return;
                case 'MARR':
                    $this->currentRecord["marriage_{$type}"] = $value;
                    return;
                case 'RESI':
                    if (!empty($this->currentRecord['residences'])) {
                        $idx = count($this->currentRecord['residences']) - 1;
                        $this->currentRecord['residences'][$idx][$type] = $value;
                    }
                    return;
            }
        }
    }

    /**
     * Process family (FAM) fields
     */
    protected function processFamilyField(string $tag, string $value, ?string $parentTag, int $level): void
    {
        switch ($tag) {
            case 'HUSB':
                $this->currentRecord['husband_id'] = $value;
                break;

            case 'WIFE':
                $this->currentRecord['wife_id'] = $value;
                break;

            case 'CHIL':
                $this->currentRecord['children'][] = [
                    'id' => $value,
                    'frel' => 'Natural',
                    'mrel' => 'Natural'
                ];
                break;

            case 'MARR':
            case 'DIV':
            case 'ANUL':
                // Event containers
                break;

            case 'DATE':
                $this->applyDateToContext($value);
                break;

            case 'PLAC':
                $this->applyPlaceToContext($value);
                break;

            case 'LATI':
                $lat = $this->parseCoordinate($value);
                $this->applyCoordinateToContext('lat', $lat);
                break;

            case 'LONG':
                $lon = $this->parseCoordinate($value);
                $this->applyCoordinateToContext('lon', $lon);
                break;

            case 'OBJE':
                $this->currentRecord['media_refs'][] = $value;
                break;

            case 'SOUR':
                $this->currentRecord['sources'][] = $value;
                break;

            case 'NOTE':
                if (!empty($value)) {
                    $this->currentRecord['notes'] .= $value . "\n";
                }
                break;

            case 'SNOTE':
                $this->currentRecord['shared_note_refs'][] = $value;
                break;

            case 'UID':
                $this->currentRecord['uid'] = $value;
                break;

            case 'EXID':
                $this->currentRecord['external_ids'][] = ['value' => $value, 'type' => ''];
                break;
        }
    }

    /**
     * Process media (OBJE) fields (GEDCOM 7.0 structure)
     */
    protected function processMediaField(string $tag, string $value, ?string $parentTag, int $level): void
    {
        switch ($tag) {
            case 'FILE':
                // GEDCOM 7.0: Multiple FILE structures allowed
                $this->currentRecord['files'][] = [
                    'path' => $value,
                    'form' => '',
                    'media_type' => '',
                    'title' => '',
                ];
                // Also set primary file_path for backward compatibility
                if (empty($this->currentRecord['file_path'])) {
                    $this->currentRecord['file_path'] = $value;
                }
                break;

            case 'FORM':
                // GEDCOM 7.0: Required under FILE
                if (!empty($this->currentRecord['files'])) {
                    $idx = count($this->currentRecord['files']) - 1;
                    $this->currentRecord['files'][$idx]['form'] = strtolower($value);
                }
                $this->currentRecord['file_format'] = strtolower($value);
                break;

            case 'TYPE':
                // Media type (under FORM in 7.0)
                if ($parentTag === 'FORM' && !empty($this->currentRecord['files'])) {
                    $idx = count($this->currentRecord['files']) - 1;
                    $this->currentRecord['files'][$idx]['media_type'] = $value;
                }
                $this->currentRecord['media_type'] = $value;
                break;

            case 'TITL':
                // Can be under FILE or at OBJE level
                if ($parentTag === 'FILE' && !empty($this->currentRecord['files'])) {
                    $idx = count($this->currentRecord['files']) - 1;
                    $this->currentRecord['files'][$idx]['title'] = $value;
                }
                $this->currentRecord['title'] = $value;
                break;

            case 'CROP':
                // GEDCOM 7.0: Image cropping
                $this->currentRecord['crop'] = [];
                break;

            case 'TOP':
                if ($parentTag === 'CROP') {
                    $this->currentRecord['crop']['top'] = (int) $value;
                }
                break;

            case 'LEFT':
                if ($parentTag === 'CROP') {
                    $this->currentRecord['crop']['left'] = (int) $value;
                }
                break;

            case 'HEIGHT':
                if ($parentTag === 'CROP') {
                    $this->currentRecord['crop']['height'] = (int) $value;
                }
                break;

            case 'WIDTH':
                if ($parentTag === 'CROP') {
                    $this->currentRecord['crop']['width'] = (int) $value;
                }
                break;

            case 'TRAN':
                // GEDCOM 7.0: Translation/transformation
                $this->currentRecord['translations'][] = ['text' => $value, 'lang' => '', 'mime' => ''];
                break;

            case 'DATE':
            case '_DATE':
                $this->currentRecord['media_date'] = $value;
                break;

            case '_TEXT':
            case 'NOTE':
                $this->currentRecord['description'] .= $value . "\n";
                break;

            case 'UID':
                $this->currentRecord['uid'] = $value;
                break;

            case 'EXID':
                $this->currentRecord['external_ids'][] = ['value' => $value, 'type' => ''];
                break;

            case 'CONC':
            case 'CONT':
                $separator = ($tag === 'CONT') ? "\n" : '';
                $this->currentRecord['description'] .= $separator . $value;
                break;
        }
    }

    /**
     * Process source (SOUR) fields
     */
    protected function processSourceField(string $tag, string $value, ?string $parentTag, int $level): void
    {
        switch ($tag) {
            case 'AUTH':
                $this->currentRecord['author'] = $value;
                break;

            case 'TITL':
                $this->currentRecord['title'] = $value;
                break;

            case 'PUBL':
                $this->currentRecord['publication'] = $value;
                break;

            case 'REPO':
                $this->currentRecord['repository_id'] = $value;
                break;

            case 'NOTE':
                if (!empty($value)) {
                    $this->currentRecord['notes'] .= $value . "\n";
                }
                break;

            case 'SNOTE':
                $this->currentRecord['shared_note_refs'][] = $value;
                break;

            case 'UID':
                $this->currentRecord['uid'] = $value;
                break;

            case 'EXID':
                $this->currentRecord['external_ids'][] = ['value' => $value, 'type' => ''];
                break;

            case 'CONC':
            case 'CONT':
                if ($parentTag === 'NOTE') {
                    $separator = ($tag === 'CONT') ? "\n" : '';
                    $this->currentRecord['notes'] .= $separator . $value;
                } else {
                    $separator = ($tag === 'CONT') ? "\n" : '';
                    $this->currentRecord['publication'] .= $separator . $value;
                }
                break;
        }
    }

    /**
     * Process repository (REPO) fields
     */
    protected function processRepositoryField(string $tag, string $value, ?string $parentTag): void
    {
        switch ($tag) {
            case 'NAME':
                $this->currentRecord['name'] = $value;
                break;

            case 'ADDR':
                $this->currentRecord['address'] = $value;
                break;

            case 'WWW':
                $this->currentRecord['www'] = $value;
                break;

            case 'EMAIL':
                $this->currentRecord['email'] = $value;
                break;

            case 'PHON':
                $this->currentRecord['phone'] = $value;
                break;

            case 'UID':
                $this->currentRecord['uid'] = $value;
                break;

            case 'EXID':
                $this->currentRecord['external_ids'][] = ['value' => $value, 'type' => ''];
                break;

            case 'CONT':
                $this->currentRecord['address'] .= "\n" . $value;
                break;
        }
    }

    /**
     * Process shared note (SNOTE) fields - GEDCOM 7.0 specific
     */
    protected function processSharedNoteField(string $tag, string $value, ?string $parentTag): void
    {
        switch ($tag) {
            case 'CONT':
                $this->currentRecord['text'] .= "\n" . $value;
                break;

            case 'CONC':
                $this->currentRecord['text'] .= $value;
                break;

            case 'MIME':
                $this->currentRecord['mime_type'] = $value;
                break;

            case 'LANG':
                $this->currentRecord['lang'] = $value;
                break;

            case 'TRAN':
                $this->currentRecord['translations'][] = ['text' => $value, 'lang' => '', 'mime' => ''];
                break;

            case 'SOUR':
                $this->currentRecord['sources'][] = $value;
                break;
        }

        // Handle initial text (payload of SNOTE record)
        if ($tag !== 'CONT' && $tag !== 'CONC' && empty($this->currentRecord['text']) && $parentTag === null) {
            // The text comes as payload on the SNOTE line itself
            // This is already handled in startNewRecord
        }
    }

    /**
     * Save current citation with all collected data
     */
    protected function saveCurrentCitation(): void
    {
        if (!$this->currentSourceCitation) {
            return;
        }

        $urls = [];

        if ($this->currentSourceCitationPage) {
            if (preg_match('/(https?:\/\/(?:www\.)?familysearch\.org\/ark:\/\S+)/', $this->currentSourceCitationPage, $m)) {
                $urls['familysearch'] = $m[1];
            }
            if (preg_match('/record\s*ID\s*(\d+)/i', $this->currentSourceCitationPage, $m)) {
                $urls['findagrave_id'] = $m[1];
            }
            if (preg_match('/(https?:\/\/[^\s<>]+)/', $this->currentSourceCitationPage, $m)) {
                $urls['page_url'] = $m[1];
            }
        }

        if ($this->currentSourceCitationLink) {
            $urls['direct_link'] = $this->currentSourceCitationLink;
        }

        if ($this->currentSourceCitationNote && preg_match('/(https?:\/\/[^\s]+)/', $this->currentSourceCitationNote, $m)) {
            $urls['note_url'] = $m[1];
        }

        if (!empty($urls) || $this->currentSourceCitationPage) {
            $this->sourceCitations[] = [
                'source_gedcom_id' => $this->currentSourceCitation,
                'person_gedcom_id' => $this->currentRecord['gedcom_id'] ?? null,
                'page' => $this->currentSourceCitationPage,
                'text' => $this->currentSourceCitationText,
                'link' => $this->currentSourceCitationLink,
                'note' => $this->currentSourceCitationNote,
                'urls' => $urls,
            ];
        }
    }

    /**
     * Save current record to appropriate collection
     */
    protected function saveCurrentRecord(): void
    {
        if ($this->currentRecord === null || $this->currentType === null) {
            return;
        }

        // Trim notes and descriptions
        if (isset($this->currentRecord['notes'])) {
            $this->currentRecord['notes'] = trim($this->currentRecord['notes']);
        }
        if (isset($this->currentRecord['description'])) {
            $this->currentRecord['description'] = trim($this->currentRecord['description']);
        }
        if (isset($this->currentRecord['text'])) {
            $this->currentRecord['text'] = trim($this->currentRecord['text']);
        }

        switch ($this->currentType) {
            case 'INDI':
                $this->persons[$this->currentId] = $this->currentRecord;
                break;
            case 'FAM':
                $this->families[$this->currentId] = $this->currentRecord;
                break;
            case 'OBJE':
                $this->media[$this->currentId] = $this->currentRecord;
                break;
            case 'SOUR':
                $this->sources[$this->currentId] = $this->currentRecord;
                break;
            case 'REPO':
                $this->repositories[$this->currentId] = $this->currentRecord;
                break;
            case 'SNOTE':
                $this->sharedNotes[$this->currentId] = $this->currentRecord;
                break;
        }
    }

    /**
     * Resolve shared note references into inline notes
     */
    protected function resolveSharedNoteReferences(): void
    {
        // Expand SNOTE references in persons
        foreach ($this->persons as $id => &$person) {
            if (!empty($person['shared_note_refs'])) {
                foreach ($person['shared_note_refs'] as $noteRef) {
                    if (isset($this->sharedNotes[$noteRef])) {
                        $noteText = $this->sharedNotes[$noteRef]['text'] ?? '';
                        if (!empty($noteText)) {
                            $person['notes'] .= ($person['notes'] ? "\n\n" : '') . $noteText;
                        }
                    }
                }
            }
        }

        // Expand SNOTE references in families
        foreach ($this->families as $id => &$family) {
            if (!empty($family['shared_note_refs'])) {
                foreach ($family['shared_note_refs'] as $noteRef) {
                    if (isset($this->sharedNotes[$noteRef])) {
                        $noteText = $this->sharedNotes[$noteRef]['text'] ?? '';
                        if (!empty($noteText)) {
                            $family['notes'] .= ($family['notes'] ? "\n\n" : '') . $noteText;
                        }
                    }
                }
            }
        }

        // Expand SNOTE references in sources
        foreach ($this->sources as $id => &$source) {
            if (!empty($source['shared_note_refs'])) {
                foreach ($source['shared_note_refs'] as $noteRef) {
                    if (isset($this->sharedNotes[$noteRef])) {
                        $noteText = $this->sharedNotes[$noteRef]['text'] ?? '';
                        if (!empty($noteText)) {
                            $source['notes'] .= ($source['notes'] ? "\n\n" : '') . $noteText;
                        }
                    }
                }
            }
        }
    }

    /**
     * Parse GEDCOM coordinate (N41.409 or W75.6624)
     */
    protected function parseCoordinate(string $value): ?float
    {
        if (empty($value)) {
            return null;
        }

        $direction = strtoupper($value[0]);
        $number = (float) substr($value, 1);

        if (in_array($direction, ['S', 'W'])) {
            $number = -$number;
        }

        return round($number, 6);
    }

    /**
     * Remove UTF-8 BOM from string
     */
    protected function removeBom(string $content): string
    {
        $bom = pack('H*', 'EFBBBF');
        return preg_replace("/^$bom/", '', $content);
    }

    /**
     * Get parsing statistics
     */
    public function getStatistics(): array
    {
        return [
            'file' => basename($this->filePath),
            'lines' => count($this->lines),
            'persons' => count($this->persons),
            'families' => count($this->families),
            'media' => count($this->media),
            'sources' => count($this->sources),
            'repositories' => count($this->repositories),
            'shared_notes' => count($this->sharedNotes),
            'schema_extensions' => count($this->schemaExtensions),
            'source_citation_media' => count($this->sourceCitationMedia),
            'source_citations_with_urls' => count($this->sourceCitations),
            'gedcom_version' => '7.0',
        ];
    }

    /**
     * Get all persons
     */
    public function getPersons(): array
    {
        return $this->persons;
    }

    /**
     * Get all families
     */
    public function getFamilies(): array
    {
        return $this->families;
    }

    /**
     * Get all media
     */
    public function getMedia(): array
    {
        return $this->media;
    }

    /**
     * Get all sources
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * Get all shared notes
     */
    public function getSharedNotes(): array
    {
        return $this->sharedNotes;
    }

    /**
     * Get schema extensions
     */
    public function getSchemaExtensions(): array
    {
        return $this->schemaExtensions;
    }

    /**
     * Get header information
     */
    public function getHeader(): array
    {
        return $this->header;
    }

    /**
     * Detect GEDCOM version from file
     *
     * @param string $filePath Path to GEDCOM file
     * @return string Version string ('5.5.1', '7.0', or 'unknown')
     */
    public static function detectVersion(string $filePath): string
    {
        if (!file_exists($filePath)) {
            return 'unknown';
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return 'unknown';
        }

        $version = 'unknown';
        $lineCount = 0;
        $maxLines = 50; // Check first 50 lines

        while (($line = fgets($handle)) !== false && $lineCount < $maxLines) {
            $lineCount++;
            $line = trim($line);

            // Look for GEDC.VERS
            if (preg_match('/^\d+\s+VERS\s+(.+)$/i', $line, $matches)) {
                $versionStr = trim($matches[1]);
                if (str_starts_with($versionStr, '7.')) {
                    $version = '7.0';
                    break;
                } elseif (str_starts_with($versionStr, '5.5')) {
                    $version = '5.5.1';
                    break;
                } else {
                    $version = $versionStr;
                    break;
                }
            }

            // GEDCOM 7.0 uses SCHMA, which 5.5.1 doesn't have
            if (preg_match('/^\d+\s+SCHMA/i', $line)) {
                $version = '7.0';
                break;
            }
        }

        fclose($handle);
        return $version;
    }
}
