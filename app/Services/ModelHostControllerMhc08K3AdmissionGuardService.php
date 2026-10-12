<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Request-scoped nonce gate for the temporarily opened MHC-08 K3 provider. */
class ModelHostControllerMhc08K3AdmissionGuardService
{
    private const TTL_SECONDS = 25200;

    public function issue(string $requestId): string
    {
        $requestId = $this->requestId($requestId);
        $token = bin2hex(random_bytes(32));
        if (! Cache::add($this->key($requestId), hash('sha256', $token), self::TTL_SECONDS)) {
            throw new RuntimeException('MHC-08 K3 admission guard is already active for this request.');
        }

        return $token;
    }

    public function assert(string $requestId, string $token): void
    {
        $requestId = $this->requestId($requestId);
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            throw new RuntimeException('MHC-08 K3 admission guard token is invalid.');
        }
        $expected = Cache::get($this->key($requestId));
        if (! is_string($expected) || ! hash_equals($expected, hash('sha256', $token))) {
            throw new RuntimeException('MHC-08 K3 request is outside its exact admission scope.');
        }
    }

    public function revoke(string $requestId): void
    {
        Cache::forget($this->key($this->requestId($requestId)));
    }

    private function requestId(string $requestId): string
    {
        $requestId = trim($requestId);
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,110}\z/', $requestId) !== 1) {
            throw new RuntimeException('MHC-08 K3 admission guard request identity is invalid.');
        }

        return $requestId;
    }

    private function key(string $requestId): string
    {
        return 'model_host_controller:mhc08:k3:admission:'.hash('sha256', $requestId);
    }
}
