<?php

namespace App\Services\Genealogy;

function file_get_contents(string $filename, bool $useIncludePath = false, mixed $context = null, int $offset = 0, ?int $length = null): string|false
{
    $GLOBALS['plos_htr_upload_source_reads'] = ($GLOBALS['plos_htr_upload_source_reads'] ?? 0) + 1;
    if ($GLOBALS['plos_htr_upload_forbid_read'] ?? false) {
        throw new \RuntimeException('Forbidden fixture source read.');
    }

    return $length === null ? \file_get_contents($filename, $useIncludePath, $context, $offset)
        : \file_get_contents($filename, $useIncludePath, $context, $offset, $length);
}

function sleep(int $seconds): int
{
    return ($GLOBALS['plos_htr_upload_fake_sleep'] ?? false) ? 0 : \sleep($seconds);
}
