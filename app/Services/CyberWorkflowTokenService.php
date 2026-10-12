<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Administrative CLI-only capability; not registered as a model tool or public issuance endpoint. */
final class CyberWorkflowTokenService
{
    public const SCOPES = ['cyber.jobs.read', 'cyber.jobs.submit', 'cyber.jobs.cancel', 'cyber.jobs.resume'];

    public function __construct(private CyberPersonalAccessTokenFactory $factory, private PersonalAccessTokenLifecycleService $lifecycle) {}

    public function issue(int $userId, string $name, array $scopes, int $ttlSeconds): array
    {
        if ($userId < 1 || ! DB::table('users')->where('id', $userId)->exists()) {
            throw new RuntimeException('An existing explicit PLOS owner is required.');
        }
        if ($name !== trim($name) || $name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new RuntimeException('A bounded printable token name is required.');
        }
        if ($scopes === [] || array_filter($scopes, fn ($scope) => ! is_string($scope) || ! in_array($scope, self::SCOPES, true)) !== [] || count(array_unique($scopes)) !== count($scopes)) {
            throw new RuntimeException('Supply distinct exact CYBER scopes; wildcard and unrelated scopes are refused.');
        }
        $result = $this->factory->makeBounded($userId, 'cyber-workflow:'.$name, $scopes, $ttlSeconds);
        $id = (string) $result->accessTokenId;
        $bearer = (string) $result->accessToken;
        if ($id === '' || $bearer === '' || (int) $result->expiresIn <= 0 || (int) $result->expiresIn > $ttlSeconds) {
            if ($id !== '') {
                $this->lifecycle->revokeForUser($userId, $id);
            }
            throw new RuntimeException('Passport did not return a complete bounded token.');
        }

        return ['id' => $id, 'owner_id' => $userId, 'access_token' => $bearer, 'scopes' => $scopes, 'expires_in' => (int) $result->expiresIn, 'expires_at' => now()->addSeconds((int) $result->expiresIn)->toIso8601String()];
    }

    public function revoke(int $userId, string $tokenId): bool
    {
        // Include expired tokens; lifecycle revocation still verifies owner and personal-access grant.
        $row = DB::table('oauth_access_tokens')->where('id', $tokenId)->where('user_id', $userId)->first(['name', 'scopes']);
        $scopes = $row ? json_decode($row->scopes, true) : null;
        if ($row === null || ! str_starts_with((string) $row->name, 'cyber-workflow:') || ! is_array($scopes) || $scopes === [] || array_diff($scopes, self::SCOPES) !== []) {
            return false;
        }

        return $this->lifecycle->revokeForUser($userId, $tokenId);
    }
}
