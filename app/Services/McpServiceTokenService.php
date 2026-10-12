<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use RuntimeException;

/** Administrator-only issuer; no HTTP route or model tool registration. */
class McpServiceTokenService
{
    public const SCOPES = ['mcp.tools.invoke', 'mcp.servers.manage'];

    public const MAX_TTL_SECONDS = 90 * 86400;

    public const EXPIRY_WARNING_SECONDS = 14 * 86400;

    public function __construct(private readonly McpServiceAccessTokenFactory $factory, private readonly PersonalAccessTokenLifecycleService $lifecycle) {}

    public function plan(int $userId, string $name, array $scopes, int $ttlSeconds): array
    {
        if ($userId < 1 || ! DB::table('users')->where('id', $userId)->exists()) {
            throw new RuntimeException('An existing explicit PLOS owner is required.');
        }
        if ($name !== trim($name) || $name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new RuntimeException('A bounded printable token name is required.');
        }
        if ($ttlSeconds < 60 || $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new RuntimeException('Explicit lifetime must be between 60 seconds and 90 days.');
        }
        if ($scopes === [] || count(array_unique($scopes, SORT_REGULAR)) !== count($scopes)
            || array_filter($scopes, fn ($scope) => ! is_string($scope) || ! in_array($scope, self::SCOPES, true) || ! Passport::hasScope($scope)) !== []) {
            throw new RuntimeException('Distinct exact registered MCP scopes are required; wildcard and unrelated scopes are refused.');
        }

        $clients = DB::table('oauth_clients')->where('revoked', false)
            ->where(fn ($query) => $query->where('provider', 'users')->orWhereNull('provider'))->get(['grant_types']);
        if (! $clients->contains(fn ($client) => in_array('personal_access', json_decode($client->grant_types, true) ?: [], true))) {
            throw new RuntimeException('An active users personal-access client must exist before issuing this token.');
        }

        return ['owner_id' => $userId, 'name' => 'mcp-service:'.$name, 'scopes' => $scopes, 'ttl_seconds' => $ttlSeconds];
    }

    public function issue(int $userId, string $name, array $scopes, int $ttlSeconds): array
    {
        $plan = $this->plan($userId, $name, $scopes, $ttlSeconds);
        // Reuse the already bounded private Passport server copy, not a new grant or global TTL.
        $result = $this->factory->makeBounded($userId, $plan['name'], $scopes, $ttlSeconds);
        $id = (string) $result->accessTokenId;
        $bearer = (string) $result->accessToken;
        $expires = (int) $result->expiresIn;
        $stored = DB::table('oauth_access_tokens')->where('id', $id)->where('user_id', $userId)->first(['scopes', 'revoked']);
        $granted = $stored === null ? null : json_decode($stored->scopes, true);
        if ($stored === null || (bool) $stored->revoked || $granted !== $scopes
            || ! preg_match('/^[a-f0-9]{80}$/D', $id) || $bearer === '' || preg_match('/[\x00-\x20\x7f]/', $bearer) || $expires <= 0 || $expires > $ttlSeconds) {
            if ($id !== '') {
                $this->lifecycle->revokeForUser($userId, $id);
            }
            throw new RuntimeException('Passport did not return a complete bounded token.');
        }

        return ['id' => $id, 'owner_id' => $userId, 'access_token' => $bearer, 'scopes' => $scopes,
            'expires_in' => $expires, 'expires_at' => now()->addSeconds($expires)->toIso8601String()];
    }

    public function status(int $userId, string $id): array
    {
        $plan = $this->revocationPlan($userId, $id);
        $row = DB::table('oauth_access_tokens')->where('id', $id)->where('user_id', $userId)->first(['expires_at', 'revoked']);
        $expires = $row->expires_at === null ? null : CarbonImmutable::parse($row->expires_at, 'UTC')->getTimestamp();
        $remaining = $expires === null ? null : $expires - time();
        $state = (bool) $row->revoked ? 'revoked' : ($remaining === null ? 'unknown_expiry'
            : ($remaining <= 0 ? 'expired' : ($remaining <= self::EXPIRY_WARNING_SECONDS ? 'expiring_soon' : 'healthy')));

        return $plan + ['status' => $state, 'expires_at' => $expires === null ? null : gmdate('c', $expires),
            'remaining_seconds' => $remaining, 'warning_days' => 14, 'rotation_required' => $state !== 'healthy', 'automatic_renewal' => false];
    }

    /** Read-only consolidated operations evidence; never resolve the signing factory or bearer. */
    public static function healthSummary(): array
    {
        $rows = DB::table('oauth_access_tokens as t')->join('oauth_clients as c', 'c.id', '=', 't.client_id')
            ->where('t.revoked', false)->where('c.revoked', false)->where('t.name', 'like', 'mcp-service:%')->get(['t.expires_at']);
        $counts = ['active_records' => $rows->count(), 'expired' => 0, 'expiring_soon' => 0, 'unknown_expiry' => 0];
        foreach ($rows as $row) {
            if ($row->expires_at === null) {
                $counts['unknown_expiry']++;

                continue;
            }
            $remaining = CarbonImmutable::parse($row->expires_at, 'UTC')->getTimestamp() - time();
            if ($remaining <= 0) {
                $counts['expired']++;
            } elseif ($remaining <= self::EXPIRY_WARNING_SECONDS) {
                $counts['expiring_soon']++;
            }
        }
        $state = $counts['expired'] + $counts['unknown_expiry'] > 0 ? 'critical'
            : ($counts['expiring_soon'] > 0 ? 'warning' : ($counts['active_records'] === 0 ? 'not_configured' : 'healthy'));

        return ['status' => $state, 'warning_days' => 14, 'automatic_renewal' => false] + $counts;
    }

    public function revocationPlan(int $userId, string $id): array
    {
        if ($userId < 1 || ! preg_match('/^[a-f0-9]{80}$/D', $id)) {
            throw new RuntimeException('An exact owner and token identifier are required.');
        }
        $row = DB::table('oauth_access_tokens')->where('id', $id)->where('user_id', $userId)->first(['name', 'scopes']);
        $scopes = $row === null ? null : json_decode($row->scopes, true);
        if ($row === null || ! str_starts_with((string) $row->name, 'mcp-service:') || ! is_array($scopes)
            || $scopes === [] || array_filter($scopes, fn ($scope) => ! is_string($scope)) !== [] || array_diff($scopes, self::SCOPES) !== []) {
            throw new RuntimeException('No exact MCP service token belongs to that owner.');
        }

        return ['id' => $id, 'owner_id' => $userId, 'scopes' => $scopes];
    }

    public function revoke(int $userId, string $id): bool
    {
        $this->revocationPlan($userId, $id);

        return $this->lifecycle->revokeForUser($userId, $id);
    }
}
