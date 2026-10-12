<?php

namespace App\Console\Commands;

use App\Services\UnifiedCli\CliLemmaMessages;
use App\Services\UnifiedCli\CliLemmaTaskClaims;
use App\Services\UnifiedCli\CliLocalBindings;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class LemmaCoordinateCommand extends Command
{
    protected $signature = 'lemma:coordinate
        {action=status : status, claim, release or send}
        {task_key? : Stable shared task key for claim or release}
        {--session= : Session UUID; defaults to CODEX_THREAD_ID}
        {--summary= : Short description for a task claim}
        {--to= : Recipient session UUID for send}
        {--message-file= : JSON file with subject and body; claim sends only on conflict}
        {--message-key= : Optional stable retry key; otherwise derived from message content}
        {--json : Emit complete machine-readable coordination state}';

    protected $description = 'Check Lemma ownership and inbox, claim work or send a verified private handoff';

    public function handle(): int
    {
        $payload = ['session_id' => $this->option('session') ?: getenv('CODEX_THREAD_ID'), 'status' => 'unavailable'];
        try {
            $session = $payload['session_id'];
            $action = $this->argument('action');
            $task = $this->argument('task_key');
            if (! is_string($session) || ! Str::isUuid($session)) {
                throw new InvalidArgumentException('Provide --session or CODEX_THREAD_ID with the current session UUID.');
            }
            $currentSession = getenv('CODEX_THREAD_ID');
            if (is_string($currentSession) && $currentSession !== '' && $session !== $currentSession) {
                throw new InvalidArgumentException('--session must match the current CODEX_THREAD_ID.');
            }
            if (! in_array($action, ['status', 'claim', 'release', 'send'], true)) {
                throw new InvalidArgumentException('Choose status, claim, release or send.');
            }
            if (in_array($action, ['claim', 'release'], true) && (! is_string($task)
                || ! preg_match('~^[A-Za-z0-9][A-Za-z0-9._:/-]{0,159}$~D', $task))) {
                throw new InvalidArgumentException('Claim or release requires a stable task key.');
            }
            $summary = trim((string) $this->option('summary'));
            if ($action === 'claim' && ($summary === '' || mb_strlen($summary) > 300)) {
                throw new InvalidArgumentException('Claim requires --summary with 1 to 300 characters.');
            }
            if (($task !== null && ! in_array($action, ['claim', 'release'], true))
                || ($this->option('summary') !== null && $action !== 'claim')
                || ($this->option('to') !== null && $action !== 'send')
                || ($this->option('message-key') !== null && $this->option('message-file') === null)
                || ($this->option('message-file') !== null && ! in_array($action, ['claim', 'send'], true))) {
                throw new InvalidArgumentException('Options do not match the selected coordination action.');
            }
            $message = $this->message($action);
            $definition = config('mcp.servers.lemma-pod');
            if (! is_array($definition) || ($definition['enabled'] ?? false) !== true) {
                throw new \RuntimeException('Lemma pod binding is disabled.');
            }
            $timeout = $definition['timeout_ms'] ?? null;
            if (is_string($timeout) && ctype_digit($timeout)) {
                $timeout = (int) $timeout;
            }
            if (! is_int($timeout) || $timeout < 50 || $timeout > 3600000) {
                throw new \RuntimeException('Lemma pod timeout configuration is invalid.');
            }
            foreach (['LEMMA_POD_PILOT_PATH', 'LEMMA_POD_CREDENTIAL_PATH'] as $name) {
                if (empty($definition['env'][$name])) {
                    $bindings ??= CliLocalBindings::fromFile($definition['coordination_bindings_path']);
                    $definition['env'][$name] = $bindings->resolveMcp('mcp:lemma-pod:'.$name);
                }
            }
            $node = $this->nodeExecutable();
            $claims = new CliLemmaTaskClaims($session, $definition, base_path(), $node, $timeout);
            $messages = new CliLemmaMessages($session, $definition, base_path(), $node, $timeout);
            // Reads must succeed before any requested mutation. Status never acknowledges messages.
            $payload['observed_claims'] = $claims->listing()['claims'];
            $payload['inbox'] = $messages->inbox();
            $payload['status'] = 'ready';
            if ($action === 'claim') {
                $payload['claim'] = $claims->claim($task, $summary);
                if (! $payload['claim']['claimed']) {
                    $payload['status'] = 'conflict';
                    if ($message !== null) {
                        $payload['message'] = $this->send($messages, $session, $payload['claim']['owner_session'], $task, $message);
                    }
                }
            } elseif ($action === 'release') {
                $payload['release'] = $claims->release($task);
            } elseif ($action === 'send') {
                $payload['message'] = $this->send($messages, $session, $this->option('to'), null, $message);
            }
            $this->outputPayload($payload);

            return $payload['status'] === 'conflict' ? self::FAILURE : self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $payload['status'] = 'invalid';
            $payload['error'] = $exception->getMessage();
        } catch (Throwable) {
            // Never echo transport/auth exceptions. An interrupted write may already exist.
            $payload['status'] = 'unavailable';
            $payload['error'] = 'Coordination incomplete; inspect task/message state before retrying a write. Existing claims remain authoritative.';
        }
        $this->outputPayload($payload);

        return self::FAILURE;
    }

    protected function nodeExecutable(): string
    {
        return (new ExecutableFinder)->find('node') ?? throw new \RuntimeException('Node is unavailable.');
    }

    private function message(string $action): ?array
    {
        $path = $this->option('message-file');
        if ($action === 'send' && (! is_string($this->option('to')) || ! Str::isUuid($this->option('to')) || $path === null)) {
            throw new InvalidArgumentException('Send requires --to with a session UUID and --message-file.');
        }
        if ($path === null) {
            return null;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) || ! is_file($path) || filesize($path) > 32768) {
            throw new InvalidArgumentException('Message file must be a local JSON file of at most 32 KiB.');
        }
        try {
            $message = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('Message file must contain JSON subject and body fields.');
        }
        if (! is_array($message) || array_diff(array_keys($message), ['subject', 'body']) !== []
            || ! is_string($message['subject'] ?? null) || trim($message['subject']) === '' || mb_strlen($message['subject']) > 160
            || ! is_string($message['body'] ?? null) || trim($message['body']) === '' || mb_strlen($message['body']) > 4000) {
            throw new InvalidArgumentException('Message needs subject (1 to 160 characters) and body (1 to 4000 characters).');
        }
        $key = $this->option('message-key');
        if ($key !== null && ! preg_match('/^[a-zA-Z0-9:._-]{1,96}$/D', $key)) {
            throw new InvalidArgumentException('Message key must use 1 to 96 letters, digits or :._-.');
        }

        return ['subject' => trim($message['subject']), 'body' => $message['body']];
    }

    private function send(CliLemmaMessages $messages, string $session, string $to, ?string $task, array $message): array
    {
        $data = ['to_session' => $to, 'subject' => $message['subject'], 'body' => $message['body']];
        if ($task !== null) {
            $data['task_key'] = $task;
        }
        $key = $this->option('message-key') ?? 'm-'.hash('sha256', json_encode([$session, $data], JSON_THROW_ON_ERROR));

        return $messages->send($data, $key);
    }

    private function outputPayload(array $payload): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $this->line('Lemma: '.$payload['status'].'; session '.$payload['session_id']);
        if (isset($payload['observed_claims'])) {
            $this->table(['Task', 'Owner', 'Summary'], array_map(fn (array $claim): array => [$claim['task_key'], $claim['owner_session'], $claim['summary']], $payload['observed_claims']));
        }
        if (isset($payload['inbox'])) {
            $this->line('Unread inbox: '.$payload['inbox']['count']);
        }
        foreach (['claim', 'release', 'message', 'error'] as $key) {
            if (isset($payload[$key])) {
                $this->line($key.': '.(is_string($payload[$key]) ? $payload[$key] : json_encode($payload[$key], JSON_UNESCAPED_SLASHES)));
            }
        }
    }
}
