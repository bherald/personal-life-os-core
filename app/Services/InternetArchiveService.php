<?php

namespace App\Services;

use App\Services\Genealogy\GenealogyTreeRootResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Internet Archive Integration Service
 *
 * Provides search, metadata, and download capabilities from archive.org.
 * Primary use cases:
 * - Genealogy research (census, family histories, city directories, vital records)
 * - Historical documents and newspapers
 * - General reference materials
 *
 * APIs used:
 * - Advanced Search API (no auth, 10K result limit)
 * - Metadata API (no auth for public items)
 * - Direct download URLs (no auth for public items)
 * - Scraping API (requires S3 keys for unlimited pagination)
 *
 * Uses raw SQL per project standards — NO Eloquent/Query Builder.
 */
class InternetArchiveService
{
    private const BASE_URL = 'https://archive.org';

    private const SEARCH_URL = 'https://archive.org/advancedsearch.php';

    private const METADATA_URL = 'https://archive.org/metadata';

    private const DOWNLOAD_URL = 'https://archive.org/download';

    private const SCRAPE_URL = 'https://archive.org/services/search/v1/scrape';

    private const SEARCH_CACHE_TTL = 3600;   // 1 hour

    private const METADATA_CACHE_TTL = 86400; // 24 hours

    private const REQUEST_DELAY_MS = 1000;    // 1 sec between requests

    /** @var string Base path for downloaded archive.org files */
    private const DOWNLOAD_BASE = 'internet_archive';

    /** @var array Genealogy-relevant collection identifiers */
    private const GENEALOGY_COLLECTIONS = [
        'genealogy',
        'familygenealogy',
        'allen_county',
        '1930_census',
    ];

    /** @var array Genealogy-relevant subject keywords */
    private const GENEALOGY_SUBJECTS = [
        'genealogy', 'family history', 'census', 'vital records',
        'city directory', 'obituary', 'obituaries', 'cemetery',
        'immigration', 'naturalization', 'military records',
        'church records', 'parish registers', 'passenger lists',
        'birth records', 'death records', 'marriage records',
    ];

    private ?string $s3AccessKey = null;

    private ?string $s3SecretKey = null;

    private ?float $lastRequestTime = null;

    private static bool $missingUserAgentContactLogged = false;

    private GenealogyTreeRootResolver $treeRootResolver;

    public function __construct(?GenealogyTreeRootResolver $treeRootResolver = null)
    {
        $this->treeRootResolver = $treeRootResolver ?? app(GenealogyTreeRootResolver::class);
    }

    private function userAgent(string $version = '3.9'): string
    {
        $contact = trim((string) config('services.internet_archive.user_agent_contact', ''));
        if ($contact === '' && ! self::$missingUserAgentContactLogged) {
            self::$missingUserAgentContactLogged = true;
            Log::warning('InternetArchiveService: PLOS_USER_AGENT_CONTACT is empty; archive providers may throttle anonymous clients');
        }

        return $contact !== ''
            ? "PLOS-Framework/{$version} ({$contact})"
            : "PLOS-Framework/{$version}";
    }

    /**
     * Search the Internet Archive.
     *
     * @param  string  $query  Search query (Lucene syntax supported)
     * @param  array  $options  Options:
     *                          - rows: int (default 20, max 100)
     *                          - page: int (default 1)
     *                          - sort: string (default 'relevance')
     *                          - mediatype: string (texts, image, audio, video, collection)
     *                          - collection: string (filter to specific collection)
     *                          - fields: array (metadata fields to return)
     *                          - year_range: array [from, to] (filter by date)
     * @return array Search results
     */
    public function search(string $query, array $options = []): array
    {
        $rows = min($options['rows'] ?? 20, 100);
        $page = $options['page'] ?? 1;
        $sort = $options['sort'] ?? '';
        $mediatype = $options['mediatype'] ?? '';
        $collection = $options['collection'] ?? '';
        $fields = $options['fields'] ?? ['identifier', 'title', 'creator', 'date', 'description', 'mediatype', 'collection', 'downloads', 'subject'];

        // Build query with filters
        $q = $query;
        if ($mediatype) {
            $q .= " AND mediatype:{$mediatype}";
        }
        if ($collection) {
            $q .= " AND collection:{$collection}";
        }
        if (! empty($options['year_range'])) {
            $from = $options['year_range'][0] ?? '*';
            $to = $options['year_range'][1] ?? '*';
            $q .= " AND date:[{$from} TO {$to}]";
        }

        $cacheKey = 'ia_search:'.md5($q.$rows.$page.$sort);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $this->throttle();

        try {
            // Build URL manually because archive.org needs multiple fl[] params
            $queryParts = [
                'q='.urlencode($q),
                'rows='.$rows,
                'page='.$page,
                'output=json',
            ];
            if ($sort) {
                $queryParts[] = 'sort[]='.urlencode($sort);
            }
            foreach ($fields as $field) {
                $queryParts[] = 'fl[]='.urlencode($field);
            }
            $url = self::SEARCH_URL.'?'.implode('&', $queryParts);

            $response = Http::connectTimeout(5)->timeout(30)
                ->withHeaders(['User-Agent' => $this->userAgent()])
                ->get($url);

            if (! $response->successful()) {
                Log::warning('InternetArchiveService: Search failed', [
                    'status' => $response->status(),
                    'query' => $q,
                ]);

                return ['success' => false, 'error' => 'HTTP '.$response->status()];
            }

            $data = $response->json();
            $result = [
                'success' => true,
                'total' => $data['response']['numFound'] ?? 0,
                'page' => $page,
                'rows' => $rows,
                'items' => $data['response']['docs'] ?? [],
            ];

            Cache::put($cacheKey, $result, self::SEARCH_CACHE_TTL);

            return $result;

        } catch (\Exception $e) {
            Log::error('InternetArchiveService: Search error', [
                'query' => $q,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Search specifically within genealogy collections.
     *
     * @param  string  $query  Search terms (person name, place, etc.)
     * @param  array  $options  Additional search options
     * @return array Search results
     */
    public function searchGenealogy(string $query, array $options = []): array
    {
        $collections = implode(' OR ', array_map(fn ($c) => "collection:{$c}", self::GENEALOGY_COLLECTIONS));
        $fullQuery = "({$query}) AND ({$collections})";

        if (! isset($options['mediatype'])) {
            $options['mediatype'] = 'texts';
        }

        return $this->search($fullQuery, $options);
    }

    /**
     * Search for items by subject keywords relevant to genealogy.
     *
     * @param  string  $surname  Family surname to search for
     * @param  string  $location  Optional location context
     * @param  array  $options  Additional options
     * @return array Search results
     */
    public function searchFamilyHistory(string $surname, string $location = '', array $options = []): array
    {
        $parts = ["\"{$surname}\""];
        if ($location) {
            $parts[] = "\"{$location}\"";
        }
        $query = implode(' AND ', $parts).' AND (subject:(genealogy OR "family history" OR census OR "vital records" OR obituary OR cemetery))';

        $options['mediatype'] = $options['mediatype'] ?? 'texts';

        return $this->search($query, $options);
    }

    /**
     * Get metadata for a specific item.
     *
     * @param  string  $identifier  Item identifier (e.g., 'heraldgenealogy00smith')
     * @return array Item metadata
     */
    public function getMetadata(string $identifier): array
    {
        $cacheKey = 'ia_meta:'.$identifier;
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $this->throttle();

        try {
            $response = Http::connectTimeout(5)->timeout(30)
                ->withHeaders(['User-Agent' => $this->userAgent()])
                ->get(self::METADATA_URL.'/'.$identifier);

            if (! $response->successful()) {
                return ['success' => false, 'error' => 'HTTP '.$response->status()];
            }

            $data = $response->json();
            $metadata = $data['metadata'] ?? [];
            $files = $data['files'] ?? [];
            $server = $data['server'] ?? '';
            $dir = $data['dir'] ?? '';

            $result = [
                'success' => true,
                'identifier' => $identifier,
                'title' => $metadata['title'] ?? $identifier,
                'creator' => $metadata['creator'] ?? '',
                'date' => $metadata['date'] ?? '',
                'description' => $metadata['description'] ?? '',
                'subject' => $metadata['subject'] ?? [],
                'mediatype' => $metadata['mediatype'] ?? '',
                'collection' => $metadata['collection'] ?? [],
                'language' => $metadata['language'] ?? '',
                'item_size' => $data['item_size'] ?? 0,
                'files_count' => $data['files_count'] ?? count($files),
                'files' => array_map(function ($file) use ($identifier) {
                    return [
                        'name' => $file['name'] ?? '',
                        'format' => $file['format'] ?? '',
                        'size' => (int) ($file['size'] ?? 0),
                        'source' => $file['source'] ?? 'original',
                        'md5' => $file['md5'] ?? '',
                        'download_url' => self::DOWNLOAD_URL.'/'.$identifier.'/'.($file['name'] ?? ''),
                    ];
                }, $files),
                'url' => self::BASE_URL.'/details/'.$identifier,
            ];

            Cache::put($cacheKey, $result, self::METADATA_CACHE_TTL);

            return $result;

        } catch (\Exception $e) {
            Log::error('InternetArchiveService: Metadata error', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get downloadable files for an item, filtered by format.
     *
     * @param  string  $identifier  Item identifier
     * @param  array  $formats  Desired formats (e.g., ['PDF', 'Text', 'DjVu'])
     * @return array Filtered file list with download URLs
     */
    public function getDownloadableFiles(string $identifier, array $formats = []): array
    {
        $meta = $this->getMetadata($identifier);
        if (! ($meta['success'] ?? false)) {
            return $meta;
        }

        $files = $meta['files'] ?? [];

        if (! empty($formats)) {
            $formatsLower = array_map('strtolower', $formats);
            $files = array_filter($files, function ($file) use ($formatsLower) {
                $format = strtolower($file['format'] ?? '');
                $name = strtolower($file['name'] ?? '');
                foreach ($formatsLower as $f) {
                    if (str_contains($format, $f) || str_ends_with($name, '.'.$f)) {
                        return true;
                    }
                }

                return false;
            });
        }

        // Filter out internal/metadata files
        $files = array_filter($files, function ($file) {
            $name = $file['name'] ?? '';

            return ! str_starts_with($name, '__') && $name !== '_meta.xml' && $name !== '_files.xml';
        });

        return [
            'success' => true,
            'identifier' => $identifier,
            'title' => $meta['title'] ?? $identifier,
            'files' => array_values($files),
        ];
    }

    /**
     * Download a file from archive.org to local storage.
     *
     * @param  string  $identifier  Item identifier
     * @param  string  $filename  File name within the item
     * @param  string|null  $destinationPath  Override destination (relative to storage/app)
     * @param  string|null  $familySurname  If genealogy-related, organize under family name
     * @return array Download result with local path
     */
    public function downloadFile(string $identifier, string $filename, ?string $destinationPath = null, ?string $familySurname = null): array
    {
        $url = self::DOWNLOAD_URL.'/'.$identifier.'/'.rawurlencode($filename);

        // Determine storage path
        if ($destinationPath) {
            $localPath = $destinationPath;
        } elseif ($familySurname) {
            $safeSurname = preg_replace('/[^a-zA-Z0-9_-]/', '_', $familySurname);
            $localPath = self::DOWNLOAD_BASE.'/genealogy/'.$safeSurname.'/'.$filename;
        } else {
            $localPath = self::DOWNLOAD_BASE.'/general/'.$identifier.'/'.$filename;
        }

        $fullPath = storage_path('app/'.$localPath);
        $dir = dirname($fullPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Skip if already downloaded
        if (file_exists($fullPath) && filesize($fullPath) > 0) {
            return [
                'success' => true,
                'path' => $localPath,
                'full_path' => $fullPath,
                'size' => filesize($fullPath),
                'cached' => true,
            ];
        }

        $this->throttle();

        try {
            Log::info('InternetArchiveService: Downloading', [
                'identifier' => $identifier,
                'file' => $filename,
                'destination' => $localPath,
            ]);

            $response = Http::connectTimeout(5)->timeout(300)
                ->withHeaders(['User-Agent' => $this->userAgent()])
                ->withOptions(['sink' => $fullPath])
                ->get($url);

            if (! $response->successful()) {
                @unlink($fullPath);

                return ['success' => false, 'error' => 'HTTP '.$response->status()];
            }

            $size = filesize($fullPath);

            Log::info('InternetArchiveService: Download complete', [
                'identifier' => $identifier,
                'file' => $filename,
                'size' => $size,
            ]);

            return [
                'success' => true,
                'path' => $localPath,
                'full_path' => $fullPath,
                'size' => $size,
                'cached' => false,
            ];

        } catch (\Exception $e) {
            @unlink($fullPath);
            Log::error('InternetArchiveService: Download error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Download the best available format for an item (PDF preferred, then text).
     *
     * @param  string  $identifier  Item identifier
     * @param  string|null  $familySurname  Family name for genealogy organization
     * @return array Download result
     */
    public function downloadBestFormat(string $identifier, ?string $familySurname = null): array
    {
        $preferredFormats = ['PDF', 'DjVu', 'Text', 'EPUB'];
        $files = $this->getDownloadableFiles($identifier, $preferredFormats);

        if (! ($files['success'] ?? false) || empty($files['files'])) {
            return ['success' => false, 'error' => 'No downloadable files found'];
        }

        // Pick the best file: prefer PDF, then others by preference order
        $best = null;
        foreach ($preferredFormats as $format) {
            foreach ($files['files'] as $file) {
                $fileFormat = strtolower($file['format'] ?? '');
                $fileName = strtolower($file['name'] ?? '');
                if (str_contains($fileFormat, strtolower($format)) || str_ends_with($fileName, '.'.strtolower($format))) {
                    $best = $file;
                    break 2;
                }
            }
        }

        if (! $best) {
            $best = $files['files'][0]; // Fallback to first available
        }

        return $this->downloadFile($identifier, $best['name'], null, $familySurname);
    }

    /**
     * Copy a downloaded archive.org file into Nextcloud genealogy tree.
     *
     * @param  string  $localPath  Path relative to storage/app
     * @param  int  $treeId  Genealogy tree ID
     * @param  string  $subfolder  Subfolder (documents, photos, etc.)
     * @return array Result with Nextcloud path
     */
    public function copyToGenealogyTree(string $localPath, int $treeId, string $subfolder = 'documents'): array
    {
        $fullPath = storage_path('app/'.$localPath);
        if (! file_exists($fullPath)) {
            return ['success' => false, 'error' => 'Local file not found'];
        }

        try {
            $tree = DB::selectOne('SELECT name FROM genealogy_trees WHERE id = ?', [$treeId]);
            if (! $tree) {
                return ['success' => false, 'error' => 'Tree not found'];
            }

            $filename = basename($localPath);
            $configuredGenealogyRoot = config('genealogy.nextcloud_root', '/Library/Genealogy');
            $treeRoot = $this->treeRootResolver->treeScopedRoot(
                $treeId,
                $this->treeRootResolver->mediaRoot($treeId, $configuredGenealogyRoot),
                (string) $tree->name
            );
            $nextcloudPath = $treeRoot.'/'.$this->normalizeTreeSubfolder($subfolder).'/'.$filename;

            $nextcloudApi = app(\App\Services\NextcloudFileApiService::class);
            $nextcloudApi->ensureDirectoryExists(dirname($nextcloudPath));

            $content = file_get_contents($fullPath);
            $result = $nextcloudApi->uploadFile($nextcloudPath, $content);

            if ($result) {
                Log::info('InternetArchiveService: Copied to genealogy tree', [
                    'source' => $localPath,
                    'destination' => $nextcloudPath,
                    'tree_id' => $treeId,
                ]);

                return [
                    'success' => true,
                    'nextcloud_path' => $nextcloudPath,
                    'filename' => $filename,
                ];
            }

            return ['success' => false, 'error' => 'Upload to Nextcloud failed'];

        } catch (\Exception $e) {
            Log::error('InternetArchiveService: Copy to tree failed', [
                'path' => $localPath,
                'tree_id' => $treeId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function normalizeTreeSubfolder(string $subfolder): string
    {
        $parts = array_values(array_filter(
            explode('/', str_replace('\\', '/', trim($subfolder))),
            static fn (string $part): bool => $part !== '' && $part !== '.' && $part !== '..'
        ));

        $safe = array_map(static function (string $part): string {
            $part = preg_replace('/[^A-Za-z0-9._-]+/', '-', $part) ?? '';
            $part = trim($part, '-_.');

            return $part !== '' ? $part : 'files';
        }, $parts);

        return $safe !== [] ? implode('/', $safe) : 'documents';
    }

    /**
     * Set S3 API keys for authenticated operations.
     */
    public function setS3Keys(string $accessKey, string $secretKey): void
    {
        $this->s3AccessKey = $accessKey;
        $this->s3SecretKey = $secretKey;
    }

    /**
     * Load S3 keys from genealogy_research_providers table.
     */
    public function loadS3Keys(): bool
    {
        try {
            $provider = DB::selectOne(
                "SELECT api_key, config FROM genealogy_research_providers WHERE provider_id = 'internet_archive' AND is_active = 1"
            );
            if ($provider) {
                $config = json_decode($provider->config ?? '{}', true);
                $this->s3AccessKey = $config['s3_access_key'] ?? null;
                $this->s3SecretKey = $provider->api_key ?? null;

                return ! empty($this->s3AccessKey) && ! empty($this->s3SecretKey);
            }
        } catch (\Exception $e) {
            // Table may not exist on dev
        }

        return false;
    }

    /**
     * Scrape search (unlimited pagination, requires S3 keys).
     *
     * @param  string  $query  Search query
     * @param  array  $fields  Fields to return
     * @param  int  $count  Results per page
     * @param  string|null  $cursor  Cursor for pagination
     * @return array Results with cursor for next page
     */
    public function scrapeSearch(string $query, array $fields = ['identifier', 'title'], int $count = 100, ?string $cursor = null): array
    {
        if (! $this->s3AccessKey || ! $this->s3SecretKey) {
            $this->loadS3Keys();
        }

        if (! $this->s3AccessKey || ! $this->s3SecretKey) {
            return ['success' => false, 'error' => 'S3 keys not configured'];
        }

        $this->throttle();

        try {
            $params = [
                'q' => $query,
                'fields' => implode(',', $fields),
                'count' => $count,
            ];
            if ($cursor) {
                $params['cursor'] = $cursor;
            }

            $response = Http::connectTimeout(5)->timeout(60)
                ->withHeaders([
                    'User-Agent' => $this->userAgent(),
                    'Authorization' => "LOW {$this->s3AccessKey}:{$this->s3SecretKey}",
                ])
                ->get(self::SCRAPE_URL, $params);

            if (! $response->successful()) {
                return ['success' => false, 'error' => 'HTTP '.$response->status()];
            }

            $data = $response->json();

            return [
                'success' => true,
                'total' => $data['total'] ?? 0,
                'items' => $data['items'] ?? [],
                'cursor' => $data['cursor'] ?? null,
                'count' => $data['count'] ?? 0,
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Throttle requests to stay within rate limits.
     */
    private function throttle(): void
    {
        if ($this->lastRequestTime !== null) {
            $elapsed = (microtime(true) - $this->lastRequestTime) * 1000;
            $delay = self::REQUEST_DELAY_MS - $elapsed;
            if ($delay > 0) {
                usleep((int) ($delay * 1000));
            }
        }
        $this->lastRequestTime = microtime(true);
    }
}
