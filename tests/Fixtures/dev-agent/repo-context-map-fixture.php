<?php

namespace Tests\Fixtures\DevAgent;

final class NebulaContextTarget
{
    public function resolveNebulaSignal(): string
    {
        $types = [self::class, OtherThing::class];

        return 'source-body-must-not-be-returned';
    }
}
