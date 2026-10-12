<?php

namespace App\Services\UnifiedCli;

use Closure;
use Illuminate\Support\Str;
use RuntimeException;

/** Read attachment metadata through the existing registered PLOS media tool. */
final class CliWorkflowMediaProfile
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly string $workspace,
        private readonly CliWorkflowServices $services,
        private readonly CliMcpToolRuntime $mcp,
        private readonly ?Closure $keeper = null,
    ) {}

    public function context(): ?array
    {
        return $this->services->context('genealogy', 'media_profile');
    }

    public function read(int $mediaId, array $context): array
    {
        if ($context !== $this->context()) {
            throw new RuntimeException('Media workflow target changed before its metadata request.');
        }
        $configuration = new CliConfiguration($this->store);
        $session = $this->store->session($this->sessionId);
        $matches = array_filter((new CliToolCatalog($configuration))->definitions($session),
            fn ($tool) => $tool['mcp_server'] === 'genealogy' && $tool['mcp_tool'] === 'media_profile');
        if (count($matches) !== 1 || reset($matches)['risk_level'] !== 'read') {
            throw new RuntimeException('Media attachment requires one enabled read-only genealogy media-profile tool in the current registry.');
        }
        $action = ['id' => (string) Str::uuid(), 'name' => array_key_first($matches),
            'arguments' => ['tree_id' => $context['tree_id'], 'media_id' => $mediaId]];
        $runtime = new CliToolRuntime($this->store, $this->sessionId,
            // This is an explicit operator read, with no model request. Current
            // skill, registry, guardrail and MCP policies still authorize it.
            fn () => ['allowed' => true],
            function () use ($context): array {
                if ($context !== $this->context()) {
                    throw new RuntimeException('Media workflow target changed before dispatch.');
                }

                return $context + ['workspace' => $this->workspace, '_cli_actor' => 'operator'];
            },
            mcpCaller: fn ($server, $tool, $arguments, $scope, $definition) => $this->mcp->call($server, $tool, $arguments, $scope, $definition, $this->keeper),
            workspaceRoot: $this->workspace);
        $decision = $runtime->inspectTool($action, []);
        if (($decision['authorized'] ?? false) !== true) {
            throw new RuntimeException($decision['result_text'] ?? 'Current PLOS policy denied the media metadata read.');
        }
        $result = $runtime->execute($action, []);
        if (! $result['success']) {
            throw new RuntimeException('The selected PLOS media workflow did not return a verified record. No alternate target was tried.');
        }
        $profile = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
        $media = $profile['media'] ?? null;
        if (($profile['success'] ?? false) !== true || ($profile['tree_id'] ?? null) !== $context['tree_id']
            || ($profile['media_id'] ?? null) !== $mediaId || ! is_array($media)
            || ($media['id'] ?? null) !== $mediaId || ($media['tree_id'] ?? null) !== $context['tree_id']) {
            throw new RuntimeException('One exact media record in the selected tree is required.');
        }
        // Existing production profiles nest exact paths; the complete local
        // profile exposes row fields. Neither representation supplies file bytes.
        $paths = $media['paths'] ?? $media;

        return ['id' => $mediaId, 'tree_id' => $context['tree_id']]
            + array_intersect_key($paths, array_flip(['nextcloud_path', 'original_path', 'local_filename']));
    }
}
