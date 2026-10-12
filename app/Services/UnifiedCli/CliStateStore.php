<?php

namespace App\Services\UnifiedCli;

use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

/** Dedicated CLI state, never the application's shared migrated database. */
final class CliStateStore
{
    private PDO $db;

    /** One immutable decoded generation per store; mutable state is never cached. */
    private ?array $decodedGeneration = null;

    public function __construct(string $path)
    {
        if ($path !== ':memory:') {
            $directory = dirname($path);
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Cannot create private CLI state directory.');
            }
            if (is_link($path)) {
                throw new RuntimeException('CLI state database must not be a symlink.');
            }
            if (! file_exists($path)) {
                $handle = fopen($path, 'x');
                if ($handle === false) {
                    throw new RuntimeException('Cannot create CLI state database.');
                }
                fclose($handle);
                chmod($path, 0600);
            }
        }
        $this->db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout = 5000');
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec('CREATE TABLE IF NOT EXISTS cli_sessions (id TEXT PRIMARY KEY, revision INTEGER NOT NULL, document TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS cli_defaults (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS cli_generations (id TEXT PRIMARY KEY, document TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS cli_active_generation (slot INTEGER PRIMARY KEY CHECK (slot = 1), generation_id TEXT NOT NULL REFERENCES cli_generations(id))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS cli_action_receipts (session_id TEXT NOT NULL REFERENCES cli_sessions(id), turn_id TEXT NOT NULL, action_id TEXT NOT NULL, state TEXT NOT NULL, document TEXT NOT NULL, PRIMARY KEY (session_id, turn_id, action_id))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS cli_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id TEXT NOT NULL REFERENCES cli_sessions(id), event_type TEXT NOT NULL, document TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS cli_approvals (id TEXT PRIMARY KEY, session_id TEXT NOT NULL REFERENCES cli_sessions(id), binding_hash TEXT NOT NULL, state TEXT NOT NULL, expires_at INTEGER NOT NULL, document TEXT NOT NULL, UNIQUE(session_id, binding_hash))');
    }

    public function createSession(array $document): array
    {
        $id = (string) Str::uuid();
        $document = array_merge($document, ['id' => $id, 'revision' => 0]);
        $stmt = $this->db->prepare('INSERT INTO cli_sessions (id, revision, document) VALUES (?, 0, ?)');
        $stmt->execute([$id, $this->encode($document)]);

        return $document;
    }

    public function session(string $id): array
    {
        $stmt = $this->db->prepare('SELECT document FROM cli_sessions WHERE id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            throw new RuntimeException('CLI session not found.');
        }

        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    /** Optimistic concurrency prevents two terminal clients overwriting history. */
    public function saveSession(array $document): array
    {
        $revision = $document['revision'];
        $document['revision']++;
        $stmt = $this->db->prepare('UPDATE cli_sessions SET revision = ?, document = ? WHERE id = ? AND revision = ?');
        $stmt->execute([$document['revision'], $this->encode($document), $document['id'], $revision]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Session changed in another client; reload before continuing.');
        }

        return $document;
    }

    /** Operator control worker only: target one exact still-active turn. */
    public function requestTurnCancellation(string $sessionId, string $turnId): void
    {
        $session = $this->session($sessionId);
        $index = array_key_last($session['turns'] ?? []);
        if ($index === null || ($session['turns'][$index]['id'] ?? null) !== $turnId
            || ($session['turns'][$index]['status'] ?? null) !== 'inProgress') {
            throw new RuntimeException('The requested turn is not active; no other turn was cancelled.');
        }
        if (($session['turns'][$index]['cancel_requested'] ?? false) === true) {
            return;
        }
        $session['turns'][$index]['cancel_requested'] = true;
        $this->saveSession($session);
    }

    public function assertTurnNotCancelled(string $sessionId, string $turnId): void
    {
        $session = $this->session($sessionId);
        $turn = $session['turns'][array_key_last($session['turns'] ?? [])] ?? [];
        if (($turn['id'] ?? null) !== $turnId || ($turn['status'] ?? null) !== 'inProgress'
            || ($turn['cancel_requested'] ?? false) === true) {
            throw new RuntimeException('Operator cancelled this turn or its ownership changed. Captured bytes are retained; no retry was attempted.');
        }
    }

    public function sessions(): array
    {
        return array_map(fn ($value) => json_decode($value, true, flags: JSON_THROW_ON_ERROR), $this->db->query('SELECT document FROM cli_sessions ORDER BY rowid DESC')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Persist an explicit operator command without adding a model message. */
    public function registerOperatorAction(string $sessionId, array $action): array
    {
        $session = $this->session($sessionId);
        $turn = $session['turns'][array_key_last($session['turns'] ?? [])] ?? [];
        $this->assertTurnNotCancelled($sessionId, $turn['id'] ?? '');
        $input = $turn['items'][0]['content'] ?? [];
        $text = count($input) === 1 && ($input[0]['type'] ?? null) === 'text' ? ($input[0]['text'] ?? null) : null;
        if (! is_string($text) || ! preg_match('/^\/tool (?:(override) )?([a-z][a-z0-9_]{0,127}) (.+)$/sD', $text, $match)
            || $match[2] !== ($action['name'] ?? null)
            || ($match[1] === 'override') !== (($action['operator_override'] ?? false) === true)
            || json_decode($match[3], true, flags: JSON_THROW_ON_ERROR) !== ($action['arguments'] ?? null)
            || ! Str::isUuid($action['id'] ?? '') || isset($session['operator_actions'][$action['id']])) {
            throw new RuntimeException('Operator action requires its exact persisted tool command.');
        }
        $session['operator_actions'][$action['id']] = ['origin' => 'operator', 'turn_id' => $turn['id'],
            'input_sha256' => hash('sha256', $text), 'action' => $action];

        return $this->saveSession($session);
    }

    /** An operator origin is separate from lossless model tool calls. */
    public function operatorActionOrigin(string $sessionId, array $action): ?string
    {
        $session = $this->session($sessionId);
        $entry = $session['operator_actions'][$action['id'] ?? ''] ?? null;
        if (($entry['origin'] ?? null) !== 'operator' || ($entry['action'] ?? null) !== $action) {
            return null;
        }
        foreach ($session['turns'] ?? [] as $turn) {
            if (($turn['id'] ?? null) !== $entry['turn_id'] || ($turn['cancel_requested'] ?? false) === true) {
                continue;
            }
            $input = $turn['items'][0]['content'] ?? [];
            $text = count($input) === 1 && ($input[0]['type'] ?? null) === 'text' ? ($input[0]['text'] ?? null) : null;
            if (is_string($text) && hash_equals($entry['input_sha256'], hash('sha256', $text))) {
                return $entry['turn_id'];
            }
        }

        return null;
    }

    /** Claim before execution. A crash leaves an explicit unknown outcome. */
    public function startAction(string $sessionId, string $turnId, array $action, array $route, ?string $continuationTurnId = null): void
    {
        $session = $this->session($sessionId);
        $turns = array_filter($session['turns'] ?? [], fn ($turn) => $turn['id'] === $turnId && ($turn['status'] === 'inProgress'
            || ($continuationTurnId !== null && (($session['active_agent_turn'] ?? null) === $turnId
                || $this->operatorActionOrigin($sessionId, $action) === $turnId))));
        if ($continuationTurnId !== null && count(array_filter($session['turns'] ?? [], fn ($turn) => $turn['id'] === $continuationTurnId && $turn['status'] === 'inProgress')) !== 1) {
            throw new RuntimeException('Continuation requires the exact active operator command turn.');
        }
        if (count($turns) !== 1 || ! is_string($action['id'] ?? null) || $action['id'] === '') {
            throw new RuntimeException('Action requires the exact active session turn.');
        }
        $document = ['action' => $action, 'instance_id' => $route['instance_id'], 'model' => $route['model'], 'started_at' => time(), 'outcome' => 'unknown'];
        if ($continuationTurnId !== null) {
            $document['continuation_turn_id'] = $continuationTurnId;
        }
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO cli_action_receipts (session_id, turn_id, action_id, state, document) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$sessionId, $turnId, $action['id'], 'started', $this->encode($document)]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Action identity already has a receipt. Review its outcome; automatic replay is prohibited.');
        }
    }

    public function completeAction(string $sessionId, string $turnId, string $actionId, array $result): void
    {
        if (! is_bool($result['success'] ?? null) || ! is_string($result['output'] ?? null)) {
            throw new RuntimeException('Complete typed action result is required.');
        }
        $receipt = $this->actionReceipts($sessionId, $turnId)[$actionId] ?? null;
        if ($receipt === null || $receipt['state'] !== 'started') {
            throw new RuntimeException('Action is not awaiting completion.');
        }
        $document = array_merge($receipt['document'], ['result' => $result, 'outcome' => $result['success'] ? 'succeeded' : 'failed', 'completed_at' => time()]);
        $stmt = $this->db->prepare('UPDATE cli_action_receipts SET state = ?, document = ? WHERE session_id = ? AND turn_id = ? AND action_id = ? AND state = ?');
        $stmt->execute(['completed', $this->encode($document), $sessionId, $turnId, $actionId, 'started']);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Action receipt changed concurrently; inspect before continuing.');
        }
    }

    /** An operator can cancel an unclaimed action, never mask an unknown outcome. */
    public function cancelAction(string $sessionId, string $turnId, array $action, string $operatorTurnId): array
    {
        $session = $this->session($sessionId);
        if (! in_array($turnId, array_column($session['turns'] ?? [], 'id'), true)
            || count(array_filter($session['turns'] ?? [], fn ($turn) => $turn['id'] === $operatorTurnId && $turn['status'] === 'inProgress')) !== 1) {
            throw new RuntimeException('Cancellation requires an existing action turn and an active operator command.');
        }
        $result = ['success' => false, 'output' => '{"status":"cancelled_by_operator","action_executed":false}'];
        $document = ['action' => $action, 'operator_turn_id' => $operatorTurnId, 'action_executed' => false,
            'outcome' => 'cancelled', 'completed_at' => time(), 'result' => $result];
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO cli_action_receipts (session_id, turn_id, action_id, state, document) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$sessionId, $turnId, $action['id'], 'completed', $this->encode($document)]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Action already has a receipt. Its outcome cannot be replaced by cancellation.');
        }

        return $result;
    }

    public function actionReceipts(string $sessionId, string $turnId): array
    {
        $stmt = $this->db->prepare('SELECT action_id, state, document FROM cli_action_receipts WHERE session_id = ? AND turn_id = ? ORDER BY rowid');
        $stmt->execute([$sessionId, $turnId]);
        $receipts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $receipts[$row['action_id']] = ['state' => $row['state'], 'document' => json_decode($row['document'], true, flags: JSON_THROW_ON_ERROR)];
        }

        return $receipts;
    }

    public function defaults(): array
    {
        $result = [];
        foreach ($this->db->query('SELECT key, value FROM cli_defaults') as $row) {
            $result[$row['key']] = json_decode($row['value'], true, flags: JSON_THROW_ON_ERROR);
        }

        return $result;
    }

    public function recordEvent(string $sessionId, string $type, array $document): void
    {
        $stmt = $this->db->prepare('INSERT INTO cli_events (session_id, event_type, document) VALUES (?, ?, ?)');
        $stmt->execute([$sessionId, $type, $this->encode(['recorded_at' => time(), 'event' => $document])]);
    }

    public function events(string $sessionId): array
    {
        $stmt = $this->db->prepare('SELECT id, event_type, document FROM cli_events WHERE session_id = ? ORDER BY id');
        $stmt->execute([$sessionId]);

        return array_map(fn ($row) => ['id' => $row['id'], 'type' => $row['event_type'],
            'document' => json_decode($row['document'], true, flags: JSON_THROW_ON_ERROR)], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Approval is bound to an existing pending action and its exact policy/target. */
    public function requestApproval(string $sessionId, string $turnId, array $action, array $scope, int $ttlSeconds = 900): array
    {
        if ($ttlSeconds < 1 || $ttlSeconds > 86400) {
            throw new RuntimeException('Approval lifetime must be between one second and one day.');
        }
        $session = $this->session($sessionId);
        $pending = (new LosslessConversation($session['messages'] ?? []))->pendingToolCalls();
        $entry = $pending[$action['id'] ?? ''] ?? null;
        $modelPending = $entry !== null && ($session['message_turn_ids'][$entry['message_index']] ?? null) === $turnId
            && ($entry['call']['function']['name'] ?? null) === ($action['name'] ?? null)
            && json_decode($entry['call']['function']['arguments'] ?? '', true, flags: JSON_THROW_ON_ERROR) === ($action['arguments'] ?? null);
        if ((! $modelPending && $this->operatorActionOrigin($sessionId, $action) !== $turnId)
            || isset($this->actionReceipts($sessionId, $turnId)[$action['id']])) {
            throw new RuntimeException('Approval requires an exact pending action with no execution receipt.');
        }
        foreach (['configuration_sha256', 'instance_id', 'model'] as $field) {
            if (! is_string($scope[$field] ?? null) || $scope[$field] === '') {
                throw new RuntimeException('Approval requires current configuration, model and target scope.');
            }
        }
        if (! preg_match('/^[a-f0-9]{64}$/D', $scope['configuration_sha256'])) {
            throw new RuntimeException('Approval configuration binding must be a SHA-256 hash.');
        }
        $binding = ['turn_id' => $turnId, 'action' => $action, 'scope' => $scope];
        $hash = hash('sha256', $this->encode($binding));
        $id = (string) Str::uuid();
        $expires = time() + $ttlSeconds;
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO cli_approvals (id, session_id, binding_hash, state, expires_at, document) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$id, $sessionId, $hash, 'pending', $expires, $this->encode($binding)]);
        $stmt = $this->db->prepare('SELECT id FROM cli_approvals WHERE session_id = ? AND binding_hash = ?');
        $stmt->execute([$sessionId, $hash]);

        return $this->approval($sessionId, $stmt->fetchColumn());
    }

    public function approvals(string $sessionId): array
    {
        $stmt = $this->db->prepare('SELECT id FROM cli_approvals WHERE session_id = ? ORDER BY rowid');
        $stmt->execute([$sessionId]);

        return array_map(fn ($id) => $this->approval($sessionId, $id), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function approval(string $sessionId, string $id): array
    {
        $stmt = $this->db->prepare('SELECT id, binding_hash, state, expires_at, document FROM cli_approvals WHERE session_id = ? AND id = ?');
        $stmt->execute([$sessionId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Approval request does not belong to this session.');
        }
        $row['document'] = json_decode($row['document'], true, flags: JSON_THROW_ON_ERROR);
        $row['expired'] = $row['expires_at'] <= time();

        return $row;
    }

    /** Called only by the operator command surface, never by model arguments. */
    public function decideApproval(string $sessionId, string $id, bool $approved): array
    {
        $row = $this->approval($sessionId, $id);
        if ($row['expired'] || $row['state'] !== 'pending') {
            throw new RuntimeException('Approval is expired or already decided. Review the existing decision.');
        }
        $stmt = $this->db->prepare('UPDATE cli_approvals SET state = ? WHERE session_id = ? AND id = ? AND state = ? AND expires_at > ?');
        $stmt->execute([$approved ? 'approved' : 'denied', $sessionId, $id, 'pending', time()]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Approval changed concurrently or expired before the decision.');
        }
        $this->recordEvent($sessionId, 'operator_approval', ['id' => $id, 'approved' => $approved, 'binding_hash' => $row['binding_hash']]);

        return $this->approval($sessionId, $id);
    }

    public function hasApproval(string $sessionId, string $turnId, array $action, array $scope): bool
    {
        $hash = hash('sha256', $this->encode(['turn_id' => $turnId, 'action' => $action, 'scope' => $scope]));
        $stmt = $this->db->prepare('SELECT state, expires_at FROM cli_approvals WHERE session_id = ? AND binding_hash = ?');
        $stmt->execute([$sessionId, $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false && $row['state'] === 'approved' && $row['expires_at'] > time();
    }

    public function saveDefault(string $key, mixed $value): void
    {
        $stmt = $this->db->prepare('INSERT INTO cli_defaults (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $stmt->execute([$key, $this->encode($value)]);
    }

    public function resetDefault(string $key): void
    {
        $stmt = $this->db->prepare('DELETE FROM cli_defaults WHERE key = ?');
        $stmt->execute([$key]);
    }

    /** Validated generations activate atomically, retaining previous generations. */
    public function activate(array $snapshot, array $sessionOverrides = []): string
    {
        (new RegistrySnapshotValidator)->validate($snapshot);
        (new CliRegistryOverlay)->apply($snapshot, array_replace($this->defaults(), $sessionOverrides));
        $body = $this->encode($snapshot);
        $id = hash('sha256', $body);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('INSERT OR IGNORE INTO cli_generations (id, document) VALUES (?, ?)');
            $stmt->execute([$id, $body]);
            $stmt = $this->db->prepare('INSERT INTO cli_active_generation (slot, generation_id) VALUES (1, ?) ON CONFLICT(slot) DO UPDATE SET generation_id = excluded.generation_id');
            $stmt->execute([$id]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $id;
    }

    public function snapshot(?string $generation = null): ?array
    {
        // Resolve the selector every time: another CLI client may have activated
        // or rolled back. Generation documents are content-addressed INSERT-only.
        $stmt = $this->db->prepare($generation === null
            ? 'SELECT g.id FROM cli_generations g JOIN cli_active_generation a ON a.generation_id = g.id WHERE a.slot = 1'
            : 'SELECT id FROM cli_generations WHERE id = ?');
        $stmt->execute($generation === null ? [] : [$generation]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }
        if (($this->decodedGeneration['generation'] ?? null) === $id) {
            return $this->decodedGeneration;
        }
        $stmt = $this->db->prepare('SELECT document FROM cli_generations WHERE id = ?');
        $stmt->execute([$id]);
        $document = $stmt->fetchColumn();
        if ($document === false) {
            return null;
        }
        // Release the prior cache before decoding; this is never a map of all
        // generations. PHP arrays preserve caller isolation by copy-on-write.
        $this->decodedGeneration = null;

        return $this->decodedGeneration = ['generation' => $id,
            'snapshot' => json_decode($document, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function rollback(string $generation, array $sessionOverrides = []): void
    {
        $selected = $this->snapshot($generation);
        if ($selected === null) {
            throw new RuntimeException('Unknown CLI configuration generation.');
        }
        (new RegistrySnapshotValidator)->validate($selected['snapshot']);
        (new CliRegistryOverlay)->apply($selected['snapshot'], array_replace($this->defaults(), $sessionOverrides));
        $stmt = $this->db->prepare('UPDATE cli_active_generation SET generation_id = ? WHERE slot = 1');
        $stmt->execute([$generation]);
    }

    /** Retained registry versions only; never exposes configuration values or session data. */
    public function registryStatus(): array
    {
        $active = $this->snapshot()['generation'] ?? null;
        $generations = [];
        foreach ($this->db->query('SELECT id, document FROM cli_generations ORDER BY rowid DESC') as $row) {
            $snapshot = json_decode($row['document'], true, flags: JSON_THROW_ON_ERROR);
            $generations[] = ['generation' => $row['id'], 'active' => $row['id'] === $active,
                'source' => $snapshot['source'], 'exported_at' => $snapshot['exported_at'],
                'table_rows' => array_map('count', $snapshot['tables'])];
        }

        return ['scope' => 'registry', 'active_generation' => $active, 'generations' => $generations,
            'defaults_preserved' => true, 'database_rollback' => false];
    }

    /** Compare a retained version to active; row changes count as one removal and addition. */
    public function registryDiff(?string $generation = null): array
    {
        $active = $this->snapshot();
        if ($active === null) {
            throw new RuntimeException('No registry generation is installed.');
        }
        if ($generation === null) {
            $stmt = $this->db->prepare('SELECT id FROM cli_generations WHERE id != ? ORDER BY rowid DESC LIMIT 1');
            $stmt->execute([$active['generation']]);
            $generation = $stmt->fetchColumn() ?: null;
        }
        $previous = $generation === null ? null : $this->snapshot($generation);
        if ($generation !== null && $previous === null) {
            throw new RuntimeException('Unknown CLI configuration generation.');
        }

        return $this->compareRegistries($previous, $active) + [
            'note' => 'Stored registry comparison; changed rows count as removed and added. Database and local overrides are unchanged.'];
    }

    public function registryPreview(array $snapshot): array
    {
        (new RegistrySnapshotValidator)->validate($snapshot);

        return $this->compareRegistries($this->snapshot(), [
            'generation' => hash('sha256', $this->encode($snapshot)), 'snapshot' => $snapshot,
        ]) + ['preview' => true, 'activated' => false,
            'note' => 'Verified source preview only. No registry activation, database import or override change.'];
    }

    private function compareRegistries(?array $previous, array $active): array
    {
        $before = $previous['snapshot']['tables'] ?? [];
        $after = $active['snapshot']['tables'];
        $tables = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $name) {
            // Sort keys and retain duplicate multiplicity; export row order is not a change.
            $counts = function (array $rows): array {
                $canonical = function (mixed $value) use (&$canonical): mixed {
                    if (! is_array($value)) {
                        return $value;
                    }
                    if (! array_is_list($value)) {
                        ksort($value);
                    }

                    return array_map($canonical, $value);
                };

                return array_count_values(array_map(fn ($row) => hash('sha256', $this->encode($canonical($row))), $rows));
            };
            $old = $counts($before[$name] ?? []);
            $new = $counts($after[$name] ?? []);
            $added = $removed = 0;
            foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $hash) {
                $change = ($new[$hash] ?? 0) - ($old[$hash] ?? 0);
                $added += max(0, $change);
                $removed += max(0, -$change);
            }
            $tables[$name] = ['rows_added' => $added, 'rows_removed' => $removed];
        }

        $sections = [];
        foreach (array_unique([...array_keys($previous['snapshot'] ?? []), ...array_keys($active['snapshot'])]) as $section) {
            if (! in_array($section, ['tables', 'exported_at'], true)
                && ($previous['snapshot'][$section] ?? null) !== ($active['snapshot'][$section] ?? null)) {
                $sections[] = $section;
            }
        }

        return ['scope' => 'registry', 'from_generation' => $previous['generation'] ?? null,
            'to_generation' => $active['generation'], 'tables' => $tables, 'changed_sections' => $sections];
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
