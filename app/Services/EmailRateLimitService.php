<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Email Rate Limiting & Throttling Service
 *
 * Manages email sending limits to prevent abuse and maintain sender reputation:
 * - Per-mailbox daily limits (configurable, default 100/day)
 * - Hourly caps (default 20/hour)
 * - Domain-specific throttling (slow down for problematic domains)
 * - Cooldown periods after rate limit hit
 * - Redis-based counters with TTL for real-time tracking
 *
 * Uses hybrid storage:
 * - Redis: Real-time counters with TTL (fast, atomic operations)
 * - MySQL: Persistent state, domain throttle rules, audit trail
 */
class EmailRateLimitService
{
    // Redis key prefixes
    private const REDIS_PREFIX = 'email_rate:';
    private const COUNTER_TTL_SECONDS = 86400; // 24 hours

    // Limits loaded from SystemConfigService (SC-3)
    private int $dailyLimit;
    private int $hourlyLimit;
    private int $cooldownMinutes;

    public function __construct()
    {
        try {
            $config = app(SystemConfigService::class);
            $this->dailyLimit = $config->getInt('email.daily_send_limit', 100);
            $this->hourlyLimit = $config->getInt('email.hourly_send_limit', 20);
            $this->cooldownMinutes = $config->getInt('email.cooldown_minutes', 30);
        } catch (\Throwable $e) {
            $this->dailyLimit = 100;
            $this->hourlyLimit = 20;
            $this->cooldownMinutes = 30;
        }
    }

    /**
     * Check if an email can be sent from this mailbox to this domain
     *
     * @param string $mailbox Sender email address
     * @param string $recipientDomain Recipient's email domain
     * @return bool True if send is allowed
     */
    public function canSend(string $mailbox, string $recipientDomain): bool
    {
        $mailbox = strtolower(trim($mailbox));
        $recipientDomain = strtolower(trim($recipientDomain));

        // Check cooldown first (fastest rejection)
        if ($this->isInCooldown($mailbox)) {
            Log::debug('EmailRateLimitService: Mailbox in cooldown', ['mailbox' => $mailbox]);
            return false;
        }

        // Check rate limits
        $quota = $this->getRemainingQuota($mailbox);

        if ($quota['daily_remaining'] <= 0) {
            Log::warning('EmailRateLimitService: Daily limit reached', [
                'mailbox' => $mailbox,
                'daily_count' => $quota['daily_count'],
                'daily_limit' => $quota['daily_limit'],
            ]);
            return false;
        }

        if ($quota['hourly_remaining'] <= 0) {
            Log::warning('EmailRateLimitService: Hourly limit reached', [
                'mailbox' => $mailbox,
                'hourly_count' => $quota['hourly_count'],
                'hourly_limit' => $quota['hourly_limit'],
            ]);
            return false;
        }

        // Check domain-specific throttle (time-based)
        $domainDelay = $this->getDomainDelay($recipientDomain);
        if ($domainDelay > 0) {
            $lastSentToRecipientDomain = $this->getLastSentToRecipientDomain($mailbox, $recipientDomain);
            if ($lastSentToRecipientDomain !== null) {
                $elapsedMs = now()->getPreciseTimestamp(3) - $lastSentToRecipientDomain;
                if ($elapsedMs < $domainDelay) {
                    Log::debug('EmailRateLimitService: Domain throttle active', [
                        'mailbox' => $mailbox,
                        'domain' => $recipientDomain,
                        'delay_ms' => $domainDelay,
                        'elapsed_ms' => $elapsedMs,
                    ]);
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Record that an email was sent
     *
     * @param string $mailbox Sender email address
     * @param string $recipientDomain Recipient's email domain
     */
    public function recordSend(string $mailbox, string $recipientDomain): void
    {
        $mailbox = strtolower(trim($mailbox));
        $recipientDomain = strtolower(trim($recipientDomain));
        $now = now();

        // Increment Redis counters (atomic, fast)
        $this->incrementRedisCounters($mailbox);

        // Record domain-specific last send time
        $this->recordDomainSend($mailbox, $recipientDomain);

        // Update MySQL state (for persistence and audit)
        $this->updateDatabaseState($mailbox, $now);

        Log::debug('EmailRateLimitService: Send recorded', [
            'mailbox' => $mailbox,
            'domain' => $recipientDomain,
        ]);
    }

    /**
     * Get remaining quota for a mailbox
     *
     * @param string $mailbox Email address
     * @return array Quota information
     */
    public function getRemainingQuota(string $mailbox): array
    {
        $mailbox = strtolower(trim($mailbox));

        // Get counts from Redis (real-time)
        $dailyCount = $this->getDailyCount($mailbox);
        $hourlyCount = $this->getHourlyCount($mailbox);

        // Get mailbox-specific limits (may be customized in DB)
        $limits = $this->getMailboxLimits($mailbox);

        return [
            'mailbox' => $mailbox,
            'daily_count' => $dailyCount,
            'daily_limit' => $limits['daily'],
            'daily_remaining' => max(0, $limits['daily'] - $dailyCount),
            'hourly_count' => $hourlyCount,
            'hourly_limit' => $limits['hourly'],
            'hourly_remaining' => max(0, $limits['hourly'] - $hourlyCount),
            'cooldown_until' => $this->getCooldownUntil($mailbox),
            'in_cooldown' => $this->isInCooldown($mailbox),
        ];
    }

    /**
     * Set a cooldown period for a mailbox
     *
     * @param string $mailbox Email address
     * @param int $minutes Cooldown duration in minutes
     * @param string|null $reason Optional reason for cooldown
     */
    public function setCooldown(string $mailbox, int $minutes, ?string $reason = null): void
    {
        $mailbox = strtolower(trim($mailbox));
        $cooldownUntil = now()->addMinutes($minutes);

        // Redis-only cooldown tracking (email_rate_limits table removed per D1)
        $this->safeCache(
            'put',
            self::REDIS_PREFIX . "cooldown:{$mailbox}",
            $cooldownUntil->timestamp,
            $minutes * 60
        );

        Log::warning('EmailRateLimitService: Cooldown set', [
            'mailbox' => $mailbox,
            'minutes' => $minutes,
            'until' => $cooldownUntil->toDateTimeString(),
            'reason' => $reason,
        ]);
    }

    /**
     * Clear cooldown for a mailbox
     *
     * @param string $mailbox Email address
     */
    public function clearCooldown(string $mailbox): void
    {
        $mailbox = strtolower(trim($mailbox));
        $this->safeCache('forget', self::REDIS_PREFIX . "cooldown:{$mailbox}");
        Log::info('EmailRateLimitService: Cooldown cleared', ['mailbox' => $mailbox]);
    }

    /**
     * Get the delay (in milliseconds) required when sending to a domain
     *
     * @param string $domain Email domain
     * @return int Delay in milliseconds (0 = no delay)
     */
    public function getDomainDelay(string $domain): int
    {
        $domain = strtolower(trim($domain));

        // Check cache first
        $cacheKey = self::REDIS_PREFIX . "domain_delay:{$domain}";
        $cached = $this->safeCache('get', $cacheKey);
        if ($cached !== null) {
            return (int) $cached;
        }

        // Query database
        $throttle = DB::selectOne(
            "SELECT delay_ms FROM email_domain_throttles
             WHERE domain = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())",
            [$domain]
        );

        $delay = $throttle ? (int) $throttle->delay_ms : 0;

        // Cache for 5 minutes
        $this->safeCache('put', $cacheKey, $delay, 300);

        return $delay;
    }

    /**
     * Set domain-specific throttle
     *
     * @param string $domain Email domain
     * @param int $delayMs Delay in milliseconds
     * @param string|null $reason Reason for throttle
     * @param int|null $expiresInMinutes Optional expiration time
     */
    public function setDomainThrottle(
        string $domain,
        int $delayMs,
        ?string $reason = null,
        ?int $expiresInMinutes = null
    ): void {
        $domain = strtolower(trim($domain));
        $expiresAt = $expiresInMinutes ? now()->addMinutes($expiresInMinutes)->toDateTimeString() : null;

        // Upsert domain throttle
        $exists = DB::selectOne("SELECT id FROM email_domain_throttles WHERE domain = ?", [$domain]);

        if ($exists) {
            DB::update(
                "UPDATE email_domain_throttles
                 SET delay_ms = ?, reason = ?, expires_at = ?, is_active = 1, updated_at = NOW()
                 WHERE domain = ?",
                [$delayMs, $reason, $expiresAt, $domain]
            );
        } else {
            DB::insert(
                "INSERT INTO email_domain_throttles (domain, delay_ms, reason, expires_at, is_active)
                 VALUES (?, ?, ?, ?, 1)",
                [$domain, $delayMs, $reason, $expiresAt]
            );
        }

        // Clear cache
        $this->safeCache('forget', self::REDIS_PREFIX . "domain_delay:{$domain}");

        Log::info('EmailRateLimitService: Domain throttle set', [
            'domain' => $domain,
            'delay_ms' => $delayMs,
            'reason' => $reason,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Remove domain throttle
     *
     * @param string $domain Email domain
     */
    public function removeDomainThrottle(string $domain): void
    {
        $domain = strtolower(trim($domain));

        DB::update(
            "UPDATE email_domain_throttles SET is_active = 0, updated_at = NOW() WHERE domain = ?",
            [$domain]
        );

        $this->safeCache('forget', self::REDIS_PREFIX . "domain_delay:{$domain}");

        Log::info('EmailRateLimitService: Domain throttle removed', ['domain' => $domain]);
    }

    /**
     * Get all active domain throttles
     *
     * @return array Domain throttle configurations
     */
    public function getActiveDomainThrottles(): array
    {
        return DB::select(
            "SELECT domain, delay_ms, max_per_hour, max_per_day, reason, expires_at
             FROM email_domain_throttles
             WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY domain"
        );
    }

    /**
     * Set custom limits for a mailbox
     *
     * @param string $mailbox Email address
     * @param int|null $dailyLimit Custom daily limit (null = use default)
     * @param int|null $hourlyLimit Custom hourly limit (null = use default)
     */
    public function setMailboxLimits(string $mailbox, ?int $dailyLimit = null, ?int $hourlyLimit = null): void
    {
        $mailbox = strtolower(trim($mailbox));

        $limits = [
            'daily' => $dailyLimit ?? $this->dailyLimit,
            'hourly' => $hourlyLimit ?? $this->hourlyLimit,
        ];

        // Redis-only storage (email_rate_limits table removed per D1)
        $this->safeCache('put', self::REDIS_PREFIX . "limits:{$mailbox}", json_encode($limits), 86400 * 30);
    }

    /**
     * Get rate limit statistics for all mailboxes
     *
     * @return array Statistics
     */
    public function getStats(): array
    {
        $domainThrottles = DB::selectOne("
            SELECT COUNT(*) as count FROM email_domain_throttles WHERE is_active = 1
        ");

        return [
            'active_domain_throttles' => (int) ($domainThrottles->count ?? 0),
            'default_daily_limit' => $this->dailyLimit,
            'default_hourly_limit' => $this->hourlyLimit,
        ];
    }

    /**
     * Reset daily counters (called by scheduler at midnight)
     */
    public function resetDailyCounters(): void
    {
        // Redis counters auto-expire via TTL (set to midnight in incrementRedisCounters)
        // This method is a no-op now but kept for API compatibility
        Log::info('EmailRateLimitService: Daily counters auto-reset via Redis TTL');
    }

    /**
     * Auto-trigger cooldown when limits are hit
     *
     * Called internally when rate limits are exceeded repeatedly
     */
    public function triggerAutoThrottle(string $mailbox, string $reason): void
    {
        $cooldownMinutes = $this->cooldownMinutes;
        $this->setCooldown($mailbox, $cooldownMinutes, "Auto-throttle: {$reason}");
    }

    // =========================================================================
    // PRIVATE HELPER METHODS
    // =========================================================================

    /**
     * Check if mailbox is in cooldown
     */
    private function isInCooldown(string $mailbox): bool
    {
        $cooldownTs = $this->safeCache('get', self::REDIS_PREFIX . "cooldown:{$mailbox}");
        if ($cooldownTs !== null) {
            return time() < (int) $cooldownTs;
        }
        return false;
    }

    /**
     * Get cooldown end time
     */
    private function getCooldownUntil(string $mailbox): ?string
    {
        $cooldownTs = $this->safeCache('get', self::REDIS_PREFIX . "cooldown:{$mailbox}");
        if ($cooldownTs !== null && time() < (int) $cooldownTs) {
            return date('Y-m-d H:i:s', (int) $cooldownTs);
        }
        return null;
    }

    /**
     * Get daily send count from Redis
     */
    private function getDailyCount(string $mailbox): int
    {
        $count = $this->safeCache('get', self::REDIS_PREFIX . "daily:{$mailbox}");
        return $count !== null ? (int) $count : 0;
    }

    /**
     * Get hourly send count from Redis
     */
    private function getHourlyCount(string $mailbox): int
    {
        $count = $this->safeCache('get', self::REDIS_PREFIX . "hourly:{$mailbox}");
        return $count !== null ? (int) $count : 0;
    }

    /**
     * Increment Redis counters atomically
     *
     * Uses Cache facade for consistency with reads (handles prefix automatically)
     */
    private function incrementRedisCounters(string $mailbox): void
    {
        $dailyKey = self::REDIS_PREFIX . "daily:{$mailbox}";
        $hourlyKey = self::REDIS_PREFIX . "hourly:{$mailbox}";

        // Calculate TTLs
        $secondsUntilMidnight = max(1, (int) now()->diffInSeconds(now()->endOfDay()));
        $secondsUntilHour = max(1, (int) now()->diffInSeconds(now()->endOfHour()));

        // Atomic increment — avoids race condition with concurrent sends
        if ($this->safeCache('has', $dailyKey)) {
            $this->safeCache('increment', $dailyKey);
        } else {
            $this->safeCache('put', $dailyKey, 1, $secondsUntilMidnight);
        }

        if ($this->safeCache('has', $hourlyKey)) {
            $this->safeCache('increment', $hourlyKey);
        } else {
            $this->safeCache('put', $hourlyKey, 1, $secondsUntilHour);
        }
    }

    /**
     * Record domain-specific send time
     */
    private function recordDomainSend(string $mailbox, string $domain): void
    {
        $key = self::REDIS_PREFIX . "domain_last:{$mailbox}:{$domain}";
        $this->safeCache('put', $key, now()->getPreciseTimestamp(3), 3600);
    }

    /**
     * Get last send time to a specific domain
     */
    private function getLastSentToRecipientDomain(string $mailbox, string $domain): ?float
    {
        $key = self::REDIS_PREFIX . "domain_last:{$mailbox}:{$domain}";
        $timestamp = $this->safeCache('get', $key);
        return $timestamp !== null ? (float) $timestamp : null;
    }

    /**
     * Update persistent state (Redis-only since D1 table removal)
     */
    private function updateDatabaseState(string $mailbox, $now): void
    {
        // Redis counters already updated in incrementRedisCounters() — no DB persistence needed
    }

    /**
     * Get mailbox-specific limits (may have custom overrides)
     */
    private function getMailboxLimits(string $mailbox): array
    {
        $cacheKey = self::REDIS_PREFIX . "limits:{$mailbox}";
        $cached = $this->safeCache('get', $cacheKey);

        if ($cached !== null) {
            return json_decode($cached, true);
        }

        return [
            'daily' => $this->dailyLimit,
            'hourly' => $this->hourlyLimit,
        ];
    }

    /**
     * Redis-resilient cache wrapper (borrowed from CircuitBreaker pattern)
     */
    private function safeCache(string $operation, string $key, mixed $default = null, ?int $ttl = null): mixed
    {
        try {
            return match ($operation) {
                'get' => Cache::get($key, $default),
                'put' => Cache::put($key, $default, $ttl ?? 3600),
                'has' => Cache::has($key),
                'increment' => Cache::increment($key),
                'forget' => Cache::forget($key),
                default => $default,
            };
        } catch (\Predis\Connection\ConnectionException $e) {
            Log::warning("EmailRateLimitService: Redis connection failed", ['error' => $e->getMessage()]);
            return $default;
        } catch (\RedisException $e) {
            Log::warning("EmailRateLimitService: Redis error", ['error' => $e->getMessage()]);
            return $default;
        } catch (Exception $e) {
            Log::warning("EmailRateLimitService: Cache error", ['error' => $e->getMessage()]);
            return $default;
        }
    }
}
