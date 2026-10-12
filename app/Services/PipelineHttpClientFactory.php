<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\EasyHandle;
use Illuminate\Container\Container;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/** Retain normal PLOS clients while allowing a caller-owned execution scope. */
final class PipelineHttpClientFactory
{
    public const DECORATOR = 'plos.pipeline_http_client_decorator';

    public static function make(array $options = []): ClientInterface
    {
        return self::forRequest(new Client($options));
    }

    public static function forRequest(ClientInterface $client): ClientInterface
    {
        $container = Container::getInstance();
        if (! $container->bound(self::DECORATOR)) {
            return $client;
        }
        $decorate = $container->make(self::DECORATOR);
        if (! is_callable($decorate)) {
            throw new RuntimeException('The caller HTTP execution scope is unavailable.');
        }
        $scoped = $decorate($client);
        if (! $scoped instanceof ClientInterface) {
            throw new RuntimeException('The caller HTTP execution scope did not provide a compatible client.');
        }

        return $scoped;
    }

    public static function oneShotHandler(): CurlHandler
    {
        // The underlying handler may attempt a rewind/retry after rare cURL
        // failures. A second handle claim must stop before another request.
        $factory = new class implements CurlFactoryInterface
        {
            private bool $claimed = false;

            private CurlFactory $inner;

            public function __construct()
            {
                $this->inner = new CurlFactory(1);
            }

            public function create(RequestInterface $request, array $options): EasyHandle
            {
                if ($this->claimed) {
                    throw new RuntimeException('Pinned transport cannot replay a request. Recheck current admission before another operation.');
                }
                $this->claimed = true;

                return $this->inner->create($request, $options);
            }

            public function release(EasyHandle $easy): void
            {
                $this->inner->release($easy);
            }
        };

        return new CurlHandler(['handle_factory' => $factory]);
    }
}
