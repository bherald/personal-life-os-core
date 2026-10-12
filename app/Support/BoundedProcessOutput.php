<?php

namespace App\Support;

final class BoundedProcessOutput
{
    public static function format(string $output, int $maxBytes, int $headPercent = 67): string
    {
        $maxBytes = max(1, $maxBytes);
        if (strlen($output) <= $maxBytes) {
            return $output;
        }

        $headPercent = max(10, min(90, $headPercent));
        $headBudget = max(1, intdiv($maxBytes * $headPercent, 100));
        $tailBudget = max(1, $maxBytes - $headBudget);

        $head = mb_strcut($output, 0, $headBudget, 'UTF-8');
        $tail = self::utf8SafeSuffix($output, $tailBudget);
        $omittedBytes = strlen($output) - strlen($head) - strlen($tail);

        $beforeMarker = str_ends_with($head, "\n") ? '' : "\n";
        $afterMarker = str_starts_with($tail, "\n") ? '' : "\n";

        return $head
            .$beforeMarker
            .'...[truncated '.$omittedBytes.' bytes; output tail follows]...'
            .$afterMarker
            .$tail;
    }

    private static function utf8SafeSuffix(string $output, int $maxBytes): string
    {
        $start = max(0, strlen($output) - $maxBytes);
        $length = strlen($output);

        while ($start < $length && (ord($output[$start]) & 0xC0) === 0x80) {
            $start++;
        }

        return substr($output, $start);
    }
}
