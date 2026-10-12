<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChatStreamCancellationService
{
    private const TTL_SECONDS = 10800;

    private const MHC08_TTL_SECONDS = 26100;

    public function register(string $runId, int $conversationId): void
    {
        try {
            Cache::put($this->key($runId), [
                'conversation_id' => $conversationId,
                'cancel_requested' => false,
            ], self::TTL_SECONDS);
        } catch (\Throwable $e) {
            Log::debug('Chat stream cancellation registration unavailable', [
                'error_class' => get_class($e),
            ]);
        }
    }

    public function request(string $runId, int $conversationId): bool
    {
        try {
            $key = $this->key($runId);
            $state = Cache::get($key);
            if (! is_array($state) || (int) ($state['conversation_id'] ?? 0) !== $conversationId) {
                return false;
            }

            $state['cancel_requested'] = true;
            $state['requested_at'] = now()->toIso8601String();
            Cache::put($key, $state, self::TTL_SECONDS);

            return true;
        } catch (\Throwable $e) {
            Log::debug('Chat stream cancellation request unavailable', [
                'error_class' => get_class($e),
            ]);

            return false;
        }
    }

    public function registerOwned(string $runId, string $ownerType, string $ownerId): void
    {
        [$ownerType, $ownerId] = $this->owner($ownerType, $ownerId);
        Cache::put($this->key($runId), [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'cancel_requested' => false,
        ], self::MHC08_TTL_SECONDS);
    }

    public function requestOwned(string $runId, string $ownerType, string $ownerId): bool
    {
        try {
            [$ownerType, $ownerId] = $this->owner($ownerType, $ownerId);
            $key = $this->key($runId);
            $state = Cache::get($key);
            if (! is_array($state)
                || ($state['owner_type'] ?? null) !== $ownerType
                || ($state['owner_id'] ?? null) !== $ownerId) {
                return false;
            }

            $state['cancel_requested'] = true;
            $state['requested_at'] = now()->toIso8601String();
            Cache::put($key, $state, self::MHC08_TTL_SECONDS);

            return true;
        } catch (\Throwable $e) {
            Log::debug('Owned stream cancellation request unavailable', [
                'error_class' => get_class($e),
            ]);

            return false;
        }
    }

    public function requested(string $runId): bool
    {
        try {
            $state = Cache::get($this->key($runId));

            return is_array($state) && ($state['cancel_requested'] ?? false) === true;
        } catch (\Throwable $e) {
            Log::debug('Chat stream cancellation check unavailable', [
                'error_class' => get_class($e),
            ]);

            return false;
        }
    }

    public function clear(string $runId): void
    {
        try {
            Cache::forget($this->key($runId));
        } catch (\Throwable $e) {
            Log::debug('Chat stream cancellation cleanup unavailable', [
                'error_class' => get_class($e),
            ]);
        }
    }

    private function key(string $runId): string
    {
        return 'chat:stream-cancellation:'.hash('sha256', $runId);
    }

    /** @return array{string,string} */
    private function owner(string $ownerType, string $ownerId): array
    {
        $ownerType = trim($ownerType);
        $ownerId = trim($ownerId);
        if (preg_match('/\A[a-z][a-z0-9_]{1,31}\z/', $ownerType) !== 1
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:@-]{0,127}\z/', $ownerId) !== 1) {
            throw new \InvalidArgumentException('Cancellation owner identity is invalid.');
        }

        return [$ownerType, $ownerId];
    }
}
