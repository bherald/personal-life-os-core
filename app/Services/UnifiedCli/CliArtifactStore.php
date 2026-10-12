<?php

namespace App\Services\UnifiedCli;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/** Content-addressed original bytes. Derived media always receives a new object. */
final class CliArtifactStore
{
    private string $directory;

    public function __construct(string $directory)
    {
        if (is_link($directory)) {
            throw new RuntimeException('Artifact directory cannot be a symlink.');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create private artifact directory.');
        }
        $this->directory = realpath($directory);
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($this->directory) & 0077) !== 0) {
            throw new RuntimeException('Artifact directory must be private (0700).');
        }
    }

    public function importFile(string $path, array $allowedRoots): array
    {
        $source = realpath($path);
        if ($source === false || ! is_file($source) || ! $this->withinRoots($source, $allowedRoots)) {
            throw new InvalidArgumentException('Attachment must be a regular file inside the selected workspace roots.');
        }
        $stream = fopen($source, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read attachment.');
        }
        try {
            $before = fstat($stream);
            $artifact = $this->capture($stream);
            $after = fstat($stream);
            if ($before['ino'] !== $after['ino'] || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime'] || $artifact['bytes'] !== $after['size']) {
                throw new RuntimeException('Source file changed during capture; select a stable file and retry.');
            }

            return array_merge($artifact, ['kind' => 'original', 'name' => basename($source), 'source_path' => $source]);
        } finally {
            fclose($stream);
        }
    }

    public function derived(string $parentHash, string $bytes, string $operation): array
    {
        $this->verifiedPath($parentHash);
        if ($operation === '') {
            throw new InvalidArgumentException('Derived artifacts require operation provenance.');
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Cannot stage derived artifact.');
        }
        try {
            $this->writeAll($stream, $bytes);
            rewind($stream);

            return array_merge($this->capture($stream), ['kind' => 'derived', 'parent_sha256' => $parentHash, 'operation' => $operation]);
        } finally {
            fclose($stream);
        }
    }

    /** Store original transport bytes privately, without exposing them as a prompt. */
    public function captureBytes(string $bytes): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Cannot stage original artifact.');
        }
        try {
            $this->writeAll($stream, $bytes);
            rewind($stream);

            return array_merge($this->capture($stream), ['kind' => 'original']);
        } finally {
            fclose($stream);
        }
    }

    /** Capture all bytes from the stream's current position without truncation. */
    public function captureStream($stream): array
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Original artifact capture requires a readable stream.');
        }

        return array_merge($this->capture($stream), ['kind' => 'original']);
    }

    public function read(string $hash): string
    {
        $bytes = file_get_contents($this->verifiedPath($hash));
        if ($bytes === false || ! hash_equals($hash, hash('sha256', $bytes))) {
            throw new RuntimeException('Artifact integrity check failed.');
        }

        return $bytes;
    }

    /** Let an existing file reader inspect verified captured bytes, never the mutable source. */
    public function inspect(string $hash, Closure $reader): mixed
    {
        $path = $this->verifiedPath($hash);
        try {
            return $reader($path);
        } finally {
            $this->verifiedPath($hash);
        }
    }

    /** Publish complete bytes to a new workspace file; existing paths never change. */
    public function export(string $hash, string $destination, array $allowedRoots): array
    {
        $source = $this->verifiedPath($hash);
        $parent = realpath(dirname($destination));
        $name = basename($destination);
        if ($parent === false || ! is_dir($parent) || in_array($name, ['', '.', '..'], true)
            || ! $this->withinRoots($parent.DIRECTORY_SEPARATOR.$name, $allowedRoots)) {
            throw new InvalidArgumentException('Export requires an existing destination directory inside the workspace.');
        }
        $target = $parent.DIRECTORY_SEPARATOR.$name;
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('Export destination already exists. Choose a new path.');
        }
        $temporary = $parent.'/.plos-export-'.bin2hex(random_bytes(16));
        $output = fopen($temporary, 'xb');
        if ($output === false) {
            throw new RuntimeException('Cannot stage the output file.');
        }
        chmod($temporary, 0600);
        $input = null;
        try {
            $input = fopen($source, 'rb');
            if ($input === false || stream_copy_to_stream($input, $output) === false
                || ! fflush($output) || ! fsync($output)
                || ! hash_equals($hash, (string) hash_file('sha256', $temporary))) {
                throw new RuntimeException('Complete output could not be verified; no export published.');
            }
            fclose($output);
            $output = null;
            if (! @link($temporary, $target)) {
                throw new RuntimeException('Cannot publish output without overwriting an existing path.');
            }

            return ['path' => $target, 'sha256' => $hash, 'bytes' => filesize($target)];
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function capture($stream): array
    {
        $temporary = $this->directory.'/.capture-'.bin2hex(random_bytes(16));
        $output = fopen($temporary, 'xb');
        if ($output === false) {
            throw new RuntimeException('Cannot stage complete artifact.');
        }
        chmod($temporary, 0600);
        $context = hash_init('sha256');
        $size = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Artifact read failed; incomplete data was not admitted.');
                }
                hash_update($context, $chunk);
                $this->writeAll($output, $chunk);
                $size += strlen($chunk);
            }
            if (! fflush($output) || ! fsync($output)) {
                throw new RuntimeException('Cannot persist complete artifact.');
            }
            fclose($output);
            $output = null;
            $hash = hash_final($context);
            $target = $this->directory.'/'.$hash;
            if (file_exists($target) || is_link($target)) {
                $this->verifiedPath($hash);
            } elseif (! link($temporary, $target)) {
                // A competing capture may have installed the same immutable bytes.
                $this->verifiedPath($hash);
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($target);

            return ['sha256' => $hash, 'bytes' => $size, 'mime_type' => $mime ?: 'application/octet-stream'];
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function verifiedPath(string $hash): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new InvalidArgumentException('Invalid artifact identity.');
        }
        $path = $this->directory.'/'.$hash;
        if (is_link($path) || ! is_file($path) || ! hash_equals($hash, (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Artifact is missing or its stored bytes changed.');
        }

        return $path;
    }

    private function withinRoots(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            $canonical = realpath($root);
            if ($canonical !== false && is_dir($canonical) && str_starts_with($path, rtrim($canonical, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Artifact storage is incomplete.');
            }
            $offset += $written;
        }
    }
}
