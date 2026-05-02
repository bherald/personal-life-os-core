<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\Log;

/**
 * GedcomParserService - GEDCOM 5.5.1 File Parser with 7.0 Auto-Detection
 *
 * Parses GEDCOM genealogy files into structured PHP arrays.
 * Supports all standard GEDCOM 5.5.1 record types plus Family Tree Maker extensions.
 * Auto-detects GEDCOM 7.0 files and routes to Gedcom7ParserService.
 *
 * Usage:
 *   $parser = new GedcomParserService('/path/to/file.ged');
 *   $result = $parser->parse();
 *   // $result contains: persons, families, media, sources, repositories, header, stats
 *
 * For explicit version control:
 *   $result = GedcomParserService::parseFile('/path/to/file.ged');  // Auto-detect
 *   $result = GedcomParserService::parseFile('/path/to/file.ged', '5.5.1');  // Force 5.5.1
 *   $result = GedcomParserService::parseFile('/path/to/file.ged', '7.0');    // Force 7.0
 *
 * @see https://gedcom.io/specifications/ GEDCOM 5.5.1 Specification
 * @see https://gedcom.io/specifications/FamilySearchGEDCOMv7.html GEDCOM 7.0 Specification
 * @see docs/GEDCOM Analysis Reference Document.md
 */
class GedcomParserService
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
    protected array $header = [];

    // Current parsing state
    protected ?array $currentRecord = null;
    protected ?string $currentId = null;
    protected ?string $currentType = null;
    protected array $contextStack = [];

    // Source citation tracking (for OBJE nested under SOUR)
    protected ?string $currentSourceCitation = null;
    protected ?string $currentSourceCitationPage = null;
    protected ?string $currentSourceCitationLink = null;
    protected ?string $currentSourceCitationNote = null;
    protected ?string $currentSourceCitationText = null;
    protected array $sourceCitationMedia = [];  // Track source-to-media links
    protected array $sourceCitations = [];  // Full citation details with URLs

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * Static factory method to parse a GEDCOM file with auto-detection
     *
     * @param string $filePath Path to GEDCOM file
     * @param string|null $forceVersion Force a specific version ('5.5.1', '7.0', or null for auto)
     * @return array Parsed data normalized to internal format
     * @throws \Exception If file cannot be read or version unknown
     */
    public static function parseFile(string $filePath, ?string $forceVersion = null): array
    {
        $version = $forceVersion ?? self::detectVersion($filePath);

        Log::info('GedcomParser: Version detected', [
            'file' => basename($filePath),
            'version' => $version,
            'forced' => $forceVersion !== null,
        ]);

        if (str_starts_with($version, '7.')) {
            $parser = new Gedcom7ParserService($filePath);
            return $parser->parse();
        }

        // Default to 5.5.1 parser
        $parser = new self($filePath);
        return $parser->parse();
    }

    /**
     * Detect GEDCOM version from file header
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

        $version = '5.5.1'; // Default to 5.5.1 for backward compatibility
        $lineCount = 0;
        $maxLines = 50;

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
                } elseif (str_starts_with($versionStr, '5.')) {
                    $version = '5.5.1'; // Treat older 5.x as 5.5.1
                    break;
                }
            }

            // GEDCOM 7.0 uses SCHMA which 5.5.1 doesn't have
            if (preg_match('/^\d+\s+SCHMA/i', $line)) {
                $version = '7.0';
                break;
            }
        }

        fclose($handle);
        return $version;
    }

    /**
     * Parse the GEDCOM file (5.5.1 format)
     *
     * Note: For auto-detection of GEDCOM 7.0, use the static parseFile() method instead.
     *
     * @return array Parsed data with persons, families, media, sources, header, stats
     * @throws \Exception If file cannot be read
     */
    public function parse(): array
    {
        if (!file_exists($this->filePath)) {
            throw new \Exception("GEDCOM file not found: {$this->filePath}");
        }

        // Check if this is actually a 7.0 file and log a warning
        $version = self::detectVersion($this->filePath);
        if (str_starts_with($version, '7.')) {
            Log::warning('GedcomParser: GEDCOM 7.0 file passed to 5.5.1 parser. Consider using parseFile() for auto-detection.', [
                'file' => basename($this->filePath),
            ]);
        }

        // Read file with UTF-8 BOM handling
        $content = file_get_contents($this->filePath);
        $content = $this->removeBom($content);
        $this->lines = explode("\n", $content);

        Log::info('GedcomParser: Starting parse', [
            'file' => basename($this->filePath),
            'lines' => count($this->lines)
        ]);

        // Parse all lines
        foreach ($this->lines as $lineNum => $line) {
            $this->currentLine = $lineNum + 1;
            $this->parseLine(rtrim($line, "\r\n"));
        }

        // Save final record
        $this->saveCurrentRecord();

        $stats = $this->getStatistics();
        Log::info('GedcomParser: Parse complete', $stats);

        return [
            'header' => $this->header,
            'persons' => $this->persons,
            'families' => $this->families,
            'media' => $this->media,
            'sources' => $this->sources,
            'repositories' => $this->repositories,
            'source_citation_media' => $this->sourceCitationMedia,  // Links between sources and media via citations
            'source_citations' => $this->sourceCitations,  // Full citation details with URLs for media download
            'stats' => $stats,
        ];
    }

    /**
     * Parse a single line
     */
    protected function parseLine(string $line): void
    {
        if (empty(trim($line))) {
            return;
        }

        // Parse level and content: "0 @I1@ INDI" or "1 NAME John /Doe/"
        if (!preg_match('/^(\d+)\s+(.*)$/', $line, $matches)) {
            return;
        }

        $level = (int) $matches[1];
        $content = $matches[2];

        // Level 0 starts new records
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

        // Check for HEAD record
        if ($content === 'HEAD') {
            $this->currentRecord = [];
            $this->currentType = 'HEAD';
            return;
        }

        // Check for TRLR (trailer)
        if ($content === 'TRLR') {
            return;
        }

        // Parse record with ID: @I123@ INDI
        if (preg_match('/^@([^@]+)@\s+(\w+)$/', $content, $matches)) {
            $this->currentId = $matches[1];
            $this->currentType = $matches[2];

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
                case 'SUBM':
                    // Submitter record - can be ignored or stored in header
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

        // Parse tag and value: "NAME John /Doe/" or "DATE 1 JAN 1900"
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
            // Save and reset source citation when leaving SOUR context
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

        // Get parent context
        $parentTag = count($this->contextStack) > 1
            ? $this->contextStack[count($this->contextStack) - 2][1]
            : null;

        // Process based on record type
        switch ($this->currentType) {
            case 'HEAD':
                $this->processHeaderField($tag, $value, $parentTag);
                break;
            case 'INDI':
                $this->processPersonField($tag, $value, $parentTag, $level);
                break;
            case 'FAM':
                $this->processFamilyField($tag, $value, $parentTag);
                break;
            case 'OBJE':
                $this->processMediaField($tag, $value, $parentTag);
                break;
            case 'SOUR':
                $this->processSourceField($tag, $value, $parentTag);
                break;
            case 'REPO':
                $this->processRepositoryField($tag, $value, $parentTag);
                break;
        }
    }

    /**
     * Create new person record structure
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
        ];
    }

    /**
     * Create new media record structure
     */
    protected function newMediaRecord(string $id): array
    {
        return [
            'gedcom_id' => $id,
            'file_path' => '',
            'file_format' => '',
            'title' => '',
            'media_date' => '',
            'description' => '',
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
        ];
    }

    /**
     * Process header fields
     */
    protected function processHeaderField(string $tag, string $value, ?string $parentTag): void
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
            case 'CHAR':
                $this->header['charset'] = $value;
                break;
            case 'DATE':
                $this->header['export_date'] = $value;
                break;
            case 'FILE':
                $this->header['source_file'] = $value;
                break;
        }
    }

    /**
     * Process person (INDI) fields
     */
    protected function processPersonField(string $tag, string $value, ?string $parentTag, int $level): void
    {
        switch ($tag) {
            case 'NAME':
                if ($parentTag === 'INDI' || $level === 1) {
                    $this->currentRecord['name'] = $value;
                    // Extract surname from /Surname/
                    if (preg_match('/\/([^\/]*)\//', $value, $matches)) {
                        $this->currentRecord['surname'] = $matches[1];
                        $beforeSurname = trim(preg_split('/\//', $value)[0]);
                        // Check for nickname in parentheses: John (Jack) => Jack is nickname
                        if (preg_match('/^([^(]+)\s*\(([^)]+)\)/', $beforeSurname, $nickMatch)) {
                            $this->currentRecord['given_name'] = trim($nickMatch[1]);
                            $this->currentRecord['nickname'] = trim($nickMatch[2]);
                        } else {
                            $this->currentRecord['given_name'] = $beforeSurname;
                        }
                    }
                    // Check for suffix
                    if (preg_match('/\/[^\/]*\/\s*(.+)$/', $value, $suffixMatch)) {
                        $this->currentRecord['suffix'] = trim($suffixMatch[1]);
                    }
                }
                break;

            case 'SEX':
                $this->currentRecord['sex'] = $value;
                break;

            case 'BIRT':
                // Birth event container - data comes in sub-records
                break;

            case 'DEAT':
                // Death event container
                break;

            case 'BURI':
                // Burial event container
                break;

            case 'DATE':
                if ($parentTag === 'BIRT') {
                    $this->currentRecord['birth_date'] = $value;
                } elseif ($parentTag === 'DEAT') {
                    $this->currentRecord['death_date'] = $value;
                } elseif ($parentTag === 'BURI') {
                    $this->currentRecord['burial_date'] = $value;
                } elseif ($parentTag === 'RESI') {
                    // Add to residences
                    $this->addResidence(['date' => $value]);
                }
                break;

            case 'PLAC':
                if ($parentTag === 'BIRT') {
                    $this->currentRecord['birth_place'] = $value;
                } elseif ($parentTag === 'DEAT') {
                    $this->currentRecord['death_place'] = $value;
                } elseif ($parentTag === 'BURI') {
                    $this->currentRecord['burial_place'] = $value;
                } elseif ($parentTag === 'RESI') {
                    $this->addResidence(['place' => $value]);
                }
                break;

            case 'LATI':
                $lat = $this->parseCoordinate($value);
                $this->applyCoordinateToContext('lat', $lat);
                break;

            case 'LONG':
                $lon = $this->parseCoordinate($value);
                $this->applyCoordinateToContext('lon', $lon);
                break;

            case 'RESI':
                // Start new residence - data in sub-records
                $this->currentRecord['residences'][] = ['date' => '', 'place' => '', 'lat' => null, 'lon' => null];
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

            case '_PHOTO':
                $this->currentRecord['primary_photo'] = $value;
                break;

            case 'NOTE':
                if (!empty($value)) {
                    $this->currentRecord['notes'] .= $value . "\n";
                }
                break;

            case 'CONC':
            case 'CONT':
                // Continuation of previous field
                if ($parentTag === 'NOTE') {
                    $separator = ($tag === 'CONT') ? "\n" : '';
                    $this->currentRecord['notes'] .= $separator . $value;
                }
                break;

            case 'SOUR':
                $this->currentRecord['sources'][] = $value;
                // Save previous citation before starting new one
                $this->saveCurrentCitation();
                // Track current source citation for nested OBJE and URLs
                $this->currentSourceCitation = $value;
                $this->currentSourceCitationPage = null;
                $this->currentSourceCitationLink = null;
                $this->currentSourceCitationNote = null;
                $this->currentSourceCitationText = null;
                break;

            case 'PAGE':
                // Citation page info (often contains URLs and record IDs)
                if ($this->currentSourceCitation) {
                    $this->currentSourceCitationPage = $value;
                }
                break;

            case '_LINK':
                // Direct URL link (Family Tree Maker extension)
                if ($this->currentSourceCitation) {
                    $this->currentSourceCitationLink = $value;
                }
                break;

            case 'TEXT':
                // Citation text (under DATA tag)
                if ($this->currentSourceCitation && $parentTag === 'DATA') {
                    $this->currentSourceCitationText = $value;
                }
                break;
        }

        // Capture NOTE under SOUR (often contains URLs)
        if ($tag === 'NOTE' && $this->currentSourceCitation) {
            $inSourceContext = false;
            foreach (array_reverse($this->contextStack) as [$stackLevel, $stackTag]) {
                if ($stackTag === 'SOUR') {
                    $inSourceContext = true;
                    break;
                }
                if ($stackLevel === 1) break;
            }
            if ($inSourceContext && !empty($value)) {
                $this->currentSourceCitationNote = $value;
            }
        }

        // Handle OBJE nested under SOUR (source citation media)
        if ($tag === 'OBJE' && $this->currentSourceCitation) {
            // Check if we're inside a SOUR context
            $inSourceContext = false;
            foreach (array_reverse($this->contextStack) as [$stackLevel, $stackTag]) {
                if ($stackTag === 'SOUR') {
                    $inSourceContext = true;
                    break;
                }
                if ($stackTag === 'INDI' || $stackLevel === 1) {
                    break;
                }
            }

            if ($inSourceContext) {
                // Record this source-media link
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
     * Save current citation with all collected data
     */
    protected function saveCurrentCitation(): void
    {
        if (!$this->currentSourceCitation) {
            return;
        }

        // Extract URLs from various fields
        $urls = [];

        // Check PAGE for URLs (FamilySearch ARKs, FindAGrave record IDs, etc.)
        if ($this->currentSourceCitationPage) {
            // FamilySearch ARK URLs
            if (preg_match('/(https?:\/\/(?:www\.)?familysearch\.org\/ark:\/\S+)/', $this->currentSourceCitationPage, $m)) {
                $urls['familysearch'] = $m[1];
            }
            // FindAGrave record IDs
            if (preg_match('/record\s*ID\s*(\d+)/i', $this->currentSourceCitationPage, $m)) {
                $urls['findagrave_id'] = $m[1];
            }
            // Generic URLs in PAGE
            if (preg_match('/(https?:\/\/[^\s<>]+)/', $this->currentSourceCitationPage, $m)) {
                $urls['page_url'] = $m[1];
            }
            // Ancestry image references
            if (preg_match('/Image:\s*(\d+)/i', $this->currentSourceCitationPage, $m)) {
                $urls['ancestry_image'] = $m[1];
            }
        }

        // Direct _LINK field
        if ($this->currentSourceCitationLink) {
            $urls['direct_link'] = $this->currentSourceCitationLink;
        }

        // NOTE field URLs
        if ($this->currentSourceCitationNote && preg_match('/(https?:\/\/[^\s]+)/', $this->currentSourceCitationNote, $m)) {
            $urls['note_url'] = $m[1];
        }

        // Only save if we have useful data
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
     * Add or update residence in current record
     */
    protected function addResidence(array $data): void
    {
        if (empty($this->currentRecord['residences'])) {
            $this->currentRecord['residences'][] = [
                'date' => '',
                'place' => '',
                'lat' => null,
                'lon' => null
            ];
        }

        $idx = count($this->currentRecord['residences']) - 1;
        foreach ($data as $key => $value) {
            $this->currentRecord['residences'][$idx][$key] = $value;
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
    protected function processFamilyField(string $tag, string $value, ?string $parentTag): void
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

            case '_FREL':
                if (!empty($this->currentRecord['children'])) {
                    $idx = count($this->currentRecord['children']) - 1;
                    $this->currentRecord['children'][$idx]['frel'] = $value;
                }
                break;

            case '_MREL':
                if (!empty($this->currentRecord['children'])) {
                    $idx = count($this->currentRecord['children']) - 1;
                    $this->currentRecord['children'][$idx]['mrel'] = $value;
                }
                break;

            case 'MARR':
                // Marriage event container
                break;

            case 'DIV':
                // Divorce event container
                break;

            case 'ANUL':
                // Annulment container
                break;

            case 'DATE':
                if ($parentTag === 'MARR') {
                    $this->currentRecord['marriage_date'] = $value;
                } elseif ($parentTag === 'DIV') {
                    $this->currentRecord['divorce_date'] = $value;
                } elseif ($parentTag === 'ANUL') {
                    $this->currentRecord['annulment_date'] = $value;
                }
                break;

            case 'PLAC':
                if ($parentTag === 'MARR') {
                    $this->currentRecord['marriage_place'] = $value;
                } elseif ($parentTag === 'DIV') {
                    $this->currentRecord['divorce_place'] = $value;
                }
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
        }
    }

    /**
     * Process media (OBJE) fields
     */
    protected function processMediaField(string $tag, string $value, ?string $parentTag): void
    {
        switch ($tag) {
            case 'FILE':
                $this->currentRecord['file_path'] = $value;
                break;

            case 'FORM':
                $this->currentRecord['file_format'] = strtolower($value);
                break;

            case 'TITL':
                $this->currentRecord['title'] = $value;
                break;

            case '_DATE':
                $this->currentRecord['media_date'] = $value;
                break;

            case '_TEXT':
            case 'NOTE':
                $this->currentRecord['description'] .= $value . "\n";
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
    protected function processSourceField(string $tag, string $value, ?string $parentTag): void
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

            case 'CONC':
            case 'CONT':
                if ($parentTag === 'NOTE') {
                    $separator = ($tag === 'CONT') ? "\n" : '';
                    $this->currentRecord['notes'] .= $separator . $value;
                } else {
                    // Continuation of title or publication
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

            case 'CONT':
                $this->currentRecord['address'] .= "\n" . $value;
                break;
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

        // Trim notes
        if (isset($this->currentRecord['notes'])) {
            $this->currentRecord['notes'] = trim($this->currentRecord['notes']);
        }
        if (isset($this->currentRecord['description'])) {
            $this->currentRecord['description'] = trim($this->currentRecord['description']);
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

        // Format: N41.409 or W75.6624
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
            'source_citation_media' => count($this->sourceCitationMedia),
            'source_citations_with_urls' => count($this->sourceCitations),
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
     * Get header information
     */
    public function getHeader(): array
    {
        return $this->header;
    }

    /**
     * Find person by name
     */
    public function findPersonByName(string $name): array
    {
        $results = [];
        $name = strtolower($name);

        foreach ($this->persons as $id => $person) {
            $fullName = strtolower($person['given_name'] . ' ' . $person['surname']);
            if (str_contains($fullName, $name)) {
                $results[$id] = $person;
            }
        }

        return $results;
    }

    /**
     * Find persons by surname
     */
    public function findPersonsBySurname(string $surname): array
    {
        $results = [];
        $surname = strtolower($surname);

        foreach ($this->persons as $id => $person) {
            if (strtolower($person['surname']) === $surname) {
                $results[$id] = $person;
            }
        }

        return $results;
    }

    /**
     * Validate media file paths exist
     */
    public function validateMediaPaths(string $baseDir = ''): array
    {
        $results = [
            'found' => [],
            'missing' => [],
            'total' => count($this->media)
        ];

        foreach ($this->media as $id => $item) {
            $path = $item['file_path'];

            // Try original path
            if (file_exists($path)) {
                $results['found'][] = [
                    'id' => $id,
                    'path' => $path,
                    'title' => $item['title']
                ];
                continue;
            }

            // Try with base directory
            if (!empty($baseDir)) {
                $filename = basename($path);
                $altPath = rtrim($baseDir, '/') . '/' . $filename;
                if (file_exists($altPath)) {
                    $results['found'][] = [
                        'id' => $id,
                        'path' => $altPath,
                        'original_path' => $path,
                        'title' => $item['title']
                    ];
                    continue;
                }
            }

            $results['missing'][] = [
                'id' => $id,
                'path' => $path,
                'title' => $item['title']
            ];
        }

        return $results;
    }
}
