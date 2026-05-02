<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class TrustEnvelope
{
    public function __construct(
        public string $sourceType,
        public string $contentType,
        public string $origin,
        public string $trustLevel = 'low',
        public string $payload = '',
        public int $maxChars = 10000,
    ) {}
}
