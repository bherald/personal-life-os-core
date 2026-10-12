<?php

namespace App\Services;

class AgentSkillReferenceService
{
    private const MAX_REFERENCE_BYTES = 32_000;

    /** @return array{success:bool,reference?:string,content?:string,bytes?:int,sha256?:string,error?:string} */
    public function read(string $reference, string $agentId): array
    {
        if (! SkillLoaderService::isDiscoverableSkillName($agentId)
            || ! app(SkillLoaderService::class)->skillExists($agentId)) {
            return ['success' => false, 'error' => 'Invalid active agent identity.'];
        }

        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,119}\.md$/', $reference) !== 1) {
            return ['success' => false, 'error' => 'Reference must be a single Markdown filename.'];
        }

        $baseDirectory = realpath(SkillLoaderService::configuredSkillsBasePath());
        $configuredSkillDirectory = SkillLoaderService::configuredSkillsBasePath().DIRECTORY_SEPARATOR.$agentId;
        $skillDirectory = realpath($configuredSkillDirectory);
        if (! is_string($baseDirectory) || ! is_string($skillDirectory)
            || is_link($configuredSkillDirectory)
            || ! str_starts_with($skillDirectory, $baseDirectory.DIRECTORY_SEPARATOR)) {
            return ['success' => false, 'error' => 'Active skill bundle was not found.'];
        }

        $referencesDirectory = $skillDirectory.DIRECTORY_SEPARATOR.'references';
        $candidate = $referencesDirectory.DIRECTORY_SEPARATOR.$reference;
        if (is_link($referencesDirectory) || is_link($candidate)) {
            return ['success' => false, 'error' => 'Symlinked skill references are not allowed.'];
        }

        $resolved = realpath($candidate);
        if (! is_string($resolved) || ! is_file($resolved) || ! is_readable($resolved)) {
            return ['success' => false, 'error' => 'Skill reference was not found or is unreadable.'];
        }

        $expectedPrefix = $skillDirectory.DIRECTORY_SEPARATOR.'references'.DIRECTORY_SEPARATOR;
        if (! str_starts_with($resolved, $expectedPrefix)) {
            return ['success' => false, 'error' => 'Skill reference escaped its active bundle.'];
        }

        $bytes = filesize($resolved);
        if (! is_int($bytes) || $bytes > self::MAX_REFERENCE_BYTES) {
            return ['success' => false, 'error' => 'Skill reference exceeds the 32000-byte read limit.'];
        }

        $content = file_get_contents($resolved);
        if (! is_string($content)) {
            return ['success' => false, 'error' => 'Skill reference could not be read.'];
        }

        return [
            'success' => true,
            'reference' => $reference,
            'content' => $content,
            'bytes' => $bytes,
            'sha256' => hash('sha256', $content),
        ];
    }
}
