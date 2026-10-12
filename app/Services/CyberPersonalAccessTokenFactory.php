<?php

namespace App\Services;

use DateInterval;
use Laravel\Passport\Bridge\PersonalAccessGrant;
use Laravel\Passport\PersonalAccessTokenFactory;
use Laravel\Passport\PersonalAccessTokenResult;

/** A private server copy permits one bounded CYBER grant without changing Passport's global TTL. */
final class CyberPersonalAccessTokenFactory extends PersonalAccessTokenFactory
{
    public function __construct(PersonalAccessTokenFactory $factory)
    {
        parent::__construct(clone $factory->server);
    }

    public function makeBounded(int $userId, string $name, array $scopes, int $ttlSeconds): PersonalAccessTokenResult
    {
        if ($ttlSeconds < 60 || $ttlSeconds > 86400) {
            throw new \InvalidArgumentException('CYBER token lifetime must be between 60 and 86400 seconds.');
        }
        $server = clone $this->server;
        $server->enableGrantType(new PersonalAccessGrant, new DateInterval('PT'.$ttlSeconds.'S'));

        return (new PersonalAccessTokenFactory($server))->make($userId, $name, $scopes, 'users');
    }
}
