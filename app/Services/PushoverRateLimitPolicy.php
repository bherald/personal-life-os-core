<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class PushoverRateLimitPolicy
{
    public const TTL_SECONDS = 60;

    private const ALLOWED_GROUPS = [
        'daily_digests',
        'agent_approval_review',
        'workflow_node_notifications',
        'workflow_routine_updates',
        'auth_token_alerts',
        'test_dev_only',
    ];

    private const RATE_LIMITS = [
        'agent_approval_review' => 7,
        'daily_digests' => 7,
        'workflow_node_notifications' => 10,
        'workflow_routine_updates' => 60,
        'auth_token_alerts' => 10,
        'test_dev_only' => 20,
        'unknown' => 7,
    ];

    public static function isAllowed(string $sourceGroup): bool
    {
        return in_array($sourceGroup, self::ALLOWED_GROUPS, true);
    }

    public static function limitFor(string $sourceGroup): int
    {
        return self::RATE_LIMITS[$sourceGroup] ?? self::RATE_LIMITS['unknown'];
    }

    public static function cacheKey(string $sourceGroup): string
    {
        return 'pushover_rate_limit:'.$sourceGroup;
    }

    public static function currentCount(string $sourceGroup): int
    {
        return max(0, (int) Cache::get(self::cacheKey($sourceGroup), 0));
    }

    public static function capacity(string $sourceGroup): array
    {
        $limit = self::limitFor($sourceGroup);
        $currentCount = self::currentCount($sourceGroup);
        $allowed = self::isAllowed($sourceGroup);

        return [
            'source_group' => $sourceGroup,
            'allowed' => $allowed,
            'limit' => $limit,
            'current_count' => $currentCount,
            'remaining' => $allowed ? max(0, $limit - $currentCount) : 0,
        ];
    }

    public static function hasCapacity(string $sourceGroup, int $requiredSends): bool
    {
        if ($requiredSends <= 0 || ! self::isAllowed($sourceGroup)) {
            return true;
        }

        return self::capacity($sourceGroup)['remaining'] >= $requiredSends;
    }

    public static function recordSent(string $sourceGroup): void
    {
        Cache::put(
            self::cacheKey($sourceGroup),
            self::currentCount($sourceGroup) + 1,
            self::TTL_SECONDS
        );
    }
}
