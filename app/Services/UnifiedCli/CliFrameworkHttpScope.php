<?php

namespace App\Services\UnifiedCli;

use App\Services\CodexExecConnectorService;
use App\Services\OfflinePolicyService;
use App\Services\PipelineHttpClientFactory;
use Closure;
use GuzzleHttp\ClientInterface;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/** Apply operator connectivity choices to the existing workflow HTTP client. */
final class CliFrameworkHttpScope
{
    public static function validate(array $policy): void
    {
        if (array_diff(array_keys($policy), ['mode', 'host_addresses']) !== []
            || ! in_array($policy['mode'] ?? null, ['offline', 'lan', 'online'], true)
            || ! is_array($policy['host_addresses'] ?? null)) {
            throw new RuntimeException('Workflow network settings require a valid mode and hostname bindings.');
        }
        foreach ($policy['host_addresses'] as $host => $address) {
            if (! is_string($host) || $host !== strtolower($host) || @inet_pton($host) !== false
                || ! is_string($address) || @inet_pton($address) === false
                || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $host)) {
                throw new RuntimeException('Workflow hostname bindings require lowercase names and literal IP addresses.');
            }
        }
    }

    public function run(array $policy, Closure $operation): mixed
    {
        self::validate($policy);
        if ($policy['mode'] === 'online' && $policy['host_addresses'] === []
            && ! (new OfflinePolicyService)->isOfflineModeActive()) {
            return $operation();
        }
        $original = Http::getFacadeRoot();
        if (! $original instanceof Factory) {
            throw new RuntimeException('The existing PLOS HTTP client is unavailable.');
        }
        // A call-scoped clone preserves existing middleware/options. Restoring
        // the original also prevents settings leaking to another worker call.
        $scoped = clone $original;
        $scoped->globalMiddleware($this->middleware($policy));
        $container = Container::getInstance();
        $hadProcessGuard = $container->bound(CodexExecConnectorService::EXECUTION_GUARD);
        $previousProcessGuard = $hadProcessGuard ? $container->make(CodexExecConnectorService::EXECUTION_GUARD) : null;
        if ($hadProcessGuard && ! is_callable($previousProcessGuard)) {
            throw new RuntimeException('The existing Codex execution policy is unavailable.');
        }
        $hadDecorator = $container->bound(PipelineHttpClientFactory::DECORATOR);
        $previousDecorator = $hadDecorator ? $container->make(PipelineHttpClientFactory::DECORATOR) : null;
        if ($hadDecorator && ! is_callable($previousDecorator)) {
            throw new RuntimeException('The existing pipeline HTTP execution scope is unavailable.');
        }
        $container->instance(PipelineHttpClientFactory::DECORATOR,
            fn (ClientInterface $client): ClientInterface => new CliScopedHttpClient(
                $hadDecorator ? $previousDecorator($client) : $client, $this->middleware($policy)));
        $container->instance(CodexExecConnectorService::EXECUTION_GUARD, function () use ($policy, $previousProcessGuard): void {
            if ($previousProcessGuard !== null) {
                $previousProcessGuard();
            }
            if ((new OfflinePolicyService)->isOfflineModeActive()) {
                throw new RuntimeException('Cloud inference is disabled by routing.offline_mode; Codex execution was not started.');
            }
            if ($policy['mode'] !== 'online') {
                throw new RuntimeException('Codex requires internet access. The selected CLI network mode permits no external LLM connection.');
            }
        });
        Http::swap($scoped);
        try {
            return $operation();
        } finally {
            Http::swap($original);
            if ($hadProcessGuard) {
                $container->instance(CodexExecConnectorService::EXECUTION_GUARD, $previousProcessGuard);
            } else {
                $container->offsetUnset(CodexExecConnectorService::EXECUTION_GUARD);
            }
            if ($hadDecorator) {
                $container->instance(PipelineHttpClientFactory::DECORATOR, $previousDecorator);
            } else {
                $container->offsetUnset(PipelineHttpClientFactory::DECORATOR);
            }
        }
    }

    public function middleware(array $policy): Closure
    {
        self::validate($policy);
        $boundary = new CliNetworkBoundary($policy['host_addresses']);

        return fn (callable $next): Closure => function (RequestInterface $request, array $options) use ($next, $boundary, $policy) {
            if ($policy['mode'] === 'online' && ! array_key_exists(strtolower($request->getUri()->getHost()), $policy['host_addresses'])) {
                return $next($request, $options);
            }
            // Validate the endpoint separately; preserve the original query,
            // body, headers and URI in the actual request.
            $endpoint = (string) $request->getUri()->withQuery('')->withFragment('');
            $network = $boundary->requestOptions($endpoint, $policy['mode']);
            if ($policy['mode'] !== 'online' || isset($network['handler'])) {
                // A service's cURL options must not redirect a scoped request
                // to a different endpoint or re-enable an environment proxy.
                foreach (['CURLOPT_URL', 'CURLOPT_PORT', 'CURLOPT_PROXY', 'CURLOPT_PRE_PROXY', 'CURLOPT_PROXYPORT',
                    'CURLOPT_CONNECT_TO', 'CURLOPT_RESOLVE', 'CURLOPT_UNIX_SOCKET_PATH', 'CURLOPT_ABSTRACT_UNIX_SOCKET',
                    'CURLOPT_FOLLOWLOCATION'] as $name) {
                    if (defined($name)) {
                        unset($options['curl'][constant($name)]);
                    }
                }
            }
            if (isset($network['handler'])) {
                // Laravel's normal Guzzle handler selects PHP streams when
                // stream=true, which ignores cURL address pins. Use its cURL
                // branch while retaining Laravel's recorder, stubs, callbacks
                // and response middleware. Sink/body bytes remain unchanged.
                $network['stream'] = false;
            }
            unset($network['handler']);
            if (isset($network['curl'])) {
                $network['curl'] = array_replace($options['curl'] ?? [], $network['curl']);
            }

            return $next($request, array_replace($options, $network));
        };
    }
}
