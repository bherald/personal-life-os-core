<?php

namespace App\Services\UnifiedCli;

use App\Services\PipelineHttpClientFactory;
use GuzzleHttp\HandlerStack;
use RuntimeException;

/** Transport boundary: no implicit DNS, redirects or environment proxies on LAN/offline. */
final class CliNetworkBoundary
{
    private readonly array $remoteForwards;

    public function __construct(private readonly array $addressBindings = [], private readonly array $tlsPolicy = [], ?array $remoteForwards = null)
    {
        if ($remoteForwards === null) {
            $encoded = getenv('PLOS_CLI_REMOTE_FORWARDS');
            try {
                $remoteForwards = $encoded === false || $encoded === '' ? [] : json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw new RuntimeException('CLI SSH transport provenance is invalid.');
            }
        }
        if (! is_array($remoteForwards) || ! array_is_list($remoteForwards)) {
            throw new RuntimeException('CLI SSH transport provenance requires an explicit forward list.');
        }
        $ports = [];
        foreach ($remoteForwards as $forward) {
            if (! is_array($forward) || array_diff(array_keys($forward), ['localPort', 'remoteHost', 'remotePort', 'active']) !== []
                || ! is_int($forward['localPort'] ?? null) || $forward['localPort'] < 1 || $forward['localPort'] > 65535
                || ! is_int($forward['remotePort'] ?? null) || $forward['remotePort'] < 1 || $forward['remotePort'] > 65535
                || ! is_string($forward['remoteHost'] ?? null) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9.:-]*$/D', $forward['remoteHost'])
                || ! is_bool($forward['active'] ?? null) || isset($ports[$forward['localPort']])) {
                throw new RuntimeException('CLI SSH transport provenance is invalid.');
            }
            $ports[$forward['localPort']] = true;
        }
        $this->remoteForwards = $remoteForwards;
    }

    public function requestOptions(string $url, string $mode, bool $progressRequired = false): array
    {
        if (! in_array($mode, ['offline', 'lan', 'online'], true) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new RuntimeException('A valid explicit CLI network mode and endpoint are required.');
        }
        $parts = parse_url($url);
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user'], $parts['pass']) || isset($parts['user'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
            throw new RuntimeException('Model endpoint must be an HTTP(S) base URL without credentials, query or fragment.');
        }
        $host = trim($parts['host'], '[]');
        $address = @inet_pton($host);
        $pinned = null;
        if ($address === false && isset($this->addressBindings[strtolower($host)])) {
            $pinned = $this->addressBindings[strtolower($host)];
            $address = is_string($pinned) ? @inet_pton($pinned) : false;
            if ($address === false || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/iD', $host)) {
                throw new RuntimeException('Local hostname binding must name one exact literal address.');
            }
        }
        // Loopback is the transport address, not the model/controller's location.
        // Facts come from the owned host profile, never a model or registry override.
        $policyAddress = $address;
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $localEndpoint = strtolower($host) === 'localhost' || ($address !== false
            && ($this->inSubnet($address, '127.0.0.0', 8) || $this->inSubnet($address, '::1', 128)));
        if ($localEndpoint) {
            foreach ($this->remoteForwards as $forward) {
                if ($forward['localPort'] !== $port) {
                    continue;
                }
                if ($mode === 'offline') {
                    throw new RuntimeException('This loopback endpoint forwards to another computer. Offline mode blocks it; select LAN or online mode explicitly when remote access is intended.');
                }
                if (! $forward['active']) {
                    throw new RuntimeException('The owned SSH forward is unavailable. Other local models, tools and settings remain usable; no alternate listener was contacted.');
                }
                $remote = $forward['remoteHost'];
                $policyAddress = @inet_pton($remote);
                if ($policyAddress === false && isset($this->addressBindings[strtolower($remote)])) {
                    $pin = $this->addressBindings[strtolower($remote)];
                    $policyAddress = is_string($pin) ? @inet_pton($pin) : false;
                }
                break;
            }
        }
        if ($mode !== 'online') {
            if ($policyAddress === false) {
                throw new RuntimeException('LAN/offline endpoints require a literal address or an explicit local hostname binding; no DNS request was made.');
            }
            $loopback = $this->inSubnet($policyAddress, '127.0.0.0', 8) || $this->inSubnet($policyAddress, '::1', 128);
            $lan = $loopback || $this->inSubnet($policyAddress, '10.0.0.0', 8)
                || $this->inSubnet($policyAddress, '172.16.0.0', 12) || $this->inSubnet($policyAddress, '192.168.0.0', 16)
                || $this->inSubnet($policyAddress, '169.254.0.0', 16) || $this->inSubnet($policyAddress, 'fc00::', 7);
            if (! ($mode === 'offline' ? $loopback : $lan)) {
                throw new RuntimeException('Selected endpoint is outside the CLI '.$mode.' network boundary.');
            }
        }

        $tls = (new CliTlsPolicy($this->tlsPolicy))->options($url, $mode);
        $options = ['proxy' => '', 'allow_redirects' => false] + $tls;
        if ($pinned !== null || $progressRequired || isset($tls['curl'])) {
            if (! extension_loaded('curl')) {
                throw new RuntimeException('Pinned or maintained transport requires the local cURL extension. No alternate handler was used.');
            }
            $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
            // Guzzle's default true-streaming handler ignores cURL options.
            // Pin the handler too; the original URI retains Host and TLS identity.
            $options['handler'] = HandlerStack::create(PipelineHttpClientFactory::oneShotHandler());
            $options['curl'] = ($tls['curl'] ?? []) + [CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true];
            if ($pinned !== null) {
                $literal = str_contains($pinned, ':') ? '['.$pinned.']' : $pinned;
                $options['curl'][CURLOPT_RESOLVE] = [$host.':'.$port.':'.$literal];
            }
        }

        return $options;
    }

    /** Preserve the actual remote host when comparing controller/model identity. */
    public function effectiveHost(?string $url): ?string
    {
        $parts = $url === null ? false : parse_url($url);
        if ($parts === false || ! is_string($parts['host'] ?? null) || $parts['host'] === '') {
            return null;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $resolved = $this->addressBindings[$host] ?? $host;
        $address = is_string($resolved) ? @inet_pton($resolved) : false;
        $loopback = $host === 'localhost' || ($address !== false
            && ($this->inSubnet($address, '127.0.0.0', 8) || $this->inSubnet($address, '::1', 128)));
        $port = $parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80);
        if ($loopback) {
            foreach ($this->remoteForwards as $forward) {
                if ($forward['localPort'] === $port) {
                    $remote = strtolower($forward['remoteHost']);

                    return $this->addressBindings[$remote] ?? $remote;
                }
            }
        }

        return is_string($resolved) ? $resolved : null;
    }

    private function inSubnet(string $address, string $network, int $prefix): bool
    {
        $base = inet_pton($network);
        if (strlen($address) !== strlen($base)) {
            return false;
        }
        $bytes = intdiv($prefix, 8);
        if (substr($address, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }
        $bits = $prefix % 8;

        return $bits === 0 || ((ord($address[$bytes]) ^ ord($base[$bytes])) & (255 << (8 - $bits))) === 0;
    }
}
