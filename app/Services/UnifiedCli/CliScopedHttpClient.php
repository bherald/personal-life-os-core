<?php

namespace App\Services\UnifiedCli;

use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\ClientTrait;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Apply the same HTTP policy even when a pipeline call supplies its own handler. */
final class CliScopedHttpClient implements ClientInterface
{
    use ClientTrait;

    public function __construct(private readonly ClientInterface $inner, private readonly Closure $middleware) {}

    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        return $this->inner->send($request, $this->options($options));
    }

    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        return $this->inner->sendAsync($request, $this->options($options));
    }

    public function request(string $method, $uri, array $options = []): ResponseInterface
    {
        return $this->inner->request($method, $uri, $this->options($options));
    }

    public function requestAsync(string $method, $uri, array $options = []): PromiseInterface
    {
        return $this->inner->requestAsync($method, $uri, $this->options($options));
    }

    public function getConfig(?string $option = null)
    {
        return $this->inner->getConfig($option);
    }

    private function options(array $options): array
    {
        $handler = $options['handler'] ?? $this->inner->getConfig('handler');
        if (! is_callable($handler)) {
            throw new RuntimeException('The pipeline client does not expose a compatible scoped HTTP handler.');
        }
        $stack = $handler instanceof HandlerStack ? clone $handler : HandlerStack::create($handler);
        $stack->push($this->middleware, 'plos-workflow-network');

        return array_replace($options, ['handler' => $stack]);
    }
}
