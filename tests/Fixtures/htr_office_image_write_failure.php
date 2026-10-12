<?php

namespace App\Services;

// Failure injection only for the disposable HTR Office image inventory.
function file_put_contents(string $filename, mixed $data, int $flags = 0, mixed $context = null): int|false
{
    if (($GLOBALS['plos_htr_fixture_write_failure'] ?? false) && str_contains($filename, '/app/temp/office_')) {
        return false;
    }

    return $context === null ? \file_put_contents($filename, $data, $flags) : \file_put_contents($filename, $data, $flags, $context);
}
