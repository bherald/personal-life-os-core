<?php

namespace App\Services;

use DateInterval;
use Laravel\Passport\Bridge\PersonalAccessGrant;
use Laravel\Passport\PersonalAccessTokenFactory;
use Laravel\Passport\PersonalAccessTokenResult;

/** The CYBER helper caps one day; service automation needs an independent bounded grant lifetime. */
final class McpServiceAccessTokenFactory extends PersonalAccessTokenFactory
{
    public function __construct(PersonalAccessTokenFactory $factory)
    {
        parent::__construct(clone $factory->server);
    }

    public function makeBounded(int $userId, string $name, array $scopes, int $ttlSeconds): PersonalAccessTokenResult
    {
        if ($ttlSeconds < 60 || $ttlSeconds > McpServiceTokenService::MAX_TTL_SECONDS) {
            throw new \InvalidArgumentException('MCP service lifetime must be between 60 seconds and 90 days.');
        }
        $server = clone $this->server;
        $server->enableGrantType(new PersonalAccessGrant, new DateInterval('PT'.$ttlSeconds.'S'));

        return (new PersonalAccessTokenFactory($server))->make($userId, $name, $scopes, 'users');
    }
}
