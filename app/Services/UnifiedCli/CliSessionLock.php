<?php

namespace App\Services\UnifiedCli;

use RuntimeException;

/** OS-owned locks release on worker death; they never authorize action replay. */
final class CliSessionLock
{
    private mixed $handle;

    public function __construct(string $directory, string $sessionId)
    {
        if (! preg_match('/^[a-f0-9-]{36}$/D', $sessionId)) {
            throw new RuntimeException('Invalid session identifier.');
        }
        $directory .= '/locks';
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create private session lock directory.');
        }
        if (is_link($directory)) {
            throw new RuntimeException('Session lock directory must not be a symlink.');
        }
        $path = $directory.'/'.$sessionId.'.lock';
        if (is_link($path)) {
            throw new RuntimeException('Session lock must not be a symlink.');
        }
        $this->handle = fopen($path, 'c');
        if ($this->handle === false) {
            throw new RuntimeException('Cannot open session lock.');
        }
        chmod($path, 0600);
        if (! flock($this->handle, LOCK_EX | LOCK_NB)) {
            fclose($this->handle);
            $this->handle = null;
            throw new RuntimeException('This session has an active worker. Wait for its turn to finish.');
        }
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
