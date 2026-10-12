<?php

namespace App\Services\UnifiedCli;

use App\Services\NativeCodexToolService;
use InvalidArgumentException;

/** Command grammar and help only. Execution permission remains in PLOS tables. */
final class CliCommandCatalog
{
    private const COMMANDS = [
        'models' => ['Search all registered models and their capabilities', '[SEARCH|host:HOST|engine:ENGINE|capability:CAP|state:STATE|--json]', 'routing'],
        'model' => ['Inspect or select a model; auto restores automatic selection', '[INSTANCE::MODEL|auto]', 'routing'],
        'model-info' => ['Explain one exact model and the next action', 'INSTANCE::MODEL', 'routing'],
        'mode' => ['Inspect or select operating scope', '[chat|read-only|coding|operator|autonomous]', 'settings'],
        'permissions' => ['Show effective tool, workspace, data and approval scope', '', 'settings'],
        'engine' => ['Inspect or select an engine', '[ID|auto]', 'routing'],
        'profile' => ['Inspect or select a routing profile', '[NAME|auto]', 'routing'],
        'role' => ['Inspect or select a model role', '[NAME|auto]', 'routing'],
        'route' => ['Explain current route candidates without starting inference', '', 'routing'],
        'compute' => ['Inspect or select compute for media and other capabilities', '[INSTANCE|auto|route CAPABILITY]', 'routing'],
        'host' => ['Inspect and operate the bound engine controller', 'mode [auto|manual] | status | bind URL | plan TARGET | activate TARGET [--override] | lease | heartbeat | release | reconcile', 'controller'],
        'internet' => ['Allow or block internet tools independently of cloud inference', '[on|off]', 'settings'],
        'cloud' => ['Allow or block cloud inference through existing routing policy', '[on|off]', 'settings'],
        'tasks' => ['Find registered tasks, arguments and skill availability', '[SEARCH|--json]', 'registry'],
        'plan' => ['Select read-only planning; optionally ask the selected model for a plan', '[TASK]', 'session'],
        'diff' => ['Read tracked workspace changes using the native read-only sandbox', '[--staged]', 'native'],
        'review' => ['Review the complete tracked diff using read-only tools', '[--staged]', 'session'],
        'fork' => ['Copy conversation into an independent session without running actions', '[NAME]', 'session'],
        'network' => ['Optional connection restrictions; PLOS rules always apply', '[offline|lan|online]', 'settings'],
        'agent' => ['Inspect or select a table-defined agent', '[ID]', 'registry'],
        'agents' => ['List synchronized agent definitions', '', 'registry'],
        'skills' => ['Browse complete skills; native menu supports session toggles', '', 'registry'],
        'skill' => ['Select the complete synchronized skill version', 'NAME', 'registry'],
        'tool' => ['Run a registered tool or request an exact operator override under current policy', 'NAME JSON | override NAME JSON | run ACTION_UUID | list', 'registry'],
        'tools' => ['Inspect registered tools, schemas and current availability', '[NAME]', 'registry'],
        'guardrails' => ['Inspect synchronized guardrail rules', '[NAME]', 'registry'],
        'mcp' => ['Inspect MCP definitions or explicitly discover a server', '[verbose|discover SERVER]', 'registry'],
        'process' => ['Control owned commands even while the model is busy', 'list | output ID [OFFSET] | send ID "JSON text" | input ID JSON | eof ID | cancel ID | resize ID ROWS COLS | confirm TOKEN | deny TOKEN', 'native'],
        'approvals' => ['Inspect pending model-tool approvals', '', 'session'],
        'approve' => ['Approve the exact pending model-tool action', 'ID', 'session'],
        'deny' => ['Decline a pending model-tool action', 'ID', 'session'],
        'continue' => ['Continue an explicitly reviewed pending agent turn', '', 'session'],
        'cancel-actions' => ['Cancel pending agent actions without replay', '', 'session'],
        'receipts' => ['Inspect saved action outcomes', '', 'session'],
        'recover' => ['Close interrupted turns without replaying actions', '', 'session'],
        'status' => ['Show running host, model, data target and mode', '[--json]', 'session'],
        'compact' => ['Review and explicitly confirm compaction; originals stay saved', '[confirm ID|cancel|restore]', 'session'],
        'new' => ['Start a new native session using saved startup settings', '[NAME]', 'native'],
        'clear' => ['Start fresh while retaining the prior session', '[NAME]', 'native'],
        'resume' => ['Open the native saved-session picker; actions are never replayed', '', 'native'],
        'config' => ['Inspect settings, set an override or reset it', '[set KEY JSON|reset KEY [--saved]]', 'settings'],
        'override' => ['Override synchronized table fields for this session', 'TABLE SELECTOR FIELD[.JSON_PATH] JSON', 'settings'],
        'save-default' => ['Optionally save an existing override for later startup', 'KEY', 'settings'],
        'sync' => ['Refresh selected configuration while preserving local work', '[status|preview|diff [GENERATION]|rollback GENERATION|--remote|--development|SNAPSHOT_PATH]', 'settings'],
        'target' => ['Data defaults to this installation; choose a remote binding explicitly', '[local|prod|BINDING_NAME|none]', 'media'],
        'tree' => ['Inspect or select the genealogy tree scope', '[ID|none]', 'media'],
        'media' => ['Generate an image, attach genealogy media or run extraction/rendering', 'generate MODEL PROMPT | attach ID | render SHA256 all|1,2 DPI | extract SHA256 pdf|ocr [LANGUAGE] | extract SHA256 htr all|1,2 TOKEN_LIMIT | results', 'media'],
        'attach' => ['Preserve a local original and queue it as input', 'PATH', 'media'],
        'queue' => ['Queue a saved artifact for the next input', 'SHA256', 'media'],
        'detach' => ['Remove an artifact from pending input; retain its bytes', 'SHA256', 'media'],
        'files' => ['List this session’s artifacts and queue status', '', 'media'],
        'export' => ['Export a saved artifact to an allowed destination', 'SHA256 DESTINATION_PATH', 'media'],
        'quit' => ['Exit the native terminal', '', 'native'],
        'exit' => ['Alias for /quit', '', 'native'],
        'help' => ['List commands or show full argument help for one command', '[COMMAND]', 'session'],
    ];

    public function __construct(private readonly CliConfiguration $configuration) {}

    public function commands(array $session): array
    {
        $values = $this->configuration->effective($session)['values'];
        $registry = ($session['generation'] ?? null) !== null;
        $network = $values['cli.network'];
        $commands = [];
        foreach (self::COMMANDS as $name => [$summary, $usage, $category]) {
            $status = match ($category) {
                'routing', 'registry' => $registry ? 'registry loaded; admission unchecked' : 'registry missing',
                'controller', 'media' => 'binding checked on use',
                'settings' => 'session override; optional save',
                'native' => 'native terminal',
                default => 'session operation',
            };
            $detail = match ($category) {
                'routing', 'registry' => $registry ? 'Uses the current synchronized tables and session overrides; execution admission is checked when used.' : 'No registry generation is selected. Load configuration with /sync first.',
                'controller' => 'Controller operations require the selected binding and current table policy. Activation mode: '.($values['cli.engine_activation'] ?? 'auto').'.',
                'settings' => 'Changes apply to this session. Save explicitly with /save-default KEY. Network mode: '.$network.'.',
                'media' => 'Uses the selected data binding, tree and allowed workspace. Reachability and action permissions are checked when used.',
                'native' => 'Native terminal command; current PLOS rules still apply. Scripted tasks use the documented exec entrypoint.',
                default => 'Session records and original history are retained. Commands and tool actions are never automatically replayed.',
            };
            if ($name === 'network') {
                $detail = 'Default online adds no CLI connection restriction: use the selected endpoint under PLOS rules, or report unavailability. Optional lan restricts destinations to local/LAN addresses; offline restricts them to this computer. /save-default cli.network saves the choice.';
            } elseif ($name === 'media') {
                $detail = 'Image generation uses this installation with /target local and the selected network mode. Other media commands retain their data, tree and workspace checks.';
            } elseif ($name === 'compact') {
                $enabled = $values['cli.compaction_policy']['enabled'] ?? false;
                $status = $enabled === true ? 'explicit confirmation' : 'compaction policy disabled';
                $detail = ($enabled === true ? 'Compaction is enabled by current settings; every operation still needs explicit confirmation.' : 'Compaction is not enabled by current settings; /compact restore can restore saved originals.');
            } elseif ($name === 'process') {
                $registered = array_filter($this->configuration->definitions($session, 'agent_tool_registry'),
                    fn ($tool) => ($tool['service_class'] ?? null) === NativeCodexToolService::class
                        && in_array($tool['enabled'] ?? false, [true, 1, '1'], true));
                $status = $registered === [] ? 'native tool registration missing' : 'skill permissions and table rules';
                $detail = 'Requires registered native tools and the selected skill permissions. Input confirmation follows current table rules. A saved process ID cannot restore execution ownership.';
            } elseif ($name === 'sync' && $network === 'offline') {
                $status = 'offline; local snapshots only';
                $detail .= ' Remote sync is unavailable in offline mode; a complete local snapshot may still be imported.';
            }
            $commands[] = ['name' => $name, 'summary' => $summary, 'usage' => $usage,
                'description' => $summary.' ['.$status.']'.($usage === '' ? '' : ' — /'.$name.' '.$usage),
                'category' => $category, 'status' => $status, 'detail' => $detail, 'admission_checked' => false];
        }

        return $commands;
    }

    public function help(array $session, string $name = ''): string
    {
        $commands = $this->commands($session);
        if ($name !== '') {
            $name = ltrim($name, '/');
            foreach ($commands as $command) {
                if ($command['name'] === $name) {
                    return '/'.$name.($command['usage'] === '' ? '' : ' '.$command['usage'])."\n"
                        .$command['summary']."\n".$command['detail'];
                }
            }
            throw new InvalidArgumentException('No PLOS command binding for /'.$name.'. Use /help to inspect implemented commands.');
        }

        return implode("\n", array_map(fn ($command) => '/'.$command['name'].($command['usage'] === '' ? '' : ' '.$command['usage']).' — '.$command['summary'], $commands))
            ."\nUse /help COMMAND for details. Menu visibility does not authorize execution. Session overrides support optional /save-default KEY.";
    }
}
