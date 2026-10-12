<?php

namespace App\Services\UnifiedCli;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/** Interpret metadata separately; never trim or regenerate original instructions. */
final class CliSkillReader
{
    public function __construct(private readonly CliConfiguration $configuration) {}

    public function read(array $session, string $name): array
    {
        $bundle = $this->configuration->skillBundle($session, $name);
        $file = $bundle['files']['SKILL.md'];
        $original = base64_decode($file['base64'], true);
        if ($original === false || ! hash_equals($file['sha256'], hash('sha256', $original))) {
            throw new RuntimeException('Original skill content failed verification.');
        }
        if (! preg_match('/\A(?:\xEF\xBB\xBF)?---[ \t]*\r?\n(.*?)\r?\n---[ \t]*(?:\r?\n|\z)/s', $original, $match)) {
            throw new RuntimeException('The selected PLOS skill requires valid metadata frontmatter.');
        }
        $metadata = Yaml::parse($match[1]);
        if (! is_array($metadata) || ($metadata['name'] ?? null) !== $name) {
            throw new RuntimeException('Skill metadata identity differs from the selected table binding.');
        }

        return ['name' => $name, 'version' => $bundle['version'], 'sha256' => $file['sha256'],
            'instructions' => $original, 'configuration' => $metadata, 'files' => $bundle['files']];
    }
}
