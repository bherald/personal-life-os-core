<?php

namespace App\Services\UnifiedCli;

use App\Services\Genealogy\GedcomExportService;
use App\Services\Genealogy\GedcomParserService;
use App\Services\Genealogy\GenealogyExportPrivacyPolicyService;
use App\Services\Genealogy\GenealogyMCPService;
use App\Services\Genealogy\GenealogyService;
use App\Services\Genealogy\PrivacyService;
use App\Services\TimeService;
use RuntimeException;

/** Explicit adapters for local files and separately bound read-only data tools. */
final class CliLocalMcpServices
{
    public static function requiresDataBinding(string $server, string $tool): bool
    {
        return $server === 'genealogy' && in_array($tool, ['tree_stats', 'media_profile'], true);
    }

    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly string $workspace,
        private readonly CliArtifactStore $artifacts,
        private readonly ?CliDataBindings $data = null,
        private readonly ?string $nodeExecutable = null,
    ) {
        if ($workspace === '' || realpath($workspace) !== $workspace || ! is_dir($workspace) || $workspace === DIRECTORY_SEPARATOR) {
            throw new RuntimeException('Local domain tools require an explicit canonical workspace.');
        }
    }

    public function allows(array $session, string $server, string $tool, array $arguments, array $context): bool
    {
        return ($session['id'] ?? null) === $this->sessionId
            && ($context['workspace'] ?? null) === $this->workspace
            && match ($server) {
                'codex-skills' => in_array($tool, ['codex_skills_list', 'codex_skills_read'], true),
                'lemma-pod' => $this->lemmaPodAllowed($tool),
                'genealogy' => $tool === 'gedcom_parse' || (self::requiresDataBinding($server, $tool) && $this->data !== null
                    && ($arguments['tree_id'] ?? null) === ($context['tree_id'] ?? null)
                    && $this->dataContextMatches($context)),
                'time' => in_array($tool, ['get_current_time', 'convert_time'], true),
                default => false,
            };
    }

    public function dispatch(string $server, string $tool, array $arguments, array $definition, array $context): array
    {
        $session = $this->store->session($this->sessionId);
        $current = (new CliMcpServerDefinitions)->forSession(new CliConfiguration($this->store), $session)[$server] ?? [];
        $expectedClass = match ($server) {
            'codex-skills' => CliCodexSkillReader::class,
            'lemma-pod' => CliLemmaPodMcpService::class,
            'genealogy' => GenealogyMCPService::class,
            'time' => TimeService::class,
            default => null,
        };
        if ($expectedClass === null || ($definition['service'] ?? null) !== $expectedClass
            || ($definition['type'] ?? null) !== 'internal' || ($definition['enabled'] ?? false) !== true
            || ($current['service'] ?? null) !== $expectedClass || ($current['type'] ?? null) !== 'internal'
            || ($current['enabled'] ?? false) !== true
            || ! $this->allows($session, $server, $tool, $arguments, $context)) {
            throw new RuntimeException('No local adapter is bound to this exact MCP service, tool and workspace.');
        }
        if ($server === 'codex-skills') {
            return (new CliCodexSkillReader($this->workspace))->call($tool, $arguments);
        }
        if ($server === 'lemma-pod') {
            if ($this->nodeExecutable === null) {
                throw new RuntimeException('Lemma pod MCP Node binding is unavailable.');
            }
            $values = (new CliConfiguration($this->store))->effective($session)['values'];
            if (str_starts_with($tool, 'lemma_message_')) {
                $messages = new CliLemmaMessages($this->sessionId, $definition, base_path(),
                    $this->nodeExecutable, $values['cli.mcp_timeout_ms'] ?? 30000);

                return match ($tool) {
                    'lemma_message_send' => $messages->send($arguments,
                        'm-'.$this->sessionId.'-'.($context['_cli_action_id'] ?? throw new RuntimeException('Message action ID is missing.'))),
                    'lemma_message_inbox' => $messages->inbox($arguments['unread_only'] ?? true, $arguments['limit'] ?? 20),
                    'lemma_message_read' => $messages->read($arguments['message_id']),
                };
            }
            if (str_starts_with($tool, 'lemma_task_')) {
                $claims = new CliLemmaTaskClaims($this->sessionId, $definition, base_path(),
                    $this->nodeExecutable, $values['cli.mcp_timeout_ms'] ?? 30000);

                return match ($tool) {
                    'lemma_task_claim' => $claims->claim($arguments['task_key'], $arguments['summary']),
                    'lemma_task_release' => $claims->release($arguments['task_key']),
                    'lemma_task_list' => $claims->listing(),
                };
            }

            return CliLemmaPodMcpService::call($tool, $arguments, $definition, base_path(),
                $this->nodeExecutable, $values['cli.mcp_timeout_ms'] ?? 30000);
        }
        if ($server === 'time') {
            $service = new TimeService;

            return match ($tool) {
                'get_current_time' => $service->get_current_time($arguments),
                'convert_time' => $service->convert_time($arguments),
            };
        }

        if (self::requiresDataBinding($server, $tool)) {
            return $this->data->read($context, function () use ($tool, $arguments): array {
                $service = new GenealogyMCPService(new GenealogyService,
                    new GedcomExportService(new PrivacyService, new GenealogyExportPrivacyPolicyService), completeMediaProfiles: true);

                return match ($tool) {
                    'tree_stats' => $service->tree_stats($arguments['tree_id']),
                    'media_profile' => $service->media_profile($arguments['tree_id'], $arguments['media_id'], $arguments['face_limit'] ?? 25),
                };
            });
        }

        return $this->parseGedcom($arguments['file_path'], $arguments['preview_only'] ?? false);
    }

    private function dataContextMatches(array $context): bool
    {
        foreach ($this->data->context() as $key => $value) {
            if (($context[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function lemmaPodAllowed(string $tool): bool
    {
        $values = (new CliConfiguration($this->store))->effective($this->store->session($this->sessionId))['values'];
        if (! in_array(CliConnectivity::toolNetwork($values), ['lan', 'online'], true)) {
            return false;
        }
        if (in_array($tool, ['lemma_message_send', 'lemma_message_inbox', 'lemma_message_read',
            'lemma_task_claim', 'lemma_task_release', 'lemma_task_list'], true)) {
            return true;
        }
        $catalog = json_decode(file_get_contents(base_path('resources/plos/lemma-pod-mcp-tools.json')), true, flags: JSON_THROW_ON_ERROR);

        return in_array($tool, array_column($catalog, 'name'), true);
    }

    private function parseGedcom(string $path, bool $preview): array
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $path)) {
            throw new RuntimeException('GEDCOM input must be a local workspace file.');
        }
        $path = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('~^[A-Za-z]:[\\\\/]~', $path)
            ? $path : $this->workspace.DIRECTORY_SEPARATOR.$path;
        $original = $this->artifacts->importFile($path, [$this->workspace]);
        $this->store->recordEvent($this->sessionId, 'genealogy_file_input', ['artifact' => $original]);
        $parsed = $this->artifacts->inspect($original['sha256'], fn ($captured) => GedcomParserService::parseFile($captured));
        $derived = $this->artifacts->derived($original['sha256'], json_encode($parsed,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'PLOS GedcomParserService::parseFile');
        $this->store->recordEvent($this->sessionId, 'genealogy_file_parsed', ['artifact' => $derived]);
        $response = ['tool' => 'gedcom_parse', 'success' => true, 'file' => $original['name'],
            'stats' => $parsed['stats'], 'header' => $parsed['header'], 'timestamp' => gmdate('c'),
            'original_artifact' => $original, 'parsed_artifact' => $derived, 'preview_only' => $preview];

        // An explicitly requested preview still retains the entire derived result.
        // Full mode returns every parser field, including media and repositories.
        return $preview ? $response : array_replace($parsed, $response, ['truncated' => false]);
    }
}
