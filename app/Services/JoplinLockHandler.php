<?php

namespace App\Services;

use App\Support\JoplinPaths;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Joplin Lock Handler
 *
 * Independent PLOS PHP implementation of the public Joplin sync-lock behavior.
 * Reference: https://joplinapp.org/help/dev/spec/sync_lock/
 * It does not use upstream Joplin application or server source code.
 *
 * Implements distributed locking mechanism for safe concurrent
 * access to Joplin notes via WebDAV sync target.
 */
class JoplinLockHandler
{
    /** @var int Lock TTL in milliseconds (3 minutes) */
    private const LOCK_TTL_MS = 180000;

    /** @var int Lock refresh interval in milliseconds (1 minute) */
    private const REFRESH_INTERVAL_MS = 60000;

    /** @var string Locks directory in Joplin sync target */
    private const LOCKS_DIR = '.sync/locks/';

    /** @var string Client type identifier */
    private const CLIENT_TYPE = 'php_framework';

    private string $baseUrl;

    private string $username;

    private string $password;

    private string $joplinPath = '/Joplin-data';

    private ?string $clientId = null;

    private const HTTP_CONNECT_TIMEOUT = 5;

    private const HTTP_TIMEOUT = 120;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.nextcloud.url') ?? '', '/');
        $this->username = config('services.nextcloud.username') ?? '';
        $this->password = config('services.nextcloud.password') ?? '';
        $this->joplinPath = JoplinPaths::syncPath(false);
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT)
            ->timeout(self::HTTP_TIMEOUT)
            ->withBasicAuth($this->username, $this->password);
    }

    /**
     * Acquire a SYNC lock (allows multiple concurrent reads/writes)
     *
     * @return array Lock object
     *
     * @throws \Exception if EXCLUSIVE lock exists
     */
    public function acquireSyncLock(): array
    {
        // 1. Check for EXCLUSIVE locks
        $exclusiveLocks = $this->getExclusiveLocks();

        if (! empty($exclusiveLocks)) {
            throw new \Exception('Cannot acquire SYNC lock: EXCLUSIVE lock exists (another Joplin client may be upgrading the sync target)');
        }

        // 2. Create lock object
        $lock = [
            'type' => 'sync',
            'clientType' => self::CLIENT_TYPE,
            'clientId' => $this->clientId(),
            'updatedTime' => $this->getCurrentTimeMs(),
        ];

        // 3. Save lock file to WebDAV
        $this->saveLock($lock);

        Log::info('Acquired SYNC lock', [
            'client_id' => $this->clientId(),
            'lock_file' => $this->lockFilename($lock),
        ]);

        return $lock;
    }

    /**
     * Acquire an EXCLUSIVE lock (only one allowed, blocks all other operations)
     *
     * @param  int  $timeoutMs  Maximum time to wait for lock acquisition
     * @return array Lock object
     *
     * @throws \Exception if timeout reached
     */
    public function acquireExclusiveLock(int $timeoutMs = 30000): array
    {
        $startTime = $this->getCurrentTimeMs();
        $attempt = 1;

        while (true) {
            // 1. Check for ANY existing locks
            $syncLocks = $this->getSyncLocks();
            $exclusiveLocks = $this->getExclusiveLocks();

            if (empty($syncLocks) && empty($exclusiveLocks)) {
                // 2. No locks exist - try to acquire
                $lock = [
                    'type' => 'exclusive',
                    'clientType' => self::CLIENT_TYPE,
                    'clientId' => $this->clientId(),
                    'updatedTime' => $this->getCurrentTimeMs(),
                ];

                $this->saveLock($lock);

                // 3. Verify we got it (handle race condition)
                usleep(100000); // 100ms - give other clients time to save their locks

                $verifyLocks = $this->getExclusiveLocks();
                $ourLock = $this->findLockByClientId($verifyLocks, $this->clientId());

                if ($ourLock && $this->isOldestLock($ourLock, $verifyLocks)) {
                    Log::info('Acquired EXCLUSIVE lock', [
                        'client_id' => $this->clientId(),
                        'attempts' => $attempt,
                    ]);

                    return $ourLock;
                }

                // 4. Someone else got it first - delete ours
                $this->releaseLock($lock);

                Log::debug('Lost EXCLUSIVE lock race condition', [
                    'attempt' => $attempt,
                ]);
            }

            // 5. Check timeout
            if ($this->getCurrentTimeMs() - $startTime > $timeoutMs) {
                throw new \Exception(sprintf(
                    'Could not acquire EXCLUSIVE lock: timeout after %d attempts (%dms)',
                    $attempt,
                    $timeoutMs
                ));
            }

            // 6. Wait and retry
            $attempt++;
            sleep(1);
        }
    }

    /**
     * Release a lock
     *
     * @param  array  $lock  Lock object to release
     */
    public function releaseLock(array $lock): void
    {
        $filename = $this->lockFilename($lock);
        $path = $this->joplinPath.'/'.self::LOCKS_DIR.$filename;
        $url = $this->baseUrl.'/remote.php/dav/files/'.$this->username.$path;

        try {
            $response = $this->http()
                ->delete($url);

            if ($response->successful()) {
                Log::info('Released lock', [
                    'type' => $lock['type'],
                    'lock_file' => $filename,
                ]);
            } else {
                Log::warning('Failed to release lock', [
                    'lock_file' => $filename,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error releasing lock', [
                'lock_file' => $filename,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Refresh lock timestamp (called periodically to keep lock alive)
     *
     * @param  array  $lock  Lock object (passed by reference)
     */
    public function refreshLock(array &$lock): void
    {
        $lock['updatedTime'] = $this->getCurrentTimeMs();
        $this->saveLock($lock);

        Log::debug('Refreshed lock', [
            'type' => $lock['type'],
            'lock_file' => $this->lockFilename($lock),
        ]);
    }

    /**
     * Check if we can acquire a lock (without actually acquiring it)
     *
     * @param  string  $lockType  'sync' or 'exclusive'
     * @return bool True if lock can be acquired
     */
    public function canAcquireLock(string $lockType): bool
    {
        try {
            if ($lockType === 'sync') {
                $exclusiveLocks = $this->getExclusiveLocks();

                return empty($exclusiveLocks);
            } elseif ($lockType === 'exclusive') {
                $syncLocks = $this->getSyncLocks();
                $exclusiveLocks = $this->getExclusiveLocks();

                return empty($syncLocks) && empty($exclusiveLocks);
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Error checking lock availability', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get all active SYNC locks
     *
     * @return array Array of lock objects
     */
    private function getSyncLocks(): array
    {
        return array_filter($this->getAllLocks(), function ($lock) {
            return $lock['type'] === 'sync' && $this->lockIsActive($lock);
        });
    }

    /**
     * Get all active EXCLUSIVE locks
     *
     * @return array Array of lock objects
     */
    private function getExclusiveLocks(): array
    {
        return array_filter($this->getAllLocks(), function ($lock) {
            return $lock['type'] === 'exclusive' && $this->lockIsActive($lock);
        });
    }

    /**
     * Get all locks from WebDAV
     *
     * @return array Array of lock objects
     */
    private function getAllLocks(): array
    {
        $locksDir = $this->joplinPath.'/'.self::LOCKS_DIR;
        $url = $this->baseUrl.'/remote.php/dav/files/'.$this->username.$locksDir;

        try {
            // Use PROPFIND to list directory
            $response = $this->http()
                ->withHeaders(['Depth' => '1'])
                ->withBody('<?xml version="1.0"?>
                    <d:propfind xmlns:d="DAV:">
                        <d:prop>
                            <d:getlastmodified/>
                        </d:prop>
                    </d:propfind>', 'application/xml')
                ->send('PROPFIND', $url);

            if (! $response->successful()) {
                Log::warning('Failed to list locks directory', [
                    'status' => $response->status(),
                ]);

                return [];
            }

            // Parse WebDAV XML response
            $xml = simplexml_load_string($response->body());
            if (! $xml) {
                return [];
            }

            $xml->registerXPathNamespace('d', 'DAV:');

            $locks = [];
            foreach ($xml->xpath('//d:response') as $item) {
                $href = (string) $item->xpath('d:href')[0];

                // Extract filename from href
                if (preg_match('/\/([^\/]+\.json)$/', $href, $matches)) {
                    $filename = $matches[1];

                    // Fetch lock file content
                    $lockContent = $this->getLockContent($filename);
                    if ($lockContent) {
                        $locks[] = $lockContent;
                    }
                }
            }

            return $locks;

        } catch (\Exception $e) {
            Log::error('Error fetching locks', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get lock file content from WebDAV
     *
     * @param  string  $filename  Lock filename
     * @return array|null Lock object or null if not found
     */
    private function getLockContent(string $filename): ?array
    {
        $path = $this->joplinPath.'/'.self::LOCKS_DIR.$filename;
        $url = $this->baseUrl.'/remote.php/dav/files/'.$this->username.$path;

        try {
            $response = $this->http()
                ->get($url);

            if ($response->successful()) {
                $lock = json_decode($response->body(), true);

                // Validate lock structure
                if (isset($lock['type'], $lock['clientId'], $lock['updatedTime'])) {
                    return $lock;
                }
            }
        } catch (\Exception $e) {
            Log::debug('Could not fetch lock file', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Save lock to WebDAV
     *
     * @param  array  $lock  Lock object
     */
    private function saveLock(array $lock): void
    {
        $filename = $this->lockFilename($lock);
        $path = $this->joplinPath.'/'.self::LOCKS_DIR.$filename;
        $url = $this->baseUrl.'/remote.php/dav/files/'.$this->username.$path;

        $response = $this->http()
            ->withBody(json_encode($lock, JSON_PRETTY_PRINT), 'application/json')
            ->put($url);

        if (! $response->successful()) {
            throw new \Exception(sprintf(
                'Failed to save lock file: HTTP %d',
                $response->status()
            ));
        }
    }

    /**
     * Generate lock filename from lock object
     *
     * @param  array  $lock  Lock object
     * @return string Filename (e.g., "sync_php_framework_abc123.json")
     */
    private function lockFilename(array $lock): string
    {
        return sprintf('%s_%s_%s.json',
            $lock['type'],
            $lock['clientType'],
            $lock['clientId']
        );
    }

    /**
     * Check if lock is still active (not expired)
     *
     * @param  array  $lock  Lock object
     * @return bool True if lock is active
     */
    private function lockIsActive(array $lock): bool
    {
        $lockAge = $this->getCurrentTimeMs() - $lock['updatedTime'];

        return $lockAge < self::LOCK_TTL_MS;
    }

    /**
     * Find lock by client ID
     *
     * @param  array  $locks  Array of lock objects
     * @param  string  $clientId  Client ID to find
     * @return array|null Lock object or null
     */
    private function findLockByClientId(array $locks, string $clientId): ?array
    {
        foreach ($locks as $lock) {
            if ($lock['clientId'] === $clientId) {
                return $lock;
            }
        }

        return null;
    }

    /**
     * Check if our lock is the oldest (wins race conditions)
     *
     * The active lock with the earliest timestamp wins; ties fall back to the
     * lexicographically lowest client ID.
     *
     * @param  array  $ourLock  Our lock object
     * @param  array  $allLocks  All lock objects
     * @return bool True if our lock is the oldest
     */
    private function isOldestLock(array $ourLock, array $allLocks): bool
    {
        foreach ($allLocks as $lock) {
            // Skip our own lock
            if ($lock['clientId'] === $ourLock['clientId']) {
                continue;
            }

            // Someone has an older lock
            if ($lock['updatedTime'] < $ourLock['updatedTime']) {
                return false;
            }

            // Same timestamp - compare client IDs (alphabetically)
            if ($lock['updatedTime'] === $ourLock['updatedTime']) {
                if ($lock['clientId'] < $ourLock['clientId']) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get current time in milliseconds (JavaScript timestamp format)
     *
     * @return int Current time in milliseconds since epoch
     */
    private function getCurrentTimeMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    private function clientId(): string
    {
        if ($this->clientId === null) {
            $this->clientId = $this->getOrCreateClientId();
        }

        return $this->clientId;
    }

    /**
     * Get or create unique client ID
     *
     * @return string Client ID (32-char hex)
     */
    private function getOrCreateClientId(): string
    {
        $cacheKey = 'joplin_client_id';

        try {
            return Cache::rememberForever($cacheKey, fn () => $this->generateClientId());
        } catch (\Throwable $e) {
            Log::warning('Using transient Joplin client ID because cache is unavailable', [
                'error' => $e->getMessage(),
            ]);

            return $this->generateClientId();
        }
    }

    private function generateClientId(): string
    {
        return str_replace('-', '', Str::uuid()->toString());
    }

    /**
     * Get lock status for monitoring/debugging
     *
     * @return array Lock status information
     */
    public function getLockStatus(): array
    {
        $syncLocks = $this->getSyncLocks();
        $exclusiveLocks = $this->getExclusiveLocks();

        return [
            'client_id' => $this->clientId(),
            'sync_locks' => count($syncLocks),
            'exclusive_locks' => count($exclusiveLocks),
            'can_acquire_sync' => $this->canAcquireLock('sync'),
            'can_acquire_exclusive' => $this->canAcquireLock('exclusive'),
            'locks' => array_merge($syncLocks, $exclusiveLocks),
        ];
    }
}
