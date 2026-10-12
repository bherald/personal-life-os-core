<?php

namespace App\Services\UnifiedCli;

use RuntimeException;

/** An operator-selected, administrator-owned bubblewrap installation. */
final class CliLinuxSandbox
{
    public static function executable(): string
    {
        $selected = getenv('PLOS_CLI_BWRAP');
        $path = $selected === false ? '/usr/bin/bwrap' : $selected;
        if (PHP_OS_FAMILY !== 'Linux' || ! str_starts_with($path, '/') || str_contains($path, "\0")
            || ! is_file($path) || ! is_executable($path)) {
            throw new RuntimeException('Selected Linux bubblewrap installation is unavailable; no alternate sandbox was selected.');
        }
        // An AppArmor attachment must not name an operator-replaceable binary.
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new RuntimeException('Selected Linux bubblewrap path cannot be resolved.');
        }
        foreach (array_unique([$path, $resolved]) as $entry) {
            do {
                $info = lstat($entry);
                if ($info === false || $info['uid'] !== 0 || (($info['mode'] & 0022) !== 0 && ! is_link($entry))) {
                    throw new RuntimeException('Selected Linux bubblewrap and its parent directories must be administrator-owned and protected from writes.');
                }
                $parent = dirname($entry);
                if ($entry === $parent) {
                    break;
                }
                $entry = $parent;
            } while (true);
        }

        return $resolved;
    }
}
