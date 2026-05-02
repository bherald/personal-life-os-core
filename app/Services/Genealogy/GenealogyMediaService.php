<?php

namespace App\Services\Genealogy;

use App\Services\AIService;
use App\Services\ContentExtractionService;
use App\Services\FaceRegionService;
use App\Services\NextcloudFileApiService;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * E20: Genealogy Media Service
 *
 * Handles media file management for genealogy via Nextcloud.
 * Media files are organized under the configured genealogy Nextcloud root.
 *
 * NOTE: SSH-based Windows import removed 2026-01-10.
 * All media is now accessed via Nextcloud WebDAV API.
 *
 * Features:
 * - Manage media files in Nextcloud for genealogy records
 * - Extract face regions from photos (E23 integration)
 * - Link media to individuals, families, sources, etc.
 *
 * @see docs/future-enhancements.md E20
 */
class GenealogyMediaService
{
    private const HTTP_CONNECT_TIMEOUT = 5;

    private const HTTP_TIMEOUT = 120;

    private NextcloudFileApiService $nextcloudApi;

    private ?FaceRegionService $faceRegionService = null;

    private ?AIService $aiService = null;

    private ?ContentExtractionService $extractionService = null;

    /** Supported media extensions */
    private const SUPPORTED_EXTENSIONS = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'webp'],
        'document' => ['pdf', 'doc', 'docx', 'txt', 'rtf'],
        'audio' => ['mp3', 'wav', 'ogg', 'm4a'],
        'video' => ['mp4', 'avi', 'mov', 'mkv', 'webm'],
    ];

    public function __construct(
        NextcloudFileApiService $nextcloudApi
    ) {
        $this->nextcloudApi = $nextcloudApi;

        // Try to get FaceRegionService if available
        try {
            $this->faceRegionService = app(FaceRegionService::class);
        } catch (Exception $e) {
            // FaceRegionService not available
        }

        // Try to get AIService and ContentExtractionService
        try {
            $this->aiService = app(AIService::class);
            $this->extractionService = app(ContentExtractionService::class);
        } catch (Exception $e) {
            Log::warning('AI/Extraction services not available', ['error' => $e->getMessage()]);
        }
    }

    private function nextcloudHttp(?string $username = null, ?string $password = null): PendingRequest
    {
        return Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT)
            ->timeout(self::HTTP_TIMEOUT)
            ->withBasicAuth((string) $username, (string) $password);
    }

    private function nextcloudBase(): string
    {
        return '/'.trim((string) config('genealogy.nextcloud_root', '/Library/Genealogy'), '/');
    }

    /**
     * Set the face region service
     */
    public function setFaceRegionService(FaceRegionService $service): void
    {
        $this->faceRegionService = $service;
    }

    /**
     * Link existing media files from Nextcloud to genealogy records
     *
     * Scans the tree's Nextcloud folder and links files that match original_path filenames.
     * Files must already exist under the configured genealogy Nextcloud root.
     *
     * @param  int  $treeId  Tree database ID
     * @param  string  $treeName  Tree name for folder organization
     * @return array Import results with success/failure counts
     */
    public function importTreeMedia(int $treeId, string $treeName, ?string $windowsBasePath = null): array
    {
        $results = [
            'success' => true,
            'total' => 0,
            'linked' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
            'linked_files' => [],
        ];

        // Get all media records for this tree that haven't been linked
        $sql = 'SELECT id, gedcom_id, original_path, nextcloud_path, file_exists, title
                FROM genealogy_media
                WHERE tree_id = ? AND (file_exists = 0 OR nextcloud_path IS NULL)';

        $mediaRecords = DB::select($sql, [$treeId]);
        $results['total'] = count($mediaRecords);

        if ($results['total'] === 0) {
            Log::info("No media to link for tree {$treeId}");

            return $results;
        }

        // Build tree folder path
        $treeFolder = $this->sanitizeFolderName($treeName);
        $nextcloudFolder = $this->nextcloudBase().'/'.$treeFolder;

        // Get list of files already in Nextcloud for this tree
        $existingFiles = $this->listNextcloudMediaFiles($nextcloudFolder);

        foreach ($mediaRecords as $media) {
            try {
                $linkResult = $this->linkMediaFromNextcloud($media, $nextcloudFolder, $existingFiles);

                if ($linkResult['success']) {
                    $results['linked']++;
                    $results['linked_files'][] = [
                        'id' => $media->id,
                        'original' => $media->original_path,
                        'nextcloud' => $linkResult['nextcloud_path'],
                    ];
                } elseif ($linkResult['skipped']) {
                    $results['skipped']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'id' => $media->id,
                        'path' => $media->original_path,
                        'error' => $linkResult['error'],
                    ];
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'id' => $media->id,
                    'path' => $media->original_path,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Update tree statistics
        $this->updateTreeMediaStats($treeId);

        Log::info("Media linking completed for tree {$treeId}", $results);

        return $results;
    }

    /**
     * Link a single media record to an existing Nextcloud file
     */
    private function linkMediaFromNextcloud(object $media, string $nextcloudFolder, array $existingFiles): array
    {
        $originalPath = $media->original_path;

        if (empty($originalPath)) {
            return ['success' => false, 'skipped' => true, 'error' => 'No original path'];
        }

        // Get just the filename from the original path
        $filename = basename(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $originalPath));
        $filenameLower = strtolower($filename);

        // Look for matching file in Nextcloud (case-insensitive)
        $matchedPath = null;
        foreach ($existingFiles as $ncFile) {
            if (strtolower(basename($ncFile)) === $filenameLower) {
                $matchedPath = $ncFile;
                break;
            }
        }

        if (! $matchedPath) {
            return [
                'success' => false,
                'skipped' => false,
                'error' => "File not found in Nextcloud: {$filename}",
            ];
        }

        // Update media record with Nextcloud path
        DB::update('
            UPDATE genealogy_media
            SET nextcloud_path = ?,
                file_exists = 1,
                updated_at = NOW()
            WHERE id = ?
        ', [$matchedPath, $media->id]);

        return [
            'success' => true,
            'nextcloud_path' => $matchedPath,
        ];
    }

    /**
     * List all media files in a Nextcloud folder recursively
     */
    private function listNextcloudMediaFiles(string $folder): array
    {
        $files = [];

        try {
            $listResult = $this->nextcloudApi->listFiles($folder, true);

            if ($listResult['success'] && ! empty($listResult['files'])) {
                foreach ($listResult['files'] as $file) {
                    if (! ($file['is_directory'] ?? false)) {
                        $files[] = $file['path'];
                    }
                }
            }
        } catch (Exception $e) {
            Log::warning("Failed to list Nextcloud folder: {$folder}", ['error' => $e->getMessage()]);
        }

        return $files;
    }

    /**
     * Import a single media file from Windows via SSH
     *
     * @deprecated SSH-based import removed 2026-01-10. Use linkMediaFromNextcloud() instead.
     *
     * @throws \RuntimeException Always throws - method is deprecated
     */
    private function importSingleMedia(
        object $media,
        string $nextcloudFolder,
        ?string $windowsBasePath
    ): array {
        throw new \RuntimeException(
            'SSH-based media import is deprecated. Use linkMediaFromNextcloud() instead.'
        );

        /* Legacy SSH code removed - see git history */
    }

    /**
     * Resolve Windows path from GEDCOM reference
     *
     * Handles path mapping from GEDCOM paths to relative paths that work with
     * the configured WindowsFileService base path.
     */
    private function resolveWindowsPath(string $originalPath, ?string $basePath): ?string
    {
        // Handle various GEDCOM path formats:
        // - Absolute Windows: C:\Photos\image.jpg or a synced-library drive path
        // - Relative: Photos\image.jpg or ./Photos/image.jpg
        // - UNC: \\server\share\path

        // Remove leading ./ or .\
        $originalPath = preg_replace('/^\.[\\/\\\\]/', '', $originalPath);

        // Get the configured Windows base path from env.
        $envBasePath = config('services.windows_file.base_path', '');

        // Extract the last folder name from the configured base path.
        $envBaseFolderName = '';
        if ($envBasePath) {
            $envBasePath = rtrim($envBasePath, '/\\');
            $parts = preg_split('/[\\/\\\\]/', $envBasePath);
            $envBaseFolderName = strtolower(end($parts) ?: '');
        }

        // If path starts with drive letter, extract relative portion
        if (preg_match('/^[A-Za-z]:[\\/\\\\]/', $originalPath)) {
            // Check if path contains a known base folder that matches env base
            // Extract the portion after the configured base folder name.
            if ($envBaseFolderName && preg_match('/[\\/\\\\]'.preg_quote($envBaseFolderName, '/').'[\\/\\\\]/i', $originalPath)) {
                // Extract everything AFTER the matching base folder
                $parts = preg_split('/[\\/\\\\]'.preg_quote($envBaseFolderName, '/').'[\\/\\\\]/i', $originalPath, 2);
                if (count($parts) === 2) {
                    // Return just the path after the configured base folder.
                    return $parts[1];
                }
            }

            // Otherwise, try to use provided base path
            if ($basePath) {
                // Strip drive letter and leading slash
                $relativePart = preg_replace('/^[A-Za-z]:[\\/\\\\]/', '', $originalPath);

                return $relativePart;
            }
        }

        // Already relative path
        if ($basePath) {
            return ltrim($originalPath, '/\\');
        }

        return $originalPath;
    }

    /**
     * Get media type subfolder based on extension
     */
    private function getMediaTypeFolder(string $extension): string
    {
        $extension = strtolower($extension);

        if (in_array($extension, self::SUPPORTED_EXTENSIONS['image'])) {
            return 'photos';
        }
        if (in_array($extension, self::SUPPORTED_EXTENSIONS['document'])) {
            return 'documents';
        }
        if (in_array($extension, self::SUPPORTED_EXTENSIONS['audio'])) {
            return 'audio';
        }
        if (in_array($extension, self::SUPPORTED_EXTENSIONS['video'])) {
            return 'video';
        }

        return 'other';
    }

    /**
     * Check if extension is an image type
     */
    private function isImageExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::SUPPORTED_EXTENSIONS['image']);
    }

    /**
     * Sanitize folder name for filesystem use
     */
    private function sanitizeFolderName(string $name): string
    {
        // Replace unsafe characters
        $name = preg_replace('/[<>:"\/\\|?*]/', '_', $name);
        // Replace multiple spaces/underscores with single
        $name = preg_replace('/[\s_]+/', '_', $name);

        // Trim
        return trim($name, ' _');
    }

    /**
     * Generate unique filename in target folder
     */
    private function generateUniqueFilename(string $folder, string $filename): string
    {
        // Check if file already exists in Nextcloud
        $info = $this->nextcloudApi->getFileInfo($folder.'/'.$filename);

        if (! $info['success'] || empty($info['fileid'])) {
            // File doesn't exist, use original name
            return $filename;
        }

        // Generate unique name
        $pathInfo = pathinfo($filename);
        $baseName = $pathInfo['filename'];
        $extension = $pathInfo['extension'] ?? '';
        $counter = 1;

        do {
            $newFilename = $baseName.'_'.$counter.($extension ? '.'.$extension : '');
            $info = $this->nextcloudApi->getFileInfo($folder.'/'.$newFilename);
            $counter++;
        } while ($info['success'] && ! empty($info['fileid']) && $counter < 100);

        return $newFilename;
    }

    /**
     * Ensure Nextcloud folder exists (create if needed)
     */
    private function ensureNextcloudFolder(string $path): bool
    {
        $baseUrl = rtrim(config('services.nextcloud.url'), '/');
        $username = config('services.nextcloud.username');
        $password = config('services.nextcloud.password');

        $path = '/'.ltrim($path, '/');
        $url = "{$baseUrl}/remote.php/dav/files/{$username}{$path}";

        try {
            // Try MKCOL (create directory)
            $response = $this->nextcloudHttp($username, $password)
                ->send('MKCOL', $url);

            // 201 = created, 405 = already exists
            return in_array($response->status(), [201, 405]);
        } catch (Exception $e) {
            Log::error("Failed to create Nextcloud folder: {$path}", ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Upload file to Nextcloud via WebDAV PUT
     */
    private function uploadToNextcloud(string $localPath, string $nextcloudPath): array
    {
        $baseUrl = rtrim(config('services.nextcloud.url'), '/');
        $username = config('services.nextcloud.username');
        $password = config('services.nextcloud.password');

        $nextcloudPath = '/'.ltrim($nextcloudPath, '/');
        $url = "{$baseUrl}/remote.php/dav/files/{$username}{$nextcloudPath}";

        try {
            $fileContents = file_get_contents($localPath);
            $mimeType = mime_content_type($localPath);

            $response = $this->nextcloudHttp($username, $password)
                ->withHeaders(['Content-Type' => $mimeType])
                ->withBody($fileContents, $mimeType)
                ->put($url);

            if ($response->successful() || $response->status() === 201) {
                return ['success' => true, 'path' => $nextcloudPath];
            }

            return [
                'success' => false,
                'error' => 'Upload failed: HTTP '.$response->status(),
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Store face regions from image metadata into person_media links
     * High-confidence matches (exact, maiden_name, married_name, flexible) are auto-linked
     * Fuzzy matches (nickname, typo, soundex) are queued for human approval
     */
    private function storeFaceRegions(int $mediaId, array $faceRegions): void
    {
        // Get the tree_id for this media
        $media = DB::selectOne('SELECT tree_id FROM genealogy_media WHERE id = ?', [$mediaId]);
        if (! $media) {
            return;
        }

        foreach ($faceRegions as $region) {
            $personName = $region['name'] ?? null;
            if (! $personName) {
                continue;
            }

            // Guard: reject concatenated multi-name values (data quality bug)
            // Each face region should have exactly one person name
            if (str_contains($personName, ',')) {
                Log::warning('GenealogyMediaService: Rejecting concatenated face_name', [
                    'media_id' => $mediaId, 'face_name' => $personName,
                ]);

                continue;
            }

            // Get face region coordinates for queue/link
            $faceRegionData = [
                'x' => $region['x'] ?? null,
                'y' => $region['y'] ?? null,
                'w' => $region['w'] ?? null,
                'h' => $region['h'] ?? null,
            ];

            $match = $this->findPersonByFaceName($personName, $media->tree_id);

            if ($match) {
                $person = $match['person'];
                $matchType = $match['match_type'];
                $confidence = $match['confidence'];
                $details = $match['details'];

                // Check if this fuzzy match needs human approval
                if ($this->matchRequiresApproval($matchType)) {
                    // Queue for approval instead of auto-linking
                    $this->queueFaceMatchForApproval(
                        $media->tree_id,
                        $mediaId,
                        $personName,
                        $person,
                        $matchType,
                        $confidence,
                        $details,
                        $faceRegionData
                    );

                    Log::info('Queued fuzzy face match for approval', [
                        'face_name' => $personName,
                        'suggested_person' => $person->given_name.' '.$person->surname,
                        'match_type' => $matchType,
                        'confidence' => $confidence,
                        'media_id' => $mediaId,
                    ]);

                    continue;
                }

                $fileRegistryFaceId = $this->resolveFileRegistryFaceId($mediaId, $personName, $faceRegionData);
                if ($fileRegistryFaceId !== null) {
                    $bridgeResult = app(FaceLinkBridgeService::class)->syncFaceLink(
                        $fileRegistryFaceId,
                        (int) $person->id,
                        $mediaId
                    );

                    if ($bridgeResult['success'] ?? false) {
                        Log::info('Auto-linked face region to person via bridge', [
                            'person_id' => $person->id,
                            'person_name' => $person->given_name.' '.$person->surname,
                            'media_id' => $mediaId,
                            'file_registry_face_id' => $fileRegistryFaceId,
                            'face_name' => $personName,
                            'match_type' => $matchType,
                            'confidence' => $confidence,
                            'person_media_action' => $bridgeResult['person_media_action'] ?? null,
                        ]);

                        continue;
                    }

                    Log::warning('GenealogyMediaService: Face bridge failed during auto-link; falling back to media link only', [
                        'media_id' => $mediaId,
                        'file_registry_face_id' => $fileRegistryFaceId,
                        'person_id' => $person->id,
                        'error' => $bridgeResult['error'] ?? $bridgeResult['warning'] ?? 'unknown',
                    ]);
                }

                // High-confidence match - auto-link when no file-registry face is available yet
                $existing = DB::selectOne(
                    'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
                    [$person->id, $mediaId]
                );

                if ($existing) {
                    $sql = 'UPDATE genealogy_person_media SET
                                face_region_x = ?,
                                face_region_y = ?,
                                face_region_w = ?,
                                face_region_h = ?,
                                face_confirmed = 1
                            WHERE person_id = ? AND media_id = ?';

                    DB::update($sql, [
                        $faceRegionData['x'],
                        $faceRegionData['y'],
                        $faceRegionData['w'],
                        $faceRegionData['h'],
                        $person->id,
                        $mediaId,
                    ]);
                } else {
                    $sql = 'INSERT INTO genealogy_person_media (
                                person_id, media_id,
                                face_region_x, face_region_y, face_region_w, face_region_h,
                                face_confirmed, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())';

                    DB::insert($sql, [
                        $person->id,
                        $mediaId,
                        $faceRegionData['x'],
                        $faceRegionData['y'],
                        $faceRegionData['w'],
                        $faceRegionData['h'],
                    ]);
                }

                Log::info('Auto-linked face region to person', [
                    'person_id' => $person->id,
                    'person_name' => $person->given_name.' '.$person->surname,
                    'media_id' => $mediaId,
                    'face_name' => $personName,
                    'match_type' => $matchType,
                    'confidence' => $confidence,
                ]);
            } else {
                // No match at all - queue with no suggested person for manual review
                $this->queueFaceMatchForApproval(
                    $media->tree_id,
                    $mediaId,
                    $personName,
                    null,  // No suggested person
                    'no_match',
                    0,
                    ['method' => 'No matching person found in database'],
                    $faceRegionData
                );

                Log::debug('Queued unmatched face for review', [
                    'name' => $personName,
                    'tree_id' => $media->tree_id,
                    'media_id' => $mediaId,
                ]);
            }
        }
    }

    /**
     * Update tree media statistics
     */
    private function updateTreeMediaStats(int $treeId): void
    {
        $sql = 'UPDATE genealogy_trees SET
                    media_count = (SELECT COUNT(*) FROM genealogy_media WHERE tree_id = ?),
                    updated_at = NOW()
                WHERE id = ?';

        DB::update($sql, [$treeId, $treeId]);
    }

    /**
     * Find a person by face name from photo metadata
     * Handles married names, maiden names, middle names, nicknames, and typos
     * Returns match info including type for approval queue decisions
     *
     * @param  string  $faceName  Name from photo metadata
     * @param  int  $treeId  Tree ID
     * @return array|null ['person' => object, 'match_type' => string, 'confidence' => int, 'details' => array]
     */
    private function findPersonByFaceName(string $faceName, int $treeId): ?array
    {
        // Split the name into parts
        $parts = preg_split('/\s+/', trim($faceName));
        if (count($parts) < 2) {
            return null;
        }

        $firstName = $parts[0];
        $lastName = end($parts);
        $middlePart = count($parts) > 2 ? $parts[1] : null;

        // Try 1: Exact match on "given_name surname" - HIGH CONFIDENCE
        $person = DB::selectOne(
            'SELECT id, given_name, surname FROM genealogy_persons
             WHERE tree_id = ? AND given_name LIKE ? AND surname LIKE ?
             LIMIT 1',
            [$treeId, $firstName.'%', $lastName.'%']
        );
        if ($person) {
            return [
                'person' => $person,
                'match_type' => 'exact',
                'confidence' => 100,
                'details' => ['method' => 'Exact first/last name match'],
            ];
        }

        // Try 2: Match on "given_name middle_surname" (maiden name case) - HIGH CONFIDENCE
        if ($middlePart) {
            $person = DB::selectOne(
                'SELECT id, given_name, surname FROM genealogy_persons
                 WHERE tree_id = ? AND given_name LIKE ? AND surname LIKE ?
                 LIMIT 1',
                [$treeId, $firstName.'%', $middlePart.'%']
            );
            if ($person) {
                return [
                    'person' => $person,
                    'match_type' => 'maiden_name',
                    'confidence' => 95,
                    'details' => ['method' => 'Maiden name match', 'maiden_name' => $middlePart],
                ];
            }
        }

        // Try 3: Match by first name married to someone with the last name - HIGH CONFIDENCE
        $person = DB::selectOne(
            'SELECT p.id, p.given_name, p.surname FROM genealogy_persons p
             JOIN genealogy_families f ON p.id = f.wife_id
             JOIN genealogy_persons spouse ON spouse.id = f.husband_id
             WHERE p.tree_id = ?
             AND p.given_name LIKE ?
             AND spouse.surname LIKE ?
             LIMIT 1',
            [$treeId, $firstName.'%', $lastName.'%']
        );
        if ($person) {
            return [
                'person' => $person,
                'match_type' => 'married_name',
                'confidence' => 90,
                'details' => ['method' => 'Married into family with surname', 'married_surname' => $lastName],
            ];
        }

        // Try 4: Also check husband married to wife with that surname - HIGH CONFIDENCE
        $person = DB::selectOne(
            'SELECT p.id, p.given_name, p.surname FROM genealogy_persons p
             JOIN genealogy_families f ON p.id = f.husband_id
             JOIN genealogy_persons spouse ON spouse.id = f.wife_id
             WHERE p.tree_id = ?
             AND p.given_name LIKE ?
             AND spouse.surname LIKE ?
             LIMIT 1',
            [$treeId, $firstName.'%', $lastName.'%']
        );
        if ($person) {
            return [
                'person' => $person,
                'match_type' => 'married_name',
                'confidence' => 90,
                'details' => ['method' => 'Married to person with surname', 'spouse_surname' => $lastName],
            ];
        }

        // Try 5: Flexible match - first name and any of the other parts in surname - MEDIUM CONFIDENCE
        foreach ($parts as $i => $part) {
            if ($i === 0) {
                continue;
            }
            $person = DB::selectOne(
                'SELECT id, given_name, surname FROM genealogy_persons
                 WHERE tree_id = ? AND given_name LIKE ? AND surname LIKE ?
                 LIMIT 1',
                [$treeId, $firstName.'%', $part.'%']
            );
            if ($person) {
                return [
                    'person' => $person,
                    'match_type' => 'flexible',
                    'confidence' => 85,
                    'details' => ['method' => 'Flexible name part match', 'matched_part' => $part],
                ];
            }
        }

        // === FUZZY MATCHES BELOW - REQUIRE APPROVAL ===

        // Try 6: Nickname/alias matching - NEEDS REVIEW
        $formalNames = $this->getNicknameVariants($firstName);
        foreach ($formalNames as $formalName) {
            if ($formalName === $firstName) {
                continue;
            }

            $person = DB::selectOne(
                'SELECT id, given_name, surname FROM genealogy_persons
                 WHERE tree_id = ? AND given_name LIKE ? AND surname LIKE ?
                 LIMIT 1',
                [$treeId, $formalName.'%', $lastName.'%']
            );
            if ($person) {
                return [
                    'person' => $person,
                    'match_type' => 'nickname',
                    'confidence' => 70,
                    'details' => [
                        'method' => 'Nickname expansion',
                        'original_name' => $firstName,
                        'expanded_to' => $formalName,
                    ],
                ];
            }
        }

        // Try 7: Typo correction - NEEDS REVIEW
        $correctedFirstName = $this->correctTypo($firstName);
        if ($correctedFirstName !== $firstName) {
            $person = DB::selectOne(
                'SELECT id, given_name, surname FROM genealogy_persons
                 WHERE tree_id = ? AND given_name LIKE ? AND surname LIKE ?
                 LIMIT 1',
                [$treeId, $correctedFirstName.'%', $lastName.'%']
            );
            if ($person) {
                return [
                    'person' => $person,
                    'match_type' => 'typo',
                    'confidence' => 75,
                    'details' => [
                        'method' => 'Typo correction',
                        'original_name' => $firstName,
                        'corrected_to' => $correctedFirstName,
                    ],
                ];
            }
        }

        // Try 8: SOUNDEX phonetic matching - NEEDS REVIEW
        $person = DB::selectOne(
            'SELECT id, given_name, surname FROM genealogy_persons
             WHERE tree_id = ? AND SOUNDEX(given_name) = SOUNDEX(?) AND surname LIKE ?
             LIMIT 1',
            [$treeId, $firstName, $lastName.'%']
        );
        if ($person) {
            return [
                'person' => $person,
                'match_type' => 'soundex',
                'confidence' => 60,
                'details' => [
                    'method' => 'Phonetic (SOUNDEX) match',
                    'face_name' => $firstName,
                    'db_name' => $person->given_name,
                ],
            ];
        }

        return null;
    }

    /**
     * Check if a match type requires human approval
     */
    private function matchRequiresApproval(string $matchType): bool
    {
        // These match types are fuzzy and need human review
        return in_array($matchType, ['nickname', 'typo', 'soundex', 'levenshtein']);
    }

    /**
     * Queue a fuzzy face match for human approval
     */
    private function queueFaceMatchForApproval(
        int $treeId,
        int $mediaId,
        string $faceName,
        ?object $suggestedPerson,
        string $matchType,
        int $confidence,
        array $details,
        ?array $faceRegion = null
    ): void {
        try {
            // Check if already queued
            $existing = DB::selectOne(
                'SELECT id, status, file_registry_face_id FROM genealogy_face_match_queue
                 WHERE media_id = ? AND face_name = ?',
                [$mediaId, $faceName]
            );
            $fileRegistryFaceId = $this->resolveFileRegistryFaceId($mediaId, $faceName, $faceRegion);

            if ($existing) {
                if (empty($existing->file_registry_face_id) && $fileRegistryFaceId !== null) {
                    DB::update(
                        'UPDATE genealogy_face_match_queue
                         SET file_registry_face_id = ?, updated_at = NOW()
                         WHERE id = ?',
                        [$fileRegistryFaceId, $existing->id]
                    );
                }

                // Already in queue, skip
                return;
            }

            DB::insert(
                "INSERT INTO genealogy_face_match_queue
                 (tree_id, media_id, file_registry_face_id, face_name, suggested_person_id, match_type,
                  confidence_score, face_region, match_details, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
                [
                    $treeId,
                    $mediaId,
                    $fileRegistryFaceId,
                    $faceName,
                    $suggestedPerson?->id,
                    $matchType,
                    $confidence,
                    $faceRegion ? json_encode($faceRegion) : null,
                    json_encode($details),
                ]
            );

            Log::info('Queued fuzzy face match for approval', [
                'media_id' => $mediaId,
                'face_name' => $faceName,
                'file_registry_face_id' => $fileRegistryFaceId,
                'suggested_person_id' => $suggestedPerson?->id,
                'match_type' => $matchType,
                'confidence' => $confidence,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to queue face match for approval', [
                'media_id' => $mediaId,
                'face_name' => $faceName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveFileRegistryFaceId(int $mediaId, string $faceName, ?array $faceRegion = null): ?int
    {
        $media = DB::selectOne(
            'SELECT fr.id as file_registry_id
             FROM genealogy_media gm
             JOIN file_registry fr ON (
                 fr.current_path = gm.nextcloud_path
                 OR fr.original_path = gm.original_path
                 OR fr.current_path = gm.original_path
                 OR fr.original_path = gm.nextcloud_path
             )
             WHERE gm.id = ?
             ORDER BY fr.id
             LIMIT 1',
            [$mediaId]
        );

        if (! $media) {
            return null;
        }

        $faces = DB::select(
            'SELECT id, person_name, region_x, region_y, region_w, region_h
             FROM file_registry_faces
             WHERE file_registry_id = ? AND hidden = 0
             ORDER BY id',
            [$media->file_registry_id]
        );

        if ($faces === []) {
            return null;
        }

        $normalizedName = mb_strtolower(trim($faceName));
        $nameMatches = array_values(array_filter($faces, static function (object $face) use ($normalizedName): bool {
            return mb_strtolower(trim((string) ($face->person_name ?? ''))) === $normalizedName;
        }));

        $candidates = $nameMatches !== [] ? $nameMatches : $faces;

        if ($this->hasCompleteFaceRegion($faceRegion)) {
            $best = $this->closestFaceRegion($candidates, $faceRegion);
            if ($best !== null) {
                return (int) $best->id;
            }
        }

        if (count($nameMatches) === 1) {
            return (int) $nameMatches[0]->id;
        }

        return null;
    }

    private function hasCompleteFaceRegion(?array $faceRegion): bool
    {
        return is_array($faceRegion)
            && array_key_exists('x', $faceRegion)
            && array_key_exists('y', $faceRegion)
            && array_key_exists('w', $faceRegion)
            && array_key_exists('h', $faceRegion)
            && $faceRegion['x'] !== null
            && $faceRegion['y'] !== null
            && $faceRegion['w'] !== null
            && $faceRegion['h'] !== null;
    }

    private function closestFaceRegion(array $faces, array $faceRegion): ?object
    {
        $best = null;
        $bestDistance = null;

        foreach ($faces as $face) {
            if ($face->region_x === null || $face->region_y === null || $face->region_w === null || $face->region_h === null) {
                continue;
            }

            $distance = abs((float) $face->region_x - (float) $faceRegion['x'])
                + abs((float) $face->region_y - (float) $faceRegion['y'])
                + abs((float) $face->region_w - (float) $faceRegion['w'])
                + abs((float) $face->region_h - (float) $faceRegion['h']);

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $face;
            }
        }

        return $bestDistance !== null && $bestDistance <= 0.08 ? $best : null;
    }

    /**
     * Get nickname variants for a given name
     * Maps nicknames to formal names and vice versa
     */
    private function getNicknameVariants(string $name): array
    {
        // Common nickname mappings (nickname => [formal names])
        $nicknameToFormal = [
            'Bill' => ['William'],
            'Billy' => ['William'],
            'Will' => ['William'],
            'Willy' => ['William'],
            'Liam' => ['William'],
            'Bob' => ['Robert'],
            'Bobby' => ['Robert'],
            'Rob' => ['Robert'],
            'Robby' => ['Robert'],
            'Dick' => ['Richard'],
            'Rick' => ['Richard'],
            'Ricky' => ['Richard'],
            'Rich' => ['Richard'],
            'Mike' => ['Michael'],
            'Mikey' => ['Michael'],
            'Jim' => ['James'],
            'Jimmy' => ['James'],
            'Jamie' => ['James'],
            'Jack' => ['John', 'Jackson'],
            'Johnny' => ['John'],
            'Jon' => ['Jonathan', 'John'],
            'Joe' => ['Joseph'],
            'Joey' => ['Joseph'],
            'Tom' => ['Thomas'],
            'Tommy' => ['Thomas'],
            'Dan' => ['Daniel'],
            'Danny' => ['Daniel'],
            'Dave' => ['David'],
            'Davy' => ['David'],
            'Steve' => ['Steven', 'Stephen'],
            'Stevie' => ['Steven', 'Stephen'],
            'Chris' => ['Christopher', 'Christine', 'Christina'],
            'Christy' => ['Christine', 'Christina'],
            'Pat' => ['Patrick', 'Patricia'],
            'Patty' => ['Patricia'],
            'Paddy' => ['Patrick'],
            'Ed' => ['Edward', 'Edwin', 'Edgar'],
            'Eddie' => ['Edward', 'Edwin'],
            'Ted' => ['Edward', 'Theodore'],
            'Teddy' => ['Edward', 'Theodore'],
            'Ned' => ['Edward', 'Edmund'],
            'Sam' => ['Samuel', 'Samantha'],
            'Sammy' => ['Samuel', 'Samantha'],
            'Alex' => ['Alexander', 'Alexandra', 'Alexis'],
            'Al' => ['Albert', 'Alfred', 'Alan'],
            'Bert' => ['Albert', 'Robert', 'Herbert'],
            'Beth' => ['Elizabeth', 'Bethany'],
            'Betty' => ['Elizabeth'],
            'Betsy' => ['Elizabeth'],
            'Liz' => ['Elizabeth'],
            'Lizzy' => ['Elizabeth'],
            'Libby' => ['Elizabeth'],
            'Kate' => ['Katherine', 'Kathryn', 'Catherine'],
            'Katie' => ['Katherine', 'Kathryn', 'Catherine'],
            'Kathy' => ['Katherine', 'Kathryn', 'Catherine'],
            'Cathy' => ['Catherine', 'Katherine'],
            'Sue' => ['Susan', 'Suzanne'],
            'Susie' => ['Susan', 'Suzanne'],
            'Meg' => ['Margaret', 'Megan'],
            'Maggie' => ['Margaret'],
            'Peggy' => ['Margaret'],
            'Margie' => ['Margaret'],
            'Maddie' => ['Madison', 'Madeline'],
            'Jen' => ['Jennifer', 'Jenny'],
            'Jenny' => ['Jennifer'],
            'Jess' => ['Jessica', 'Jesse'],
            'Jessie' => ['Jessica', 'Jesse'],
            'Becky' => ['Rebecca'],
            'Becca' => ['Rebecca'],
            'Abby' => ['Abigail'],
            'Debbie' => ['Deborah', 'Debra'],
            'Deb' => ['Deborah', 'Debra'],
            'Barb' => ['Barbara'],
            'Barbie' => ['Barbara'],
            'Sandy' => ['Sandra', 'Alexander'],
            'Mandy' => ['Amanda'],
            'Andy' => ['Andrew', 'Andrea'],
            'Drew' => ['Andrew'],
            'Matt' => ['Matthew'],
            'Matty' => ['Matthew'],
            'Ben' => ['Benjamin'],
            'Benny' => ['Benjamin'],
            'Nate' => ['Nathan', 'Nathaniel'],
            'Nick' => ['Nicholas'],
            'Nicky' => ['Nicholas'],
            'Tony' => ['Anthony'],
            'Frank' => ['Francis', 'Franklin'],
            'Frankie' => ['Francis', 'Franklin'],
            'Chuck' => ['Charles'],
            'Charlie' => ['Charles'],
            'Charley' => ['Charles'],
            'Hank' => ['Henry'],
            'Harry' => ['Henry', 'Harold'],
            'Hal' => ['Henry', 'Harold'],
            'Wm' => ['William'],
            'Chas' => ['Charles'],
            'Jas' => ['James'],
            'Jno' => ['John'],
            'Thos' => ['Thomas'],
            'Geo' => ['George'],
            'Robt' => ['Robert'],
            'Saml' => ['Samuel'],
        ];

        // Also build reverse mapping (formal => [nicknames])
        $formalToNickname = [];
        foreach ($nicknameToFormal as $nick => $formals) {
            foreach ($formals as $formal) {
                if (! isset($formalToNickname[$formal])) {
                    $formalToNickname[$formal] = [];
                }
                $formalToNickname[$formal][] = $nick;
            }
        }

        $variants = [$name]; // Always include original
        $nameUpper = ucfirst(strtolower($name));

        // If input is a nickname, add formal names
        if (isset($nicknameToFormal[$nameUpper])) {
            $variants = array_merge($variants, $nicknameToFormal[$nameUpper]);
        }

        // If input is a formal name, add nicknames
        if (isset($formalToNickname[$nameUpper])) {
            $variants = array_merge($variants, $formalToNickname[$nameUpper]);
        }

        return array_unique($variants);
    }

    /**
     * Correct common typos in names
     * Returns the corrected name or the original if no correction found
     */
    private function correctTypo(string $name): string
    {
        // Known typo corrections (typo => correct)
        $knownTypos = [
            'Wlliam' => 'William',
            'Wiliam' => 'William',
            'Willam' => 'William',
            'Willliam' => 'William',
            'Micheal' => 'Michael',
            'Michale' => 'Michael',
            'Robret' => 'Robert',
            'Robet' => 'Robert',
            'Jmaes' => 'James',
            'Jame' => 'James',
            'Jonh' => 'John',
            'Joesph' => 'Joseph',
            'Josehp' => 'Joseph',
            'Elizbeth' => 'Elizabeth',
            'Elizaeth' => 'Elizabeth',
            'Margret' => 'Margaret',
            'Margaet' => 'Margaret',
            'Cathrine' => 'Catherine',
            'Cathryn' => 'Kathryn',
            'Anderw' => 'Andrew',
            'Adnrew' => 'Andrew',
            'Thoams' => 'Thomas',
            'Thoms' => 'Thomas',
            'Daneil' => 'Daniel',
            'Dnaiel' => 'Daniel',
            'Benjmain' => 'Benjamin',
            'Benjamn' => 'Benjamin',
            'Christpher' => 'Christopher',
            'Chirstopher' => 'Christopher',
            'Nichoals' => 'Nicholas',
            'Nichols' => 'Nicholas',
            'Richrad' => 'Richard',
            'Ricahrd' => 'Richard',
            'Edwrad' => 'Edward',
            'Edwad' => 'Edward',
            'Georeg' => 'George',
            'Goerge' => 'George',
            'Charle' => 'Charles',
            'Chalres' => 'Charles',
            'Henyr' => 'Henry',
            'Hnery' => 'Henry',
            'Samule' => 'Samuel',
            'Samuell' => 'Samuel',
            'Rebcca' => 'Rebecca',
            'Rebeca' => 'Rebecca',
            'Jesscia' => 'Jessica',
            'Jesica' => 'Jessica',
            'Jennfier' => 'Jennifer',
            'Jenifer' => 'Jennifer',
            'Amand' => 'Amanda',
            'Amanada' => 'Amanda',
            'Sahra' => 'Sarah',
            'Sarha' => 'Sarah',
        ];

        // Check known typos first (case-insensitive)
        $nameKey = ucfirst(strtolower($name));
        if (isset($knownTypos[$nameKey])) {
            return $knownTypos[$nameKey];
        }

        // If no known typo, try to find a close match using Levenshtein
        // Common first names to compare against
        $commonNames = [
            'William', 'Robert', 'James', 'John', 'Michael', 'David', 'Richard',
            'Joseph', 'Thomas', 'Charles', 'Christopher', 'Daniel', 'Matthew',
            'Anthony', 'Mark', 'Donald', 'Steven', 'Paul', 'Andrew', 'Joshua',
            'Kenneth', 'Kevin', 'Brian', 'George', 'Timothy', 'Ronald', 'Edward',
            'Jason', 'Jeffrey', 'Ryan', 'Jacob', 'Gary', 'Nicholas', 'Eric',
            'Jonathan', 'Stephen', 'Larry', 'Justin', 'Scott', 'Brandon', 'Benjamin',
            'Samuel', 'Raymond', 'Gregory', 'Frank', 'Alexander', 'Patrick', 'Henry',
            'Mary', 'Patricia', 'Jennifer', 'Linda', 'Barbara', 'Elizabeth', 'Susan',
            'Jessica', 'Sarah', 'Karen', 'Lisa', 'Nancy', 'Betty', 'Margaret',
            'Sandra', 'Ashley', 'Kimberly', 'Emily', 'Donna', 'Michelle', 'Dorothy',
            'Carol', 'Amanda', 'Melissa', 'Deborah', 'Stephanie', 'Rebecca', 'Sharon',
            'Laura', 'Cynthia', 'Kathleen', 'Amy', 'Angela', 'Shirley', 'Anna',
            'Brenda', 'Pamela', 'Emma', 'Nicole', 'Helen', 'Samantha', 'Katherine',
            'Christine', 'Debra', 'Rachel', 'Carolyn', 'Janet', 'Catherine', 'Maria',
            'Kathryn', 'Tasha', 'Natasha',
        ];

        $bestMatch = $name;
        $bestDistance = 3; // Only consider matches with distance <= 2

        foreach ($commonNames as $commonName) {
            $distance = levenshtein(strtolower($name), strtolower($commonName));
            if ($distance > 0 && $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestMatch = $commonName;
            }
        }

        return $bestMatch;
    }

    /**
     * Get import status for a tree
     */
    public function getImportStatus(int $treeId): array
    {
        $sql = 'SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN file_exists = 1 THEN 1 ELSE 0 END) as imported,
                    SUM(CASE WHEN file_exists = 0 AND original_path IS NOT NULL THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN original_path IS NULL THEN 1 ELSE 0 END) as no_source
                FROM genealogy_media
                WHERE tree_id = ?';

        $stats = DB::selectOne($sql, [$treeId]);

        return [
            'total' => (int) $stats->total,
            'imported' => (int) $stats->imported,
            'pending' => (int) $stats->pending,
            'no_source' => (int) $stats->no_source,
            'percent_complete' => $stats->total > 0
                ? round(($stats->imported / $stats->total) * 100, 1)
                : 100,
        ];
    }

    /**
     * Validate file existence for all genealogy_media records.
     *
     * Checks file_exists=1 records against the configured local filesystem path.
     * If a file is missing: sets file_exists=0 and removes person_media links.
     * The normal pipeline (face-sync, media-consolidate) will re-add recovered files.
     */
    public function validateFileExistence(int $treeId, int $batchSize = 500, bool $dryRun = false): array
    {
        $stats = [
            'checked' => 0,
            'missing' => 0,
            'links_removed' => 0,
            'primary_photos_cleared' => 0,
            'already_missing' => 0,
            'errors' => [],
        ];

        // Process in chunks to avoid memory issues
        $offset = 0;

        while (true) {
            $records = DB::select(
                'SELECT id, nextcloud_path, local_filename
                 FROM genealogy_media
                 WHERE tree_id = ? AND file_exists = 1 AND nextcloud_path IS NOT NULL
                 ORDER BY id
                 LIMIT ? OFFSET ?',
                [$treeId, $batchSize, $offset]
            );

            if (empty($records)) {
                break;
            }

            foreach ($records as $record) {
                $stats['checked']++;

                if (! file_exists($record->nextcloud_path)) {
                    $stats['missing']++;

                    if ($dryRun) {
                        continue;
                    }

                    try {
                        // Mark file as missing
                        DB::update(
                            'UPDATE genealogy_media SET file_exists = 0, updated_at = NOW() WHERE id = ?',
                            [$record->id]
                        );

                        // Clear primary_photo_id references pointing to this media
                        $clearedPrimary = DB::update(
                            'UPDATE genealogy_persons SET primary_photo_id = NULL
                             WHERE tree_id = ? AND primary_photo_id = ?',
                            [$treeId, $record->id]
                        );
                        $stats['primary_photos_cleared'] += $clearedPrimary;

                        // Remove person-media links (they'll be re-created if file returns)
                        $removed = DB::delete(
                            'DELETE FROM genealogy_person_media WHERE media_id = ?',
                            [$record->id]
                        );
                        $stats['links_removed'] += $removed;

                        Log::info('Genealogy media validate: file missing', [
                            'media_id' => $record->id,
                            'path' => $record->nextcloud_path,
                            'links_removed' => $removed,
                        ]);
                    } catch (\Exception $e) {
                        $stats['errors'][] = "Media {$record->id}: {$e->getMessage()}";
                    }
                }
            }

            $offset += $batchSize;
        }

        // Also clean up orphaned person_media links pointing to file_exists=0 media
        if (! $dryRun) {
            $orphaned = DB::delete(
                'DELETE pm FROM genealogy_person_media pm
                 JOIN genealogy_media m ON pm.media_id = m.id
                 WHERE m.tree_id = ? AND m.file_exists = 0',
                [$treeId]
            );
            $stats['links_removed'] += $orphaned;
            if ($orphaned > 0) {
                $stats['already_missing'] = $orphaned;
            }
        }

        return $stats;
    }

    /**
     * Get media files that failed to import
     */
    public function getFailedImports(int $treeId): array
    {
        $sql = 'SELECT id, gedcom_id, original_path, title, created_at
                FROM genealogy_media
                WHERE tree_id = ?
                  AND file_exists = 0
                  AND original_path IS NOT NULL
                ORDER BY created_at';

        return DB::select($sql, [$treeId]);
    }

    /**
     * Retry importing a single media file
     */
    public function retryImport(int $mediaId, ?string $windowsBasePath = null): array
    {
        $sql = 'SELECT m.*, t.name as tree_name
                FROM genealogy_media m
                JOIN genealogy_trees t ON t.id = m.tree_id
                WHERE m.id = ?';

        $media = DB::selectOne($sql, [$mediaId]);

        if (! $media) {
            return ['success' => false, 'error' => 'Media not found'];
        }

        $treeFolder = $this->sanitizeFolderName($media->tree_name);
        $nextcloudFolder = $this->nextcloudBase().'/'.$treeFolder;

        return $this->importSingleMedia($media, $nextcloudFolder, $windowsBasePath);
    }

    /**
     * Get Nextcloud URL for a media file
     */
    public function getMediaUrl(int $mediaId): ?string
    {
        $sql = 'SELECT nextcloud_path FROM genealogy_media WHERE id = ? AND file_exists = 1';
        $media = DB::selectOne($sql, [$mediaId]);

        if (! $media || empty($media->nextcloud_path)) {
            return null;
        }

        // Get direct download URL from Nextcloud
        $fileInfo = $this->nextcloudApi->getFileInfo($media->nextcloud_path);

        if ($fileInfo['success'] && ! empty($fileInfo['fileid'])) {
            $directUrl = $this->nextcloudApi->getDirectDownloadUrl($fileInfo['fileid']);
            if ($directUrl['success']) {
                return $directUrl['url'];
            }
        }

        // Fallback to WebDAV URL
        $baseUrl = rtrim(config('services.nextcloud.url'), '/');
        $username = config('services.nextcloud.username');

        return "{$baseUrl}/remote.php/dav/files/{$username}{$media->nextcloud_path}";
    }

    /**
     * Delete media file from Nextcloud
     */
    public function deleteMedia(int $mediaId): bool
    {
        $sql = 'SELECT nextcloud_path FROM genealogy_media WHERE id = ?';
        $media = DB::selectOne($sql, [$mediaId]);

        if (! $media || empty($media->nextcloud_path)) {
            return true; // Nothing to delete
        }

        $baseUrl = rtrim(config('services.nextcloud.url'), '/');
        $username = config('services.nextcloud.username');
        $password = config('services.nextcloud.password');

        $url = "{$baseUrl}/remote.php/dav/files/{$username}{$media->nextcloud_path}";

        try {
            $response = $this->nextcloudHttp($username, $password)
                ->delete($url);

            if ($response->successful() || $response->status() === 404) {
                // Update database
                $sql = 'UPDATE genealogy_media SET
                            nextcloud_path = NULL,
                            file_exists = 0,
                            updated_at = NOW()
                        WHERE id = ?';
                DB::update($sql, [$mediaId]);

                return true;
            }

            return false;
        } catch (Exception $e) {
            Log::error('Failed to delete media from Nextcloud', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Upload a new media file directly (not from Windows)
     */
    public function uploadMedia(int $treeId, string $treeName, string $localPath, array $metadata = []): array
    {
        if (! file_exists($localPath)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        $filename = $metadata['filename'] ?? basename($localPath);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Prepare Nextcloud path
        $treeFolder = $this->sanitizeFolderName($treeName);
        $mediaTypeFolder = $this->getMediaTypeFolder($extension);
        $genealogyRoot = $this->nextcloudBase();
        $nextcloudFolder = $genealogyRoot.'/'.$treeFolder.'/'.$mediaTypeFolder;

        // Ensure folders exist
        $this->ensureNextcloudFolder($genealogyRoot.'/'.$treeFolder);
        $this->ensureNextcloudFolder($nextcloudFolder);

        // Generate unique filename
        $targetFilename = $this->generateUniqueFilename($nextcloudFolder, $filename);
        $nextcloudPath = $nextcloudFolder.'/'.$targetFilename;

        // Upload to Nextcloud
        $uploadResult = $this->uploadToNextcloud($localPath, $nextcloudPath);

        if (! $uploadResult['success']) {
            return $uploadResult;
        }

        // Get file info
        $mimeType = mime_content_type($localPath);
        $fileSize = filesize($localPath);
        $width = null;
        $height = null;

        if ($this->isImageExtension($extension)) {
            $imageInfo = @getimagesize($localPath);
            if ($imageInfo) {
                $width = $imageInfo[0];
                $height = $imageInfo[1];
            }
        }

        // Extract face regions if applicable
        $hasFaces = false;
        $faceCount = 0;

        if ($this->isImageExtension($extension) && $this->faceRegionService) {
            try {
                $faceRegions = $this->faceRegionService->readFaceRegions($localPath);
                $faceCount = count($faceRegions);
                $hasFaces = $faceCount > 0;
            } catch (Exception $e) {
                // Ignore face extraction errors
            }
        }

        // Generate GEDCOM ID
        $sql = "SELECT gedcom_id FROM genealogy_media
                WHERE tree_id = ? AND gedcom_id LIKE 'M%'
                ORDER BY CAST(SUBSTRING(gedcom_id, 2) AS UNSIGNED) DESC
                LIMIT 1";
        $lastMedia = DB::selectOne($sql, [$treeId]);
        $nextNum = $lastMedia ? ((int) substr($lastMedia->gedcom_id, 1)) + 1 : 1;
        $gedcomId = 'M'.$nextNum;

        // Insert database record
        $sql = 'INSERT INTO genealogy_media (
                    tree_id, gedcom_id, original_path, nextcloud_path, local_filename,
                    file_format, mime_type, file_size, title, media_date, description,
                    media_type, file_exists, imported_at, width, height, has_faces, face_count,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?, ?, ?, ?, NOW(), NOW())';

        $mediaType = $this->getMediaTypeFolder($extension);
        if ($mediaType === 'photos') {
            $mediaType = 'photo';
        }
        if ($mediaType === 'documents') {
            $mediaType = 'document';
        }

        DB::insert($sql, [
            $treeId,
            $gedcomId,
            $metadata['original_path'] ?? $localPath,
            $nextcloudPath,
            $targetFilename,
            $extension,
            $mimeType,
            $fileSize,
            $metadata['title'] ?? pathinfo($filename, PATHINFO_FILENAME),
            $metadata['date'] ?? null,
            $metadata['description'] ?? null,
            $mediaType,
            $width,
            $height,
            $hasFaces ? 1 : 0,
            $faceCount,
        ]);

        $mediaId = (int) DB::getPdo()->lastInsertId();

        // Update tree stats
        $this->updateTreeMediaStats($treeId);

        return [
            'success' => true,
            'media_id' => $mediaId,
            'gedcom_id' => $gedcomId,
            'nextcloud_path' => $nextcloudPath,
            'face_count' => $faceCount,
        ];
    }

    /**
     * Get service status
     *
     * NOTE: SSH-based Windows access removed 2026-01-10
     * All file operations now use Nextcloud WebDAV API
     */
    public function getStatus(): array
    {
        $nextcloudConnected = false;
        $faceRegionAvailable = false;

        // Test Nextcloud connection
        try {
            $info = $this->nextcloudApi->getFileInfo('/');
            $nextcloudConnected = $info['success'] ?? false;
        } catch (Exception $e) {
            // Connection failed
        }

        // Check face region service
        if ($this->faceRegionService) {
            $faceRegionAvailable = $this->faceRegionService->isAvailable();
        }

        return [
            'nextcloud_connected' => $nextcloudConnected,
            'face_region_available' => $faceRegionAvailable,
            'nextcloud_base_folder' => $this->nextcloudBase(),
            'supported_extensions' => self::SUPPORTED_EXTENSIONS,
            'ssh_status' => 'deprecated', // SSH removed 2026-01-10
        ];
    }

    // =========================================================================
    // AI MEDIA ANALYSIS METHODS
    // =========================================================================

    /**
     * Analyze a single media file with AI
     *
     * Downloads from Nextcloud, extracts EXIF, runs AI vision analysis,
     * parses subject tags from AI response, and updates database.
     *
     * @param  int  $mediaId  Media record ID
     * @return array Analysis results
     */
    public function analyzeMedia(int $mediaId): array
    {
        if (! $this->extractionService || ! $this->aiService) {
            return [
                'success' => false,
                'error' => 'AI/Extraction services not available',
            ];
        }

        // Get media record
        $sql = 'SELECT id, tree_id, nextcloud_path, file_format, local_filename, title,
                       analysis_status, file_exists, mime_type
                FROM genealogy_media WHERE id = ?';
        $media = DB::selectOne($sql, [$mediaId]);

        if (! $media) {
            return ['success' => false, 'error' => 'Media not found'];
        }

        if (! $media->file_exists || empty($media->nextcloud_path)) {
            return ['success' => false, 'error' => 'Media file not available in Nextcloud'];
        }

        // Mark as processing
        DB::update(
            "UPDATE genealogy_media SET analysis_status = 'processing', updated_at = NOW() WHERE id = ?",
            [$mediaId]
        );

        try {
            // Download file from Nextcloud to temp location
            $localTempPath = $this->downloadFromNextcloud($media->nextcloud_path);
            if (! $localTempPath) {
                throw new Exception('Failed to download from Nextcloud');
            }

            try {
                // Run content extraction
                $extractionResult = $this->extractionService->extract($localTempPath, [
                    'use_vision' => true,
                    'use_ocr' => true,
                    'extract_faces' => true,
                ]);

                // Process extraction results
                $updateData = $this->processExtractionResults($extractionResult, $media);

                // Generate AI description if vision is available
                if ($this->isImageExtension($media->file_format)) {
                    $aiDescription = $this->generateMediaDescription($localTempPath);
                    if ($aiDescription) {
                        $updateData['ai_description'] = $aiDescription;

                        // Extract subject tags from AI description
                        $subjectTags = $this->extractSubjectTags($aiDescription, $media->title);
                        if (! empty($subjectTags)) {
                            $updateData['subject_tags'] = json_encode($subjectTags);
                        }
                    }
                }

                // Update database with extracted metadata
                $updateData['analysis_status'] = 'completed';
                $updateData['analyzed_at'] = now();
                $updateData['analysis_error'] = null;

                $this->updateMediaMetadata($mediaId, $updateData);

                return [
                    'success' => true,
                    'media_id' => $mediaId,
                    'exif_extracted' => ! empty($updateData['exif_data']),
                    'ai_description' => ! empty($updateData['ai_description']),
                    'subject_tags' => $updateData['subject_tags'] ?? null,
                    'date_taken' => $updateData['date_taken'] ?? null,
                ];

            } finally {
                // Cleanup temp file
                if (file_exists($localTempPath)) {
                    @unlink($localTempPath);
                }
            }

        } catch (Exception $e) {
            Log::error('Media analysis failed', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            DB::update(
                "UPDATE genealogy_media SET analysis_status = 'failed', analysis_error = ?, updated_at = NOW() WHERE id = ?",
                [substr($e->getMessage(), 0, 500), $mediaId]
            );

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Analyze all pending media for a tree
     *
     * @param  int  $treeId  Tree ID
     * @param  int  $limit  Max items to process
     * @return array Results summary
     */
    public function analyzeTreeMedia(int $treeId, int $limit = 100): array
    {
        $sql = "SELECT id FROM genealogy_media
                WHERE tree_id = ?
                  AND file_exists = 1
                  AND analysis_status IN ('pending', 'failed')
                ORDER BY created_at
                LIMIT ?";

        $pendingMedia = DB::select($sql, [$treeId, $limit]);

        $results = [
            'total' => count($pendingMedia),
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($pendingMedia as $media) {
            $result = $this->analyzeMedia($media->id);
            $results['processed']++;

            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'media_id' => $media->id,
                    'error' => $result['error'] ?? 'Unknown error',
                ];
            }
        }

        Log::info('Tree media analysis completed', [
            'tree_id' => $treeId,
            'results' => $results,
        ]);

        return $results;
    }

    /**
     * Get analysis status for a tree
     */
    public function getAnalysisStatus(int $treeId): array
    {
        $sql = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN analysis_status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN analysis_status = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN analysis_status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN analysis_status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN analysis_status = 'skipped' THEN 1 ELSE 0 END) as skipped
                FROM genealogy_media
                WHERE tree_id = ? AND file_exists = 1";

        $stats = DB::selectOne($sql, [$treeId]);

        return [
            'total' => (int) $stats->total,
            'pending' => (int) $stats->pending,
            'processing' => (int) $stats->processing,
            'completed' => (int) $stats->completed,
            'failed' => (int) $stats->failed,
            'skipped' => (int) $stats->skipped,
            'percent_complete' => $stats->total > 0
                ? round((($stats->completed + $stats->skipped) / $stats->total) * 100, 1)
                : 100,
        ];
    }

    /**
     * Download file from Nextcloud to temp location
     */
    private function downloadFromNextcloud(string $nextcloudPath): ?string
    {
        $baseUrl = rtrim(config('services.nextcloud.url'), '/');
        $username = config('services.nextcloud.username');
        $password = config('services.nextcloud.password');

        $nextcloudPath = '/'.ltrim($nextcloudPath, '/');
        $url = "{$baseUrl}/remote.php/dav/files/{$username}{$nextcloudPath}";

        try {
            $response = $this->nextcloudHttp($username, $password)->get($url);

            if ($response->successful()) {
                $extension = pathinfo($nextcloudPath, PATHINFO_EXTENSION);
                $tempPath = storage_path('app/temp/media_'.uniqid().'.'.$extension);

                // Ensure temp directory exists
                $tempDir = dirname($tempPath);
                if (! is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }

                file_put_contents($tempPath, $response->body());

                return $tempPath;
            }

            Log::warning('Nextcloud download failed', [
                'path' => $nextcloudPath,
                'status' => $response->status(),
            ]);

        } catch (Exception $e) {
            Log::error('Nextcloud download error', [
                'path' => $nextcloudPath,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Process extraction results into database-ready format
     */
    private function processExtractionResults(array $extractionResult, object $media): array
    {
        $updateData = [];

        // Process EXIF data if available
        if (! empty($extractionResult['exif'])) {
            $exif = $extractionResult['exif'];
            $updateData['exif_data'] = json_encode($exif);

            // Extract specific EXIF fields
            if (! empty($exif['date_taken'])) {
                try {
                    $dateTaken = $this->parseExifDate($exif['date_taken']);
                    if ($dateTaken) {
                        $updateData['date_taken'] = $dateTaken;
                    }
                } catch (Exception $e) {
                    // Invalid date format
                }
            }

            if (! empty($exif['gps_latitude'])) {
                $updateData['gps_latitude'] = $exif['gps_latitude'];
            }
            if (! empty($exif['gps_longitude'])) {
                $updateData['gps_longitude'] = $exif['gps_longitude'];
            }
            if (! empty($exif['camera_make'])) {
                $updateData['camera_make'] = substr($exif['camera_make'], 0, 100);
            }
            if (! empty($exif['camera_model'])) {
                $updateData['camera_model'] = substr($exif['camera_model'], 0, 100);
            }
        }

        // Process face regions if detected
        if (! empty($extractionResult['faces'])) {
            $this->storeFaceRegions($media->id, $extractionResult['faces']);
        }

        return $updateData;
    }

    /**
     * Parse EXIF date string to database format
     */
    private function parseExifDate(string $dateStr): ?string
    {
        // EXIF date format: "YYYY:MM:DD HH:MM:SS"
        $dateStr = trim($dateStr);

        // Try standard EXIF format
        if (preg_match('/^(\d{4}):(\d{2}):(\d{2})\s+(\d{2}):(\d{2}):(\d{2})/', $dateStr, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
        }

        // Try ISO format
        try {
            $dt = new \DateTime($dateStr);

            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Generate AI description for image
     */
    private function generateMediaDescription(string $filePath): ?string
    {
        if (! $this->aiService || ! $this->aiService->isVisionAvailable()) {
            return null;
        }

        try {
            $imageData = @file_get_contents($filePath);
            if (! $imageData) {
                return null;
            }

            $prompt = <<<'EOT'
Analyze this genealogy/family history photo and provide:
1. A detailed description of what you see (people, setting, occasion, era)
2. Estimated time period based on clothing, photo quality, setting
3. Notable details that could help identify people or places

Be specific about number of people, their apparent ages, relationships if obvious,
and any text visible in the photo (signs, documents, etc.).
EOT;

            $result = $this->aiService->processImage(
                base64_encode($imageData),
                $prompt
            );

            if ($result['success'] && ! empty($result['response'])) {
                return $result['response'];
            }

        } catch (Exception $e) {
            Log::warning('AI description generation failed', [
                'file' => basename($filePath),
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Extract subject tags from AI description
     */
    private function extractSubjectTags(string $description, ?string $title): array
    {
        if (! $this->aiService) {
            return [];
        }

        try {
            $prompt = <<<EOT
Extract subject tags from this genealogy photo description. Return ONLY a JSON array of tags.
Include tags for: people count, relationships, era/decade, occasion, location type, clothing style, photo type.

Description: {$description}
Title: {$title}

Example output: ["portrait", "1920s", "wedding", "formal attire", "group photo", "outdoor"]

Return only the JSON array, no other text:
EOT;

            $result = $this->aiService->process($prompt, ['ai_timeout' => 30]);

            if ($result['success'] && ! empty($result['response'])) {
                $response = trim($result['response']);

                // Extract JSON array from response
                if (preg_match('/\[.*\]/s', $response, $matches)) {
                    $tags = json_decode($matches[0], true);
                    if (is_array($tags)) {
                        // Clean and validate tags
                        return array_values(array_filter(array_map(function ($tag) {
                            $tag = trim($tag);

                            return strlen($tag) > 1 && strlen($tag) < 50 ? strtolower($tag) : null;
                        }, $tags)));
                    }
                }
            }

        } catch (Exception $e) {
            Log::debug('Subject tag extraction failed', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Update media metadata in database
     */
    private function updateMediaMetadata(int $mediaId, array $data): void
    {
        $validFields = [
            'ai_description', 'subject_tags', 'exif_data',
            'date_taken', 'gps_latitude', 'gps_longitude',
            'camera_make', 'camera_model',
            'analysis_status', 'analyzed_at', 'analysis_error',
        ];

        $setClauses = [];
        $params = [];

        foreach ($validFields as $field) {
            if (array_key_exists($field, $data)) {
                $setClauses[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($setClauses)) {
            return;
        }

        $setClauses[] = 'updated_at = NOW()';
        $params[] = $mediaId;

        $sql = 'UPDATE genealogy_media SET '.implode(', ', $setClauses).' WHERE id = ?';
        DB::update($sql, $params);
    }

    /**
     * Reset analysis status for retry
     */
    public function resetAnalysisStatus(int $mediaId): bool
    {
        $affected = DB::update(
            "UPDATE genealogy_media SET analysis_status = 'pending', analysis_error = NULL, updated_at = NOW() WHERE id = ?",
            [$mediaId]
        );

        return $affected > 0;
    }

    /**
     * Bulk reset failed analyses
     */
    public function resetFailedAnalyses(int $treeId): int
    {
        return DB::update(
            "UPDATE genealogy_media SET analysis_status = 'pending', analysis_error = NULL, updated_at = NOW()
             WHERE tree_id = ? AND analysis_status = 'failed'",
            [$treeId]
        );
    }

    // =========================================================================
    // NEXTCLOUD FOLDER SCANNER
    // =========================================================================

    /**
     * Scan a Nextcloud folder for media files and import them
     *
     * Recursively scans a folder in Nextcloud, extracts face regions from photos,
     * and creates genealogy_media records. Can be used to import photos from
     * the configured media folder or any other Nextcloud folder.
     *
     * @param  int  $treeId  Target tree for imported media
     * @param  string  $nextcloudFolder  Folder path in Nextcloud
     * @param  bool  $recursive  Whether to scan subdirectories
     * @return array Scan results
     */
    public function scanNextcloudFolder(int $treeId, string $nextcloudFolder, bool $recursive = true, bool $filterForMatches = false): array
    {
        $results = [
            'success' => true,
            'folder' => $nextcloudFolder,
            'files_found' => 0,
            'imported' => 0,
            'skipped' => 0,
            'filtered' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        try {
            // Get tree info
            $tree = DB::selectOne('SELECT id, name FROM genealogy_trees WHERE id = ?', [$treeId]);
            if (! $tree) {
                return ['success' => false, 'error' => 'Tree not found'];
            }

            // List files in Nextcloud folder
            $files = $this->listNextcloudFolder($nextcloudFolder, $recursive);
            $results['files_found'] = count($files);

            foreach ($files as $file) {
                // If filtering enabled, check if file has person match potential before importing
                if ($filterForMatches) {
                    // Quick check using filename/path only (no metadata yet)
                    $hasMatchPotential = $this->mediaHasPersonMatchPotential(
                        $file['filename'],
                        ['path' => $file['path']], // Limited metadata from file listing
                        $treeId
                    );

                    if (! $hasMatchPotential) {
                        $results['filtered']++;
                        Log::debug('Skipping file without match potential', [
                            'file' => $file['path'],
                            'reason' => 'No surname or family keyword match in filename/path',
                        ]);

                        continue;
                    }
                }

                $importResult = $this->importNextcloudFile($treeId, $tree->name, $file, $filterForMatches);

                if ($importResult['success']) {
                    $results['imported']++;
                } elseif ($importResult['skipped']) {
                    $results['skipped']++;
                } elseif ($importResult['filtered'] ?? false) {
                    $results['filtered']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'path' => $file['path'],
                        'error' => $importResult['error'],
                    ];
                }
            }

            Log::info('Nextcloud folder scan completed', [
                'tree_id' => $treeId,
                'folder' => $nextcloudFolder,
                'results' => $results,
            ]);

        } catch (Exception $e) {
            Log::error('Nextcloud folder scan failed', [
                'folder' => $nextcloudFolder,
                'error' => $e->getMessage(),
            ]);
            $results['success'] = false;
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Scan Nextcloud folder and import ONLY files that have face EXIF metadata
     *
     * This method downloads each image file, checks for XMP-mwg-rs face region data,
     * and only imports files that contain face tags. Face regions are extracted
     * and linked to matching persons in the genealogy database.
     *
     * @param  int  $treeId  Tree database ID
     * @param  string  $nextcloudFolder  Nextcloud folder path to scan
     * @param  bool  $recursive  Whether to scan subfolders
     * @return array Import results
     */
    public function scanNextcloudFolderWithFaces(int $treeId, string $nextcloudFolder, bool $recursive = true): array
    {
        $results = [
            'success' => true,
            'folder' => $nextcloudFolder,
            'files_scanned' => 0,
            'files_with_faces' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'faces_found' => 0,
            'persons_linked' => 0,
            'errors' => [],
        ];

        if (! $this->faceRegionService) {
            return ['success' => false, 'error' => 'FaceRegionService not available'];
        }

        try {
            // Get tree info
            $tree = DB::selectOne('SELECT id, name FROM genealogy_trees WHERE id = ?', [$treeId]);
            if (! $tree) {
                return ['success' => false, 'error' => 'Tree not found'];
            }

            // List files in Nextcloud folder
            $files = $this->listNextcloudFolder($nextcloudFolder, $recursive);

            Log::info('Starting face-only scan', [
                'tree_id' => $treeId,
                'folder' => $nextcloudFolder,
                'files_found' => count($files),
            ]);

            foreach ($files as $file) {
                $results['files_scanned']++;

                // Skip non-image files
                $ext = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
                if (! in_array($ext, self::SUPPORTED_EXTENSIONS['image'])) {
                    continue;
                }

                // Filesystem-first: skip WebDAV when local path available
                $tempPath = sys_get_temp_dir().'/face_check_'.uniqid().'_'.basename($file['path']);
                $localFsPath = $this->nextcloudApi->localPath($file['path']);
                if ($localFsPath) {
                    if (! copy($localFsPath, $tempPath)) {
                        Log::debug('Could not copy local file for face check', ['path' => $file['path']]);

                        continue;
                    }
                } else {
                    $downloadResult = $this->nextcloudApi->downloadFile($file['path']);
                    if (! $downloadResult['success'] || empty($downloadResult['content'])) {
                        Log::debug('Could not download file for face check', ['path' => $file['path']]);

                        continue;
                    }
                    file_put_contents($tempPath, $downloadResult['content']);
                    if (! file_exists($tempPath)) {
                        Log::debug('Could not save temp file for face check', ['path' => $file['path']]);

                        continue;
                    }
                }

                // Check for face regions
                $faceRegions = $this->faceRegionService->readFaceRegions($tempPath);

                // Clean up temp file
                @unlink($tempPath);

                // Skip files without face data
                if (empty($faceRegions)) {
                    continue;
                }

                $results['files_with_faces']++;
                $results['faces_found'] += count($faceRegions);

                Log::info('Found file with faces', [
                    'path' => $file['path'],
                    'face_count' => count($faceRegions),
                    'faces' => array_column($faceRegions, 'name'),
                ]);

                // Check if already imported
                $existingMedia = DB::selectOne(
                    'SELECT id FROM genealogy_media WHERE tree_id = ? AND nextcloud_path = ?',
                    [$treeId, $file['path']]
                );

                if ($existingMedia) {
                    // Update face data on existing record
                    $this->storeFaceRegions($existingMedia->id, $faceRegions);
                    $results['skipped']++;

                    continue;
                }

                // Import the file
                $importResult = $this->importNextcloudFile($treeId, $tree->name, $file);

                if ($importResult['success']) {
                    $results['imported']++;

                    // Get the media ID and store face regions
                    if (isset($importResult['media_id'])) {
                        $this->storeFaceRegions($importResult['media_id'], $faceRegions);

                        // Count linked persons
                        $linkedCount = DB::selectOne(
                            'SELECT COUNT(*) as cnt FROM genealogy_person_media WHERE media_id = ?',
                            [$importResult['media_id']]
                        );
                        $results['persons_linked'] += $linkedCount->cnt ?? 0;
                    }
                } elseif ($importResult['skipped'] ?? false) {
                    $results['skipped']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'path' => $file['path'],
                        'error' => $importResult['error'] ?? 'Unknown error',
                    ];
                }
            }

            Log::info('Face-only scan completed', [
                'tree_id' => $treeId,
                'folder' => $nextcloudFolder,
                'results' => $results,
            ]);

        } catch (Exception $e) {
            Log::error('Face-only scan failed', [
                'folder' => $nextcloudFolder,
                'error' => $e->getMessage(),
            ]);
            $results['success'] = false;
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Scan Nextcloud folder for images with face metadata (async version with progress callback)
     *
     * @param  int  $treeId  Tree ID
     * @param  string  $nextcloudFolder  Folder path
     * @param  bool  $recursive  Scan subfolders
     * @param  callable|null  $progressCallback  Callback for progress updates
     * @return array Import results
     */
    public function scanNextcloudFolderWithFacesAsync(
        int $treeId,
        string $nextcloudFolder,
        bool $recursive = true,
        ?callable $progressCallback = null
    ): array {
        $results = [
            'success' => true,
            'folder' => $nextcloudFolder,
            'files_scanned' => 0,
            'files_with_faces' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'faces_found' => 0,
            'persons_linked' => 0,
            'errors' => [],
        ];

        try {
            $files = $this->listNextcloudFolder($nextcloudFolder, $recursive);
            $totalFiles = count($files);

            Log::info('Starting async face scan', [
                'tree_id' => $treeId,
                'folder' => $nextcloudFolder,
                'files_found' => $totalFiles,
            ]);

            foreach ($files as $index => $file) {
                $results['files_scanned']++;

                // Skip non-image files
                $ext = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
                if (! in_array($ext, self::SUPPORTED_EXTENSIONS['image'])) {
                    continue;
                }

                // Report progress
                if ($progressCallback) {
                    $progressCallback([
                        'files_scanned' => $results['files_scanned'],
                        'files_with_faces' => $results['files_with_faces'],
                        'faces_found' => $results['faces_found'],
                        'imported' => $results['imported'],
                        'skipped' => $results['skipped'],
                        'failed' => $results['failed'],
                        'total_files' => $totalFiles,
                        'percent_complete' => round(($index + 1) / $totalFiles * 100, 1),
                        'current_file' => basename($file['path']),
                    ]);
                }

                // Filesystem-first: skip WebDAV when local path available
                $tempPath = sys_get_temp_dir().'/face_check_'.uniqid().'_'.basename($file['path']);

                try {
                    $localFsPath = $this->nextcloudApi->localPath($file['path']);
                    if ($localFsPath) {
                        if (! copy($localFsPath, $tempPath)) {
                            continue;
                        }
                    } else {
                        $downloadResult = $this->nextcloudApi->downloadFile($file['path']);
                        if (! $downloadResult['success']) {
                            continue;
                        }
                        file_put_contents($tempPath, $downloadResult['content']);
                    }

                    // Extract face regions using FaceRegionService
                    $faceRegions = [];
                    if ($this->faceRegionService) {
                        $faceRegions = $this->faceRegionService->readFaceRegions($tempPath);
                    }

                    if (empty($faceRegions)) {
                        @unlink($tempPath);

                        continue;
                    }

                    // Found faces!
                    $results['files_with_faces']++;
                    $results['faces_found'] += count($faceRegions);

                    // Check if already imported
                    $existing = DB::selectOne(
                        'SELECT id FROM genealogy_media WHERE tree_id = ? AND nextcloud_path = ?',
                        [$treeId, $file['path']]
                    );

                    if ($existing) {
                        $results['skipped']++;
                        @unlink($tempPath);

                        continue;
                    }

                    // Import this file
                    $importResult = $this->importMediaFromNextcloud(
                        $treeId,
                        $file['path'],
                        $tempPath,
                        null,
                        $faceRegions
                    );

                    if ($importResult['success']) {
                        $results['imported']++;
                        $results['persons_linked'] += $importResult['persons_linked'] ?? 0;
                    } else {
                        $results['failed']++;
                        $results['errors'][] = [
                            'path' => $file['path'],
                            'error' => $importResult['error'] ?? 'Unknown error',
                        ];
                    }

                } catch (Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'path' => $file['path'],
                        'error' => $e->getMessage(),
                    ];
                } finally {
                    @unlink($tempPath);
                }
            }

            Log::info('Async face scan completed', [
                'tree_id' => $treeId,
                'folder' => $nextcloudFolder,
                'results' => $results,
            ]);

        } catch (Exception $e) {
            Log::error('Async face scan failed', [
                'folder' => $nextcloudFolder,
                'error' => $e->getMessage(),
            ]);
            $results['success'] = false;
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * List files in a Nextcloud folder via WebDAV PROPFIND
     */
    private function listNextcloudFolder(string $folder, bool $recursive = true): array
    {
        $baseUrl = rtrim(config('services.nextcloud.url'), '/');
        $username = config('services.nextcloud.username');
        $password = config('services.nextcloud.password');

        $folder = '/'.ltrim($folder, '/');
        $url = "{$baseUrl}/remote.php/dav/files/{$username}{$folder}";

        $xml = <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:prop>
    <d:getcontenttype/>
    <d:getcontentlength/>
    <d:getlastmodified/>
    <oc:fileid/>
    <d:resourcetype/>
  </d:prop>
</d:propfind>
XML;

        try {
            $response = Http::connectTimeout(5)->withBasicAuth($username, $password)
                ->withHeaders([
                    'Content-Type' => 'application/xml',
                    'Depth' => $recursive ? 'infinity' : '1',
                ])
                ->timeout(120)
                ->send('PROPFIND', $url, ['body' => $xml]);

            Log::debug('PROPFIND response', [
                'folder' => $folder,
                'status' => $response->status(),
                'body_length' => strlen($response->body()),
            ]);

            if (! $response->successful()) {
                Log::warning('Nextcloud PROPFIND failed', [
                    'folder' => $folder,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $result = $this->parseNextcloudListing($response->body(), $folder);
            Log::debug('parseNextcloudListing returned', ['count' => count($result)]);

            return $result;

        } catch (Exception $e) {
            Log::error('Nextcloud folder list error', [
                'folder' => $folder,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Parse Nextcloud PROPFIND response to get file list
     */
    private function parseNextcloudListing(string $xmlContent, string $baseFolder): array
    {
        $files = [];
        $username = config('services.nextcloud.username');

        Log::debug('parseNextcloudListing called', [
            'xml_length' => strlen($xmlContent),
            'base_folder' => $baseFolder,
            'username' => $username,
            'xml_start' => substr($xmlContent, 0, 200),
        ]);

        try {
            // Ensure no previous errors
            libxml_clear_errors();
            libxml_use_internal_errors(true);

            $xml = @simplexml_load_string($xmlContent);
            if ($xml === false || $xml === null) {
                $errors = libxml_get_errors();
                Log::error('XML parsing failed', [
                    'errors' => array_map(fn ($e) => trim($e->message).' at line '.$e->line, $errors),
                    'xml_start' => substr($xmlContent, 0, 500),
                ]);
                libxml_clear_errors();

                return [];
            }

            $xml->registerXPathNamespace('d', 'DAV:');
            $xml->registerXPathNamespace('oc', 'http://owncloud.org/ns');

            $responses = $xml->xpath('//d:response');
            Log::debug('XPath //d:response result', [
                'count' => is_array($responses) ? count($responses) : 'not array',
                'type' => gettype($responses),
            ]);

            if (! is_array($responses) || empty($responses)) {
                Log::warning('No responses found in XML, trying alternative XPath');
                // Try without namespace prefix
                $responses = $xml->xpath('//*[local-name()="response"]');
                Log::debug('Alternative XPath result', [
                    'count' => is_array($responses) ? count($responses) : 'not array',
                ]);
            }

            $debugCount = 0;
            foreach ($responses as $response) {
                // Use children() with DAV: namespace instead of XPath for better namespace handling
                $davChildren = $response->children('DAV:');
                $href = (string) ($davChildren->href ?? '');

                // Find propstat with 200 OK status
                $propstat = null;
                $prop = null;
                foreach ($davChildren->propstat as $ps) {
                    $status = (string) ($ps->children('DAV:')->status ?? '');
                    if (strpos($status, '200 OK') !== false) {
                        $propstat = $ps;
                        $prop = $ps->children('DAV:')->prop;
                        break;
                    }
                }

                if ($debugCount < 3) {
                    Log::debug('Processing response', [
                        'href' => substr($href, 0, 100),
                        'has_propstat' => $propstat !== null,
                        'has_prop' => $prop !== null,
                    ]);
                }
                $debugCount++;

                if (! $prop) {
                    continue;
                }

                try {
                    // Check if it's a file (not a collection/directory)
                    $propChildren = $prop->children('DAV:');
                    $resourceType = $propChildren->resourcetype;
                    $isDir = isset($resourceType->children('DAV:')->collection);

                    if ($debugCount <= 5) {
                        Log::debug('Directory check', [
                            'href' => substr($href, -60),
                            'is_dir' => $isDir,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Exception in directory check', [
                        'error' => $e->getMessage(),
                        'href' => $href,
                    ]);

                    continue;
                }

                if ($isDir) {
                    continue;
                } // Skip directories

                // After directory check, debug the file processing
                if ($debugCount <= 10) {
                    $extension = strtolower(pathinfo(urldecode($href), PATHINFO_EXTENSION));
                    Log::debug('File check', [
                        'href' => substr($href, -60),
                        'extension' => $extension,
                        'is_supported' => $this->isSupportedExtension($extension),
                    ]);
                }

                // Extract path from href
                $path = urldecode($href);
                $path = preg_replace('#^/remote\.php/dav/files/'.preg_quote($username, '#').'#', '', $path);

                // Only process supported media files
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (! $this->isSupportedExtension($extension)) {
                    continue;
                }

                // Get owncloud namespace properties
                $ocChildren = $prop->children('http://owncloud.org/ns');

                $files[] = [
                    'path' => $path,
                    'filename' => basename($path),
                    'extension' => $extension,
                    'size' => (int) ($propChildren->getcontentlength ?? 0),
                    'mime_type' => (string) ($propChildren->getcontenttype ?? ''),
                    'last_modified' => (string) ($propChildren->getlastmodified ?? ''),
                    'fileid' => (string) ($ocChildren->fileid ?? ''),
                ];
            }

        } catch (Exception $e) {
            Log::warning('Failed to parse Nextcloud listing', ['error' => $e->getMessage()]);
        }

        return $files;
    }

    /**
     * Check if extension is supported
     */
    private function isSupportedExtension(string $extension): bool
    {
        foreach (self::SUPPORTED_EXTENSIONS as $extensions) {
            if (in_array($extension, $extensions)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Import a single file from Nextcloud into genealogy media
     *
     * @param  int  $treeId  Tree ID
     * @param  string  $treeName  Tree name
     * @param  array  $fileInfo  File info from Nextcloud listing
     * @param  bool  $filterForMatches  If true, skip files without surname/family keyword/face matches
     * @return array Import result
     */
    private function importNextcloudFile(int $treeId, string $treeName, array $fileInfo, bool $filterForMatches = false): array
    {
        $nextcloudPath = $fileInfo['path'];
        $filename = $fileInfo['filename'];
        $extension = $fileInfo['extension'];

        // Check if already imported
        $existing = DB::selectOne(
            'SELECT id FROM genealogy_media WHERE tree_id = ? AND nextcloud_path = ?',
            [$treeId, $nextcloudPath]
        );

        if ($existing) {
            return ['success' => false, 'skipped' => true, 'error' => 'Already imported'];
        }

        try {
            // Download to temp file for processing
            $localTempPath = $this->downloadFromNextcloud($nextcloudPath);
            if (! $localTempPath) {
                return ['success' => false, 'skipped' => false, 'error' => 'Download failed'];
            }

            try {
                // Get image dimensions if applicable
                $width = null;
                $height = null;
                if ($this->isImageExtension($extension)) {
                    $imageInfo = @getimagesize($localTempPath);
                    if ($imageInfo) {
                        $width = $imageInfo[0];
                        $height = $imageInfo[1];
                    }
                }

                // Extract face regions if this is an image
                $hasFaces = false;
                $faceCount = 0;
                $faceRegions = [];

                if ($this->isImageExtension($extension) && $this->faceRegionService) {
                    try {
                        $faceRegions = $this->faceRegionService->readFaceRegions($localTempPath);
                        $faceCount = count($faceRegions);
                        $hasFaces = $faceCount > 0;
                    } catch (Exception $e) {
                        Log::debug('Face region extraction failed', [
                            'file' => $filename,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // If filtering for matches, check if file has match potential based on extracted metadata
                if ($filterForMatches && ! $hasFaces) {
                    // No face data - need to check for name/keyword matches
                    // (Face data alone is enough reason to import)
                    $metadata = [
                        'path' => $nextcloudPath,
                        'has_xmp_faces' => $hasFaces,
                    ];

                    // Try to extract EXIF/XMP title if available
                    if ($this->isImageExtension($extension)) {
                        try {
                            $exif = @exif_read_data($localTempPath);
                            if ($exif) {
                                if (! empty($exif['Title'])) {
                                    $metadata['title'] = $exif['Title'];
                                }
                                if (! empty($exif['ImageDescription'])) {
                                    $metadata['description'] = $exif['ImageDescription'];
                                }
                                if (! empty($exif['Subject'])) {
                                    $metadata['subject_tags'] = $exif['Subject'];
                                }
                            }
                        } catch (Exception $e) {
                            // Ignore EXIF errors
                        }
                    }

                    $hasMatchPotential = $this->mediaHasPersonMatchPotential($filename, $metadata, $treeId);
                    if (! $hasMatchPotential) {
                        // Clean up temp file before returning
                        @unlink($localTempPath);

                        return [
                            'success' => false,
                            'skipped' => false,
                            'filtered' => true,
                            'error' => 'No match potential (no surname, family keywords, or face data)',
                        ];
                    }
                }

                // Get MIME type
                $mimeType = $fileInfo['mime_type'] ?: mime_content_type($localTempPath);
                $fileSize = $fileInfo['size'] ?: filesize($localTempPath);

                // Generate GEDCOM ID
                $sql = "SELECT gedcom_id FROM genealogy_media
                        WHERE tree_id = ? AND gedcom_id LIKE 'M%'
                        ORDER BY CAST(SUBSTRING(gedcom_id, 2) AS UNSIGNED) DESC
                        LIMIT 1";
                $lastMedia = DB::selectOne($sql, [$treeId]);
                $nextNum = $lastMedia ? ((int) substr($lastMedia->gedcom_id, 1)) + 1 : 1;
                $gedcomId = 'M'.$nextNum;

                // Determine media type
                $mediaType = $this->getMediaTypeFolder($extension);
                if ($mediaType === 'photos') {
                    $mediaType = 'photo';
                }
                if ($mediaType === 'documents') {
                    $mediaType = 'document';
                }

                // Insert database record
                $sql = "INSERT INTO genealogy_media (
                            tree_id, gedcom_id, original_path, nextcloud_path, local_filename,
                            file_format, mime_type, file_size, title, media_type,
                            file_exists, imported_at, width, height,
                            has_faces, face_count, source_folder,
                            analysis_status, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?, ?, ?, ?, ?, 'pending', NOW(), NOW())";

                // Extract source folder from path
                $sourceFolder = dirname($nextcloudPath);

                DB::insert($sql, [
                    $treeId,
                    $gedcomId,
                    $nextcloudPath, // Use Nextcloud path as original path
                    $nextcloudPath,
                    $filename,
                    $extension,
                    $mimeType,
                    $fileSize,
                    pathinfo($filename, PATHINFO_FILENAME), // Title from filename
                    $mediaType,
                    $width,
                    $height,
                    $hasFaces ? 1 : 0,
                    $faceCount,
                    $sourceFolder,
                ]);

                $mediaId = (int) DB::getPdo()->lastInsertId();

                // Store face regions if found
                if (! empty($faceRegions)) {
                    $this->storeFaceRegions($mediaId, $faceRegions);
                }

                // Update tree stats
                $this->updateTreeMediaStats($treeId);

                return [
                    'success' => true,
                    'skipped' => false,
                    'media_id' => $mediaId,
                    'gedcom_id' => $gedcomId,
                    'face_count' => $faceCount,
                ];

            } finally {
                if (file_exists($localTempPath)) {
                    @unlink($localTempPath);
                }
            }

        } catch (Exception $e) {
            Log::error('Nextcloud file import failed', [
                'path' => $nextcloudPath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'skipped' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Rescan face regions from media files and link to persons
     * Downloads each image from Nextcloud, extracts face regions, and creates person_media links
     */
    public function rescanFaceRegions(int $treeId, int $limit = 100): array
    {
        if (! $this->faceRegionService) {
            return [
                'success' => false,
                'error' => 'FaceRegionService not available',
                'processed' => 0,
                'faces_found' => 0,
                'persons_linked' => 0,
            ];
        }

        // Get photo media files that need face scanning
        $sql = "SELECT id, nextcloud_path, local_filename
                FROM genealogy_media
                WHERE tree_id = ?
                AND file_exists = 1
                AND media_type = 'photo'
                AND nextcloud_path IS NOT NULL
                LIMIT ?";

        $mediaFiles = DB::select($sql, [$treeId, $limit]);

        $processed = 0;
        $facesFound = 0;
        $personsLinked = 0;

        foreach ($mediaFiles as $media) {
            try {
                // Filesystem-first: skip WebDAV when local path available
                $tempDir = sys_get_temp_dir();
                $tempPath = $tempDir.'/face_scan_'.$media->id.'_'.basename($media->local_filename);

                $localFsPath = $this->nextcloudApi->localPath($media->nextcloud_path);
                if ($localFsPath) {
                    if (! copy($localFsPath, $tempPath)) {
                        Log::debug('Failed to copy local file for face scan', ['media_id' => $media->id]);

                        continue;
                    }
                } else {
                    $downloadResult = $this->nextcloudApi->downloadFile($media->nextcloud_path);
                    if (! $downloadResult['success'] || empty($downloadResult['content'])) {
                        Log::debug('Failed to download for face scan', ['media_id' => $media->id]);

                        continue;
                    }
                    file_put_contents($tempPath, $downloadResult['content']);
                }

                // Read face regions
                $faceRegions = $this->faceRegionService->readFaceRegions($tempPath);

                // Update media record
                $faceCount = count($faceRegions);
                DB::update(
                    'UPDATE genealogy_media SET has_faces = ?, face_count = ? WHERE id = ?',
                    [$faceCount > 0 ? 1 : 0, $faceCount, $media->id]
                );

                if ($faceCount > 0) {
                    $facesFound += $faceCount;

                    // Store face regions and link to persons
                    $this->storeFaceRegions($media->id, $faceRegions);

                    // Count how many persons were linked
                    $linkedCount = DB::selectOne(
                        'SELECT COUNT(*) as cnt FROM genealogy_person_media WHERE media_id = ?',
                        [$media->id]
                    );
                    $personsLinked += $linkedCount->cnt ?? 0;
                }

                // Clean up temp file
                @unlink($tempPath);

                $processed++;

            } catch (Exception $e) {
                Log::error('Face scan failed', [
                    'media_id' => $media->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Face rescan completed', [
            'tree_id' => $treeId,
            'processed' => $processed,
            'faces_found' => $facesFound,
            'persons_linked' => $personsLinked,
        ]);

        return [
            'success' => true,
            'processed' => $processed,
            'faces_found' => $facesFound,
            'persons_linked' => $personsLinked,
        ];
    }

    /**
     * Get list of folders in Nextcloud that could contain genealogy media
     *
     * NOTE: Updated 2026-01-10 - genealogy media is rooted by config.
     */
    public function listAvailableNextcloudFolders(): array
    {
        $commonFolders = (array) config('genealogy.media_scan_roots', []);

        $availableFolders = [];

        foreach ($commonFolders as $folder) {
            $info = $this->nextcloudApi->getFileInfo($folder);
            if ($info['success']) {
                $availableFolders[] = [
                    'path' => $folder,
                    'exists' => true,
                ];
            }
        }

        return $availableFolders;
    }

    /**
     * Clean up unlinked media - uses AI to match or delete media that has:
     * - No person links (via genealogy_person_media)
     * - No face data (has_faces = 0 AND face_count = 0)
     *
     * For each media item:
     * 1. Extract metadata (title, filename, path, description, exif_data, subject_tags)
     * 2. Get list of all persons in the tree (given_name, surname, nickname)
     * 3. Use AI to determine if media matches any person with high certainty
     * 4. If match found - create person_media link
     * 5. If no match - delete from DB and Nextcloud
     *
     * @param  int  $treeId  Tree ID to clean up
     * @param  bool  $dryRun  If true, only analyze and report, don't delete
     * @param  int  $limit  Maximum number of media to process (0 = all)
     * @return array Results with linked, deleted, and skipped counts
     */
    public function cleanupUnlinkedMedia(int $treeId, bool $dryRun = true, int $limit = 100): array
    {
        $results = [
            'success' => true,
            'dry_run' => $dryRun,
            'processed' => 0,
            'linked' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'errors' => 0,
            'error_details' => [],
            'matches' => [],
            'deletions' => [],
        ];

        // Get all family surnames for the tree
        $surnamesSql = "SELECT DISTINCT surname FROM genealogy_persons
                        WHERE tree_id = ? AND surname IS NOT NULL AND surname != ''
                        ORDER BY surname";
        $surnamesResult = DB::select($surnamesSql, [$treeId]);
        $surnames = array_map(fn ($r) => $r->surname, $surnamesResult);

        // Get all persons with their names for AI matching
        $personsSql = 'SELECT id, given_name, surname, nickname, birth_date, death_date
                       FROM genealogy_persons
                       WHERE tree_id = ?
                       ORDER BY surname, given_name';
        $persons = DB::select($personsSql, [$treeId]);

        // Build person lookup for AI prompt
        $personList = [];
        foreach ($persons as $p) {
            $name = trim(($p->given_name ?? '').' '.($p->surname ?? ''));
            if (! empty($name)) {
                $personList[] = [
                    'id' => $p->id,
                    'name' => $name,
                    'nickname' => $p->nickname,
                    'birth' => $p->birth_date,
                    'death' => $p->death_date,
                ];
            }
        }

        // Get unlinked media without face data
        $limitClause = $limit > 0 ? "LIMIT {$limit}" : '';
        $mediaSql = "SELECT m.* FROM genealogy_media m
                     WHERE m.tree_id = ?
                       AND m.id NOT IN (SELECT DISTINCT media_id FROM genealogy_person_media)
                       AND (m.has_faces = 0 OR m.has_faces IS NULL)
                       AND (m.face_count = 0 OR m.face_count IS NULL)
                     ORDER BY m.id
                     {$limitClause}";
        $unlinkedMedia = DB::select($mediaSql, [$treeId]);

        Log::info('Starting media cleanup', [
            'tree_id' => $treeId,
            'dry_run' => $dryRun,
            'unlinked_count' => count($unlinkedMedia),
            'persons_count' => count($personList),
            'surnames_count' => count($surnames),
        ]);

        foreach ($unlinkedMedia as $media) {
            $results['processed']++;

            try {
                // First try simple surname matching in filename/path
                $matchedSurname = $this->findSurnameInMedia($media, $surnames);

                if ($matchedSurname) {
                    // Found a surname - use AI to determine which person(s)
                    $aiMatch = $this->aiMatchMediaToPerson($media, $personList, $matchedSurname);

                    if ($aiMatch && $aiMatch['confidence'] >= 0.7) {
                        // High confidence match - link to person
                        if (! $dryRun) {
                            $this->linkMediaToPerson($media->id, $aiMatch['person_id']);
                        }
                        $results['linked']++;
                        $results['matches'][] = [
                            'media_id' => $media->id,
                            'title' => $media->title,
                            'person_id' => $aiMatch['person_id'],
                            'person_name' => $aiMatch['person_name'],
                            'confidence' => $aiMatch['confidence'],
                            'reason' => $aiMatch['reason'],
                        ];

                        continue;
                    }
                }

                // No surname match or low confidence - check if media could still be family-related
                // Look for family-related keywords in title/path
                $hasFamilyIndicator = $this->hasFamilyIndicator($media, $surnames);

                if ($hasFamilyIndicator) {
                    // Skip deletion - might be family-related
                    $results['skipped']++;
                    Log::debug('Skipping media with family indicator', [
                        'media_id' => $media->id,
                        'title' => $media->title,
                    ]);

                    continue;
                }

                // No match found - mark for deletion
                if (! $dryRun) {
                    // Delete from Nextcloud first
                    if (! empty($media->nextcloud_path)) {
                        $this->deleteFromNextcloud($media->nextcloud_path);
                    }

                    // Delete from database
                    DB::delete('DELETE FROM genealogy_media WHERE id = ?', [$media->id]);
                }

                $results['deleted']++;
                $results['deletions'][] = [
                    'media_id' => $media->id,
                    'title' => $media->title,
                    'path' => $media->nextcloud_path ?? $media->original_path,
                ];

            } catch (Exception $e) {
                $results['errors']++;
                $results['error_details'][] = [
                    'media_id' => $media->id,
                    'error' => $e->getMessage(),
                ];
                Log::error('Error processing media for cleanup', [
                    'media_id' => $media->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Media cleanup completed', [
            'tree_id' => $treeId,
            'dry_run' => $dryRun,
            'results' => [
                'processed' => $results['processed'],
                'linked' => $results['linked'],
                'deleted' => $results['deleted'],
                'skipped' => $results['skipped'],
                'errors' => $results['errors'],
            ],
        ]);

        // Update tree media count if we actually deleted records
        if (! $dryRun && $results['deleted'] > 0) {
            $this->updateTreeMediaStats($treeId);
        }

        return $results;
    }

    /**
     * Find a family surname in media title, filename, or path
     */
    private function findSurnameInMedia(object $media, array $surnames): ?string
    {
        $searchText = strtolower(
            ($media->title ?? '').' '.
            ($media->original_path ?? '').' '.
            ($media->nextcloud_path ?? '').' '.
            ($media->description ?? '')
        );

        foreach ($surnames as $surname) {
            if (strlen($surname) >= 3 && stripos($searchText, strtolower($surname)) !== false) {
                return $surname;
            }
        }

        return null;
    }

    /**
     * Check if media has family-related indicators (even without surname match)
     */
    private function hasFamilyIndicator(object $media, array $surnames): bool
    {
        $searchText = strtolower(
            ($media->title ?? '').' '.
            ($media->original_path ?? '').' '.
            ($media->nextcloud_path ?? '')
        );

        // Check for family keywords
        $familyKeywords = [
            'family', 'reunion', 'wedding', 'funeral', 'christmas', 'thanksgiving',
            'birthday', 'anniversary', 'graduation', 'baptism', 'confirmation',
            'homestead', 'grandma', 'grandpa', 'mom', 'dad', 'aunt', 'uncle',
            'cousin', 'brother', 'sister', 'grave', 'headstone', 'cemetery',
        ];

        foreach ($familyKeywords as $keyword) {
            if (stripos($searchText, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Use AI to match media to a specific person based on metadata
     */
    private function aiMatchMediaToPerson(object $media, array $personList, string $matchedSurname): ?array
    {
        if (! $this->aiService) {
            // Fall back to simple matching if AI not available
            return $this->simpleMatchMediaToPerson($media, $personList, $matchedSurname);
        }

        // Filter persons with matching surname
        $relevantPersons = array_filter($personList, function ($p) use ($matchedSurname) {
            return stripos($p['name'], $matchedSurname) !== false;
        });

        if (empty($relevantPersons)) {
            return null;
        }

        // Build AI prompt
        $personListStr = '';
        foreach ($relevantPersons as $p) {
            $dates = '';
            if ($p['birth'] || $p['death']) {
                $dates = ' ('.($p['birth'] ?? '?').' - '.($p['death'] ?? '?').')';
            }
            $nickname = $p['nickname'] ? " aka \"{$p['nickname']}\"" : '';
            $personListStr .= "- ID {$p['id']}: {$p['name']}{$nickname}{$dates}\n";
        }

        $mediaInfo = 'Title: '.($media->title ?? 'Unknown')."\n";
        $mediaInfo .= 'Path: '.($media->original_path ?? $media->nextcloud_path ?? 'Unknown')."\n";
        if ($media->description) {
            $mediaInfo .= "Description: {$media->description}\n";
        }
        if ($media->media_date) {
            $mediaInfo .= "Date: {$media->media_date}\n";
        }

        $prompt = <<<PROMPT
Analyze this genealogy media file and determine which person it likely belongs to.

MEDIA INFO:
{$mediaInfo}

POSSIBLE PERSONS (with surname "{$matchedSurname}"):
{$personListStr}

Based on the media filename, title, and any dates, determine:
1. Which person (by ID) this media most likely belongs to
2. Your confidence level (0.0 to 1.0)
3. Brief reason for your match

Respond in this exact JSON format only, no other text:
{"person_id": 123, "confidence": 0.85, "reason": "Name matches and dates align"}

If you cannot determine a match with at least 50% confidence, respond:
{"person_id": null, "confidence": 0.0, "reason": "Cannot determine match"}
PROMPT;

        try {
            $result = $this->aiService->process($prompt, ['factual_mode' => true]);

            if ($result['success'] && ! empty($result['response'])) {
                // Extract JSON from response
                $response = $result['response'];
                if (preg_match('/\{[^}]+\}/', $response, $matches)) {
                    $parsed = json_decode($matches[0], true);
                    if ($parsed && isset($parsed['person_id']) && $parsed['person_id']) {
                        // Find person name
                        $personName = '';
                        foreach ($personList as $p) {
                            if ($p['id'] == $parsed['person_id']) {
                                $personName = $p['name'];
                                break;
                            }
                        }

                        return [
                            'person_id' => $parsed['person_id'],
                            'person_name' => $personName,
                            'confidence' => floatval($parsed['confidence'] ?? 0.5),
                            'reason' => $parsed['reason'] ?? 'AI match',
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            Log::warning('AI matching failed', ['error' => $e->getMessage()]);
        }

        // Fall back to simple matching
        return $this->simpleMatchMediaToPerson($media, $personList, $matchedSurname);
    }

    /**
     * Simple matching when AI is not available
     */
    private function simpleMatchMediaToPerson(object $media, array $personList, string $matchedSurname): ?array
    {
        $searchText = strtolower(($media->title ?? '').' '.($media->original_path ?? ''));

        // Find persons with matching surname
        $matches = [];
        foreach ($personList as $p) {
            if (stripos($p['name'], $matchedSurname) !== false) {
                // Check if first name also appears
                $firstName = explode(' ', $p['name'])[0] ?? '';
                if (strlen($firstName) >= 3 && stripos($searchText, strtolower($firstName)) !== false) {
                    // Both first and last name match
                    return [
                        'person_id' => $p['id'],
                        'person_name' => $p['name'],
                        'confidence' => 0.8,
                        'reason' => 'Name match in filename',
                    ];
                }
                $matches[] = $p;
            }
        }

        // If only one person has this surname, assign with lower confidence
        if (count($matches) === 1) {
            return [
                'person_id' => $matches[0]['id'],
                'person_name' => $matches[0]['name'],
                'confidence' => 0.6,
                'reason' => 'Only person with surname '.$matchedSurname,
            ];
        }

        return null;
    }

    /**
     * Link media to person
     */
    private function linkMediaToPerson(int $mediaId, int $personId): bool
    {
        try {
            // Check if link already exists
            $exists = DB::selectOne(
                'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
                [$personId, $mediaId]
            );

            if ($exists) {
                return true;
            }

            DB::insert(
                'INSERT INTO genealogy_person_media (person_id, media_id, created_at) VALUES (?, ?, NOW())',
                [$personId, $mediaId]
            );

            return true;
        } catch (Exception $e) {
            Log::error('Failed to link media to person', [
                'media_id' => $mediaId,
                'person_id' => $personId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete file from Nextcloud
     */
    private function deleteFromNextcloud(string $path): bool
    {
        $baseUrl = rtrim(config('services.nextcloud.url'), '/');
        $username = config('services.nextcloud.username');
        $password = config('services.nextcloud.password');

        $url = "{$baseUrl}/remote.php/dav/files/{$username}{$path}";

        try {
            $response = $this->nextcloudHttp($username, $password)->delete($url);

            return $response->successful() || $response->status() === 404;
        } catch (Exception $e) {
            Log::error('Failed to delete from Nextcloud', ['path' => $path, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Check if media filename/path/metadata can potentially match a person in the tree.
     * Used during import to skip media that cannot be matched.
     *
     * @param  string  $filename  The filename or path
     * @param  array  $metadata  Additional metadata (title, description, exif, subject_tags)
     * @param  int  $treeId  Tree ID to check against
     * @return bool True if media has potential to match a person
     */
    public function mediaHasPersonMatchPotential(string $filename, array $metadata, int $treeId): bool
    {
        // Get cached surnames for tree
        static $surnameCache = [];

        if (! isset($surnameCache[$treeId])) {
            $sql = "SELECT DISTINCT LOWER(surname) as surname FROM genealogy_persons
                    WHERE tree_id = ? AND surname IS NOT NULL AND surname != '' AND LENGTH(surname) >= 3";
            $results = DB::select($sql, [$treeId]);
            $surnameCache[$treeId] = array_map(fn ($r) => $r->surname, $results);
        }
        $surnames = $surnameCache[$treeId];

        // Build searchable text
        $searchText = strtolower($filename);
        if (! empty($metadata['title'])) {
            $searchText .= ' '.strtolower($metadata['title']);
        }
        if (! empty($metadata['description'])) {
            $searchText .= ' '.strtolower($metadata['description']);
        }
        if (! empty($metadata['subject_tags'])) {
            $tags = is_array($metadata['subject_tags']) ? $metadata['subject_tags'] : json_decode($metadata['subject_tags'], true);
            if ($tags) {
                $searchText .= ' '.strtolower(implode(' ', $tags));
            }
        }

        // Check for surname match
        foreach ($surnames as $surname) {
            if (stripos($searchText, $surname) !== false) {
                return true;
            }
        }

        // Check for family keywords (might be group photos)
        $familyKeywords = [
            'family', 'reunion', 'wedding', 'funeral', 'christmas', 'thanksgiving',
            'birthday', 'anniversary', 'graduation', 'baptism', 'confirmation',
            'homestead', 'grandma', 'grandpa', 'mom', 'dad', 'aunt', 'uncle',
        ];

        foreach ($familyKeywords as $keyword) {
            if (stripos($searchText, $keyword) !== false) {
                return true;
            }
        }

        // Check if file might contain face data (will be determined during scan)
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'tiff', 'tif'])) {
            // Images might have face data embedded - allow import for face scan
            // Note: This is less restrictive, but face scan will identify later
            if (! empty($metadata['has_xmp_faces'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sync face regions from database back to Nextcloud image files
     * This writes confirmed face tags back to the original images
     *
     * @param  int  $treeId  Tree ID
     * @param  int  $limit  Maximum media to sync per call
     * @return array Sync results with success/failure counts
     */
    public function syncFaceRegionsToNextcloud(int $treeId, int $limit = 50): array
    {
        if (! $this->faceRegionService) {
            return [
                'success' => false,
                'error' => 'FaceRegionService not available',
            ];
        }

        $results = [
            'synced' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        // Get media with confirmed face regions that need sync
        $sql = "SELECT DISTINCT m.id, m.nextcloud_path, m.title
                FROM genealogy_media m
                INNER JOIN genealogy_person_media pm ON pm.media_id = m.id
                INNER JOIN genealogy_persons p ON p.id = pm.person_id
                WHERE m.tree_id = ?
                  AND m.nextcloud_path IS NOT NULL
                  AND pm.face_confirmed = 1
                  AND pm.face_region_x IS NOT NULL
                  AND (m.face_sync_status IS NULL OR m.face_sync_status = 'pending')
                LIMIT ?";

        $mediaItems = DB::select($sql, [$treeId, $limit]);

        if (empty($mediaItems)) {
            return [
                'success' => true,
                'synced' => 0,
                'message' => 'No media items pending face sync',
            ];
        }

        foreach ($mediaItems as $media) {
            try {
                // Get face regions for this media
                $personMediaSql = 'SELECT pm.face_region_x, pm.face_region_y,
                                          pm.face_region_w, pm.face_region_h,
                                          p.given_name, p.surname
                                   FROM genealogy_person_media pm
                                   INNER JOIN genealogy_persons p ON p.id = pm.person_id
                                   WHERE pm.media_id = ?
                                     AND pm.face_confirmed = 1
                                     AND pm.face_region_x IS NOT NULL';

                $personMedia = DB::select($personMediaSql, [$media->id]);

                if (empty($personMedia)) {
                    $results['skipped']++;

                    continue;
                }

                // Download file from Nextcloud
                $tempPath = $this->downloadFromNextcloud($media->nextcloud_path);
                if (! $tempPath) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'media_id' => $media->id,
                        'error' => 'Failed to download from Nextcloud',
                    ];

                    continue;
                }

                try {
                    // Convert to array format
                    $pmArray = array_map(fn ($pm) => (array) $pm, $personMedia);

                    // Write face regions with retry
                    $writeResult = $this->faceRegionService->syncFaceRegionsFromDatabase($tempPath, $pmArray);

                    if ($writeResult['success']) {
                        // Upload modified file back to Nextcloud
                        $uploadSuccess = $this->uploadToNextcloud($tempPath, $media->nextcloud_path);

                        if ($uploadSuccess) {
                            // Mark as synced
                            DB::update(
                                "UPDATE genealogy_media SET face_sync_status = 'synced', face_sync_at = NOW() WHERE id = ?",
                                [$media->id]
                            );
                            $results['synced']++;

                            Log::info('GenealogyMediaService: Face regions synced to Nextcloud', [
                                'media_id' => $media->id,
                                'path' => $media->nextcloud_path,
                                'regions_count' => count($personMedia),
                            ]);
                        } else {
                            $results['failed']++;
                            $results['errors'][] = [
                                'media_id' => $media->id,
                                'error' => 'Failed to upload to Nextcloud',
                            ];
                        }
                    } else {
                        $results['failed']++;
                        $results['errors'][] = [
                            'media_id' => $media->id,
                            'error' => $writeResult['error'] ?? 'Write operation failed',
                        ];

                        // Mark as failed
                        DB::update(
                            "UPDATE genealogy_media SET face_sync_status = 'failed', face_sync_error = ? WHERE id = ?",
                            [$writeResult['error'] ?? 'Write failed', $media->id]
                        );
                    }
                } finally {
                    // Clean up temp file
                    @unlink($tempPath);
                }

            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'media_id' => $media->id,
                    'error' => $e->getMessage(),
                ];

                Log::error('GenealogyMediaService: Face region sync failed', [
                    'media_id' => $media->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $results['success'] = true;
        $results['total_processed'] = $results['synced'] + $results['failed'] + $results['skipped'];

        return $results;
    }

    /**
     * Mark a media item's face regions for sync
     *
     * @param  int  $mediaId  Media ID
     * @return bool Success
     */
    public function markMediaForFaceSync(int $mediaId): bool
    {
        try {
            DB::update(
                "UPDATE genealogy_media SET face_sync_status = 'pending' WHERE id = ?",
                [$mediaId]
            );

            return true;
        } catch (Exception $e) {
            Log::error('Failed to mark media for face sync', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get face sync status for a tree
     *
     * @param  int  $treeId  Tree ID
     * @return array Status counts
     */
    public function getFaceSyncStatus(int $treeId): array
    {
        $sql = "SELECT
                    COUNT(CASE WHEN face_sync_status = 'pending' THEN 1 END) as pending,
                    COUNT(CASE WHEN face_sync_status = 'synced' THEN 1 END) as synced,
                    COUNT(CASE WHEN face_sync_status = 'failed' THEN 1 END) as failed,
                    COUNT(CASE WHEN face_sync_status IS NULL AND EXISTS (
                        SELECT 1 FROM genealogy_person_media pm
                        WHERE pm.media_id = genealogy_media.id
                          AND pm.face_confirmed = 1
                          AND pm.face_region_x IS NOT NULL
                    ) THEN 1 END) as needs_sync
                FROM genealogy_media
                WHERE tree_id = ? AND nextcloud_path IS NOT NULL";

        $result = DB::selectOne($sql, [$treeId]);

        return [
            'pending' => (int) ($result->pending ?? 0),
            'synced' => (int) ($result->synced ?? 0),
            'failed' => (int) ($result->failed ?? 0),
            'needs_sync' => (int) ($result->needs_sync ?? 0),
        ];
    }
}
