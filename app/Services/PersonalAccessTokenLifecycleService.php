<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\PersonalAccessTokenFactory;
use RuntimeException;

class PersonalAccessTokenLifecycleService
{
    public const OPS_STATUS_SCOPE = 'ops.status.read';

    public function __construct(
        private readonly PersonalAccessTokenFactory $factory,
        private readonly ?string $connectionName = null,
    ) {}

    public function listForUser(int $userId): array
    {
        $rows = $this->connection()->select(
            <<<'SQL'
                SELECT
                    t.id,
                    t.name,
                    t.scopes,
                    t.created_at,
                    t.expires_at,
                    c.grant_types
                FROM oauth_access_tokens t
                INNER JOIN oauth_clients c ON c.id = t.client_id
                WHERE t.user_id = ?
                  AND t.revoked = 0
                  AND c.revoked = 0
                  AND (t.expires_at IS NULL OR t.expires_at > ?)
                ORDER BY t.created_at DESC, t.id ASC
                SQL,
            [$userId, now()->format('Y-m-d H:i:s')],
        );

        $tokens = [];

        foreach ($rows as $row) {
            if (! $this->hasGrant($row->grant_types ?? null, 'personal_access')) {
                continue;
            }

            $scopes = $this->decodeStringList($row->scopes ?? null);
            $tokens[] = [
                'id' => (string) $row->id,
                'name' => (string) ($row->name ?: 'Unnamed Token'),
                'scopes' => $scopes,
                'ops_status_access' => in_array(self::OPS_STATUS_SCOPE, $scopes, true)
                    && ! in_array('*', $scopes, true),
                'created_at' => $row->created_at,
                'expires_at' => $row->expires_at,
            ];
        }

        return $tokens;
    }

    public function issueForUser(int $userId, string $name): array
    {
        $result = $this->factory->make(
            $userId,
            $name,
            [self::OPS_STATUS_SCOPE],
            'users',
        );

        $id = (string) $result->accessTokenId;
        $accessToken = (string) $result->accessToken;
        $expiresIn = (int) $result->expiresIn;

        if ($id === '' || $accessToken === '' || $expiresIn <= 0) {
            throw new RuntimeException('Passport returned an incomplete personal access token result.');
        }

        return [
            'id' => $id,
            'access_token' => $accessToken,
            'token_type' => (string) ($result->tokenType ?: 'Bearer'),
            'scopes' => [self::OPS_STATUS_SCOPE],
            'expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
        ];
    }

    public function revokeForUser(int $userId, string $tokenId): bool
    {
        $row = $this->connection()->selectOne(
            <<<'SQL'
                SELECT t.id, c.grant_types, c.revoked AS client_revoked
                FROM oauth_access_tokens t
                INNER JOIN oauth_clients c ON c.id = t.client_id
                WHERE t.id = ?
                  AND t.user_id = ?
                  AND t.revoked = 0
                LIMIT 1
                SQL,
            [$tokenId, $userId],
        );

        if ($row === null
            || (bool) $row->client_revoked
            || ! $this->hasGrant($row->grant_types ?? null, 'personal_access')) {
            return false;
        }

        return $this->connection()->update(
            'UPDATE oauth_access_tokens SET revoked = 1, updated_at = ? WHERE id = ? AND user_id = ? AND revoked = 0',
            [now()->format('Y-m-d H:i:s'), $tokenId, $userId],
        ) === 1;
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection($this->connectionName);
    }

    private function hasGrant(mixed $value, string $grant): bool
    {
        return in_array($grant, $this->decodeStringList($value), true);
    }

    private function decodeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
