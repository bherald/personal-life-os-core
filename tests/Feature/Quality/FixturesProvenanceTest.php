<?php

namespace Tests\Feature\Quality;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class FixturesProvenanceTest extends TestCase
{
    private const MAX_FIXTURE_BYTES = 2_000_000;

    private const FORBIDDEN_PATTERNS = [
        '/Herald/i',
        '/Mancini/i',
        '/b'.'herald/i',
        '~/MASTER/'.'FT~i',
        '~/MASTER/'.'Family Tree~i',
        '~/home/'.'bill~i',
        '/192\.168\.'.'8\./',
    ];

    #[Test]
    public function public_fixtures_have_provenance_rows(): void
    {
        $fixturesDir = base_path('tests/Fixtures');
        $provenancePath = $fixturesDir.'/PROVENANCE.md';

        $this->assertFileExists($provenancePath);

        $provenance = (string) file_get_contents($provenancePath);
        $missing = [];

        foreach ($this->fixtureFiles($fixturesDir) as $relativePath => $absolutePath) {
            if ($relativePath === 'PROVENANCE.md') {
                continue;
            }

            if (! str_contains($provenance, '`'.$relativePath.'`')) {
                $missing[] = $relativePath;
            }

            $this->assertLessThanOrEqual(
                self::MAX_FIXTURE_BYTES,
                filesize($absolutePath),
                "{$relativePath} exceeds the public fixture size ceiling"
            );
        }

        $this->assertEmpty(
            $missing,
            "Tracked public fixtures missing PROVENANCE.md rows:\n  ".implode("\n  ", $missing)
        );
    }

    #[Test]
    public function public_fixtures_do_not_contain_private_tokens(): void
    {
        $fixturesDir = base_path('tests/Fixtures');
        $offenders = [];

        foreach ($this->fixtureFiles($fixturesDir) as $relativePath => $absolutePath) {
            if ($relativePath === 'PROVENANCE.md') {
                continue;
            }

            $body = (string) file_get_contents($absolutePath);
            foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                if (preg_match($pattern, $body)) {
                    $offenders[] = "{$relativePath} matches {$pattern}";
                }
            }
        }

        $this->assertEmpty(
            $offenders,
            "Private tokens found in public fixtures:\n  ".implode("\n  ", $offenders)
        );
    }

    /**
     * @return array<string, string>
     */
    private function fixtureFiles(string $fixturesDir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fixturesDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $relativePath = str_replace($fixturesDir.'/', '', $file->getPathname());
            $files[$relativePath] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }
}
