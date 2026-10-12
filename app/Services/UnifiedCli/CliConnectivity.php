<?php

namespace App\Services\UnifiedCli;

use InvalidArgumentException;

/** Small operator adapter for the existing routing switches and network boundary. */
final class CliConnectivity
{
    public static function setting(string $control, string $choice): array
    {
        if (! in_array($control, ['internet', 'cloud'], true) || ! in_array($choice, ['on', 'off'], true)) {
            throw new InvalidArgumentException('Choose internet or cloud, followed by on or off.');
        }

        return $control === 'internet'
            ? ['routing.internet_access', $choice === 'on' ? 'enabled' : 'disabled']
            : ['routing.offline_mode', $choice === 'on' ? 'disabled' : 'enabled'];
    }

    public static function toolNetwork(array $values): string
    {
        $network = $values['cli.network'] ?? 'offline';

        return $network === 'online' && ($values['routing.internet_access'] ?? 'enabled') !== 'enabled' ? 'lan' : $network;
    }

    public static function describe(array $values): array
    {
        return ['network' => $values['cli.network'] ?? 'offline',
            'internet' => ($values['routing.internet_access'] ?? 'enabled') === 'enabled' ? 'on' : 'off',
            'cloud' => ($values['routing.offline_mode'] ?? 'disabled') === 'disabled' ? 'on' : 'off',
            'note' => 'Independent routing switches. Offline/LAN boundaries, provider profiles, tool permissions and approval requirements still apply. On permits consideration; it does not grant publication or data authority.'];
    }
}
