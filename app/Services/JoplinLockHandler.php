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
    public const LOCK_TTL_MS = 180000;

    /** @var int Lock refresh interval in milliseconds (1 minute) */
    private const REFRESH_INTERVAL_MS = 60000;

    /** @var string Locks directory in Joplin sync target */
    private const LOCKS_DIR = 'locks/';

    /** @var string Client type identifier */
    private const CLIENT_TYPE = 3; // Joplin wire protocol: CLI client

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
        try {
            $active = $this->getAllLocks();
            $ours = false;
            foreach ($active as $candidate) {
                if (! $this->lockIsActive($candidate)) {
                    continue;
                }
                if ($candidate['type'] === 'exclusive') {
                    throw new \RuntimeException('Exclusive lock appeared during sync acquisition');
                }
                if ($candidate['clientId'] === $lock['clientId'] && $candidate['type'] === 'sync') {
                    $ours = true;
                }
            }
            if (! $ours) {
                throw new \RuntimeException('Sync lock could not be read back');
            }
        } catch (\Throwable $e) {
            $this->releaseLock($lock);
            throw $e;
        }

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
        $url = $this->baseUrl.'/remote.php/dav/files/'.$this->username.$this->joplinPath.'/'.self::LOCKS_DIR;
        $response = $this->http()->withHeaders(['Depth' => '1'])
            ->withBody('<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getlastmodified/></d:prop></d:propfind>', 'application/xml')
            ->send('PROPFIND', $url);
        if (! $response->successful()) {
            throw new \RuntimeException('Cannot establish Joplin lock state: HTTP '.$response->status());
        }
        $xml = simplexml_load_string($response->body(), \SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new \RuntimeException('Invalid Joplin lock listing');
        }
        $xml->registerXPathNamespace('d', 'DAV:');
        $locks = [];
        foreach ($xml->xpath('//d:response') as $item) {
            $item->registerXPathNamespace('d', 'DAV:');
            $href = (string) ($item->xpath('d:href')[0] ?? '');
            $filename = basename(rawurldecode($href));
            if (! str_ends_with($filename, '.json')) {
                continue;
            }
            if (! preg_match('/^(1|2|sync|exclusive)_(1|2|3|desktop|mobile|cli|php_framework)_([a-zA-Z0-9-]+)\.json$/D', $filename, $parts)) {
                throw new \RuntimeException('Unrecognized Joplin lock filename');
            }
            $date = (string) ($item->xpath('d:propstat/d:prop/d:getlastmodified')[0] ?? '');
            $timestamp = strtotime($date);
            if ($timestamp === false) {
                throw new \RuntimeException('Joplin lock timestamp unavailable');
            }
            $locks[] = ['type' => in_array($parts[1], ['1', 'sync'], true) ? 'sync' : 'exclusive',
                'clientType' => $parts[2], 'clientId' => $parts[3], 'updatedTime' => $timestamp * 1000];
        }

        return $locks;
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
            ->withBody(json_encode(array_merge($lock, ['type' => $lock['type'] === 'sync' ? 1 : 2, 'clientType' => self::CLIENT_TYPE]), JSON_PRETTY_PRINT), 'application/json')
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
            $lock['type'] === 'sync' ? 1 : 2,
            self::CLIENT_TYPE,
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
        // One consistent DAV snapshot; repeated availability probes previously listed the same directory five times.
        $active = array_values(array_filter($this->getAllLocks(), fn ($lock) => $this->lockIsActive($lock)));
        $syncLocks = array_filter($active, fn ($lock) => $lock['type'] === 'sync');
        $exclusiveLocks = array_filter($active, fn ($lock) => $lock['type'] === 'exclusive');

        return [
            'client_id' => $this->clientId(),
            'sync_locks' => count($syncLocks),
            'exclusive_locks' => count($exclusiveLocks),
            'can_acquire_sync' => count($exclusiveLocks) === 0,
            'can_acquire_exclusive' => count($active) === 0,
            'locks' => $active,
        ];
    }
}
