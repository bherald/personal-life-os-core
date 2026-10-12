<?php

namespace App\Services\UnifiedCli;

use RuntimeException;
use Throwable;

/** Native presentation of existing PLOS definitions; never discovers or starts a runtime. */
final class CliNativeCatalog
{
    public function __construct(private readonly CliConfiguration $configuration, private readonly string $stateDirectory) {}

    public function skills(array $session, string $workspace): array
    {
        $skills = $errors = [];
        foreach ($this->configuration->skills($session, includeDisabled: true) as $row) {
            try {
                if (! $row['version_matches']) {
                    throw new RuntimeException('No complete skill bundle matches the active table version.');
                }
                $skill = (new CliSkillReader($this->configuration))->read($session, $row['name']);
                $skills[] = ['name' => $skill['name'], 'description' => (string) ($skill['configuration']['description'] ?? $skill['name']),
                    'path' => $this->materialize($skill), 'scope' => 'user', 'enabled' => $row['active'], 'pluginId' => null];
            } catch (Throwable $error) {
                $errors[] = ['path' => $this->stateDirectory.'/skills/'.$row['name'].'/SKILL.md', 'message' => $error->getMessage()];
            }
        }

        return ['data' => [['cwd' => $workspace, 'skills' => $skills, 'errors' => $errors]]];
    }

    public function select(array $session, array $input): array
    {
        $selection = null;
        foreach ($input as $item) {
            if (($item['type'] ?? null) !== 'skill') {
                continue;
            }
            if (! is_string($item['name'] ?? null) || ! is_string($item['path'] ?? null)
                || ($selection !== null && $selection !== $item['name'])) {
                throw new RuntimeException('Select one PLOS skill per turn; use the native picker or /skill.');
            }
            $skill = (new CliSkillReader($this->configuration))->read($session, $item['name']);
            if ($item['path'] !== $this->materialize($skill)) {
                throw new RuntimeException('Native skill selection differs from the current saved bundle. Refresh the picker; no model was invoked.');
            }
            $selection = $item['name'];
        }

        return $selection === null ? $session : $this->configuration->set($session, 'cli.skill', $selection);
    }

    public function mcp(array $session): array
    {
        $servers = (new CliMcpServerDefinitions)->forSession($this->configuration, $session);
        $catalog = new CliToolCatalog($this->configuration);
        $definitions = $catalog->definitions($session);
        $result = [];
        foreach ($servers as $name => $server) {
            $tools = [];
            foreach ($definitions as $toolName => $definition) {
                if (($definition['mcp_server'] ?? null) !== $name || ! $server['enabled']) {
                    continue;
                }
                $tools[$toolName] = ['name' => $toolName, 'description' => $definition['description'] ?? $toolName,
                    'inputSchema' => $catalog->schema($definition['parameters'])];
            }
            $result[] = ['name' => $name, 'runtimeStatus' => $server['enabled'] ? 'notStarted' : 'disabled',
                'pluginId' => null, 'serverInfo' => null, 'tools' => (object) $tools,
                'resources' => [], 'resourceTemplates' => [], 'authStatus' => 'unknown'];
        }

        return ['data' => $result, 'nextCursor' => null];
    }

    public function validateSelection(array $session, array $item): void
    {
        $name = $this->configuration->selectedSkillName($session);
        if (! is_string($name) || ($item['name'] ?? null) !== $name
            || ($item['path'] ?? null) !== $this->materialize((new CliSkillReader($this->configuration))->read($session, $name))) {
            throw new RuntimeException('Native skill input does not match the selected PLOS bundle.');
        }
    }

    public function toggle(array $session, array $params): array
    {
        if (! is_bool($params['enabled'] ?? null) || (! is_string($params['name'] ?? null) && ! is_string($params['path'] ?? null))) {
            throw new RuntimeException('Skill toggle requires an exact saved skill selector and enabled boolean.');
        }
        $matches = [];
        $name = $params['name'] ?? basename(dirname($params['path']));
        foreach ($this->configuration->skills($session, includeDisabled: true) as $row) {
            if ($name !== $row['name']) {
                continue;
            }
            $skill = (new CliSkillReader($this->configuration))->read($session, $row['name']);
            if (isset($params['path']) && $params['path'] !== $this->materialize($skill)) {
                continue;
            }
            $matches[] = $row;
        }
        if (count($matches) !== 1 || ! ctype_digit((string) $matches[0]['registry_id'])) {
            throw new RuntimeException('Native skill toggle requires one exact table row with a synchronized ID.');
        }
        $row = $matches[0];
        $enabled = $params['enabled'];
        $value = is_bool($row['active_value']) ? $enabled : (is_int($row['active_value']) ? (int) $enabled : ($enabled ? '1' : '0'));

        return $this->configuration->set($session, 'registry.skill_versions.#'.$row['registry_id'].'.is_active', $value);
    }

    private function materialize(array $skill): string
    {
        // The complete asset manifest contributes to identity, including files
        // other than SKILL.md. Prior session versions remain available.
        $hash = hash('sha256', json_encode($skill['files'], JSON_THROW_ON_ERROR));
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $skill['name'])) {
            throw new RuntimeException('Native skill name differs from the PLOS bundle format.');
        }
        $root = $this->stateDirectory.'/skills/'.$hash.'/'.$skill['name'];
        foreach ($skill['files'] as $relative => $file) {
            if (str_contains($relative, "\0") || str_contains($relative, '\\') || str_starts_with($relative, '/')
                || in_array('', explode('/', $relative), true)
                || in_array('..', explode('/', $relative), true) || in_array('.', explode('/', $relative), true)) {
                throw new RuntimeException('Skill asset path is not a safe relative file path.');
            }
            $path = $root.'/'.$relative;
            $directory = $this->stateDirectory;
            foreach (explode('/', substr(dirname($path), strlen($this->stateDirectory) + 1)) as $part) {
                $directory .= '/'.$part;
                if (! file_exists($directory) && ! is_link($directory)) {
                    @mkdir($directory, 0700);
                }
                if (! is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
                    throw new RuntimeException('Native skill cache directory is unavailable or linked.');
                }
            }
            if (is_link($path) || (file_exists($path) && (! is_file($path) || hash_file('sha256', $path) !== $file['sha256']))) {
                throw new RuntimeException('Native skill cache differs from the saved original. It was not overwritten.');
            }
            if (file_exists($path)) {
                continue;
            }
            $bytes = base64_decode($file['base64'], true);
            if ($bytes === false || strlen($bytes) !== $file['bytes'] || hash('sha256', $bytes) !== $file['sha256']) {
                throw new RuntimeException('Saved skill asset failed original-byte verification.');
            }
            $stream = @fopen($path, 'x+b');
            if ($stream === false) {
                throw new RuntimeException('Native skill asset could not be created exclusively. Refresh the picker.');
            }
            try {
                chmod($path, 0600);
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($stream, substr($bytes, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Native skill asset write was incomplete.');
                    }
                    $offset += $written;
                }
            } finally {
                fclose($stream);
            }
        }

        return $root.'/SKILL.md';
    }
}
