<?php

namespace App\Services\UnifiedCli;

use Closure;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PDO;
use RuntimeException;

/** Exact local credential references and a scoped read-only MySQL connection. */
final class CliDataBindings
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly string $manifest,
        private readonly CliLocalBindings $credentials,
    ) {}

    /** Selection is metadata; only a registered database tool needs its binding. */
    public function operationContext(?array $toolDefinition = null): array
    {
        if ($toolDefinition !== null && CliLocalMcpServices::requiresDataBinding(
            (string) ($toolDefinition['mcp_server'] ?? ''), (string) ($toolDefinition['mcp_tool'] ?? ''))) {
            return $this->context();
        }
        $selection = (new CliConfiguration($this->store))->operationSelection($this->store->session($this->sessionId));

        return ['operation_target' => $selection['target'], 'tree_id' => $selection['tree_id']];
    }

    public function context(): array
    {
        [$selection, $definition] = $this->definition();

        return ['operation_target' => $selection['target'], 'tree_id' => $selection['tree_id'],
            'data_binding_sha256' => hash('sha256', json_encode([$selection['target'], $definition], JSON_THROW_ON_ERROR)),
            'data_read_only' => true];
    }

    /** Inspect local metadata only: no credentials resolved and no database opened. */
    public function inspect(): array
    {
        try {
            return ['configured' => true, 'connection_checked' => false, 'credentials_checked' => false,
                'context' => $this->context()];
        } catch (\Throwable $error) {
            return ['configured' => false, 'connection_checked' => false, 'credentials_checked' => false,
                'reason' => $error instanceof RuntimeException ? $error->getMessage() : 'Local data binding metadata is invalid.'];
        }
    }

    public function read(array $context, Closure $operation): mixed
    {
        $expected = $this->context();
        foreach ($expected as $key => $value) {
            if (($context[$key] ?? null) !== $value) {
                throw new RuntimeException('Data service context differs from the current target, tree or connection binding.');
            }
        }
        [, $definition] = $this->definition();
        $dsn = isset($definition['socket_path']) ? 'mysql:unix_socket='.$definition['socket_path']
            : 'mysql:host='.$definition['host'].';port='.$definition['port'];
        $dsn .= ';dbname='.$definition['database'].';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, $this->credentials->resolve($definition['username_reference']),
                $this->credentials->resolve($definition['password_reference']), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_PERSISTENT => false,
                    PDO::ATTR_TIMEOUT => 5, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $pdo->beginTransaction();
            $identity = $pdo->query('SELECT @@server_uuid AS server_uuid, DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
            if ($identity['server_uuid'] !== $definition['server_uuid'] || $identity['database_name'] !== $definition['database']) {
                throw new RuntimeException('Database identity differs from its explicit binding.');
            }
        } catch (\Throwable) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('Selected read-only database connection or identity is unavailable. No alternate target was tried.');
        }
        $connection = new MySqlConnection($pdo, $definition['database']);
        $connection->setReconnector(fn () => throw new RuntimeException('Automatic database reconnect and replay are disabled.'));
        $app = Facade::getFacadeApplication();
        if ($app === null) {
            $pdo->rollBack();
            throw new RuntimeException('The CLI framework container is unavailable.');
        }
        $hadDatabase = $app->bound('db');
        $previous = $hadDatabase ? $app->make('db') : null;
        try {
            if ($this->context() !== $expected) {
                throw new RuntimeException('Data binding changed while connecting; no service was dispatched.');
            }
            DB::swap($connection);
            $result = $operation($connection);
            $this->store->recordEvent($this->sessionId, 'data_target_read', $expected);

            return $result;
        } finally {
            if ($hadDatabase) {
                DB::swap($previous);
            } else {
                $app->forgetInstance('db');
                DB::clearResolvedInstance('db');
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->disconnect();
        }
    }

    /** Reference names only; the managed local worker resolves these in its parent. */
    public function credentialReferences(array $context): array
    {
        foreach ($this->context() as $key => $value) {
            if (($context[$key] ?? null) !== $value) {
                throw new RuntimeException('Worker credentials require the exact selected data context.');
            }
        }
        [, $definition] = $this->definition();

        return array_values(array_unique([$definition['username_reference'], $definition['password_reference']]));
    }

    private function definition(): array
    {
        $session = $this->store->session($this->sessionId);
        $configuration = new CliConfiguration($this->store);
        $selection = $configuration->operationSelection($session, workflow: true);
        if ($selection['target'] === null || $selection['tree_id'] === null) {
            throw new RuntimeException('Select /tree and inspect /target before using database tools. The default is this installation (local); select /target prod explicitly for production from another installation. A matching host-local data binding is required.');
        }
        $manifest = json_decode(CliLocalBindings::readPrivateFile($this->manifest), true, flags: JSON_THROW_ON_ERROR);
        $definition = $manifest['targets'][$selection['target']] ?? null;
        if (($manifest['schema'] ?? null) !== 'plos.cli.data-bindings.v1' || ! is_array($definition)
            || array_diff(array_keys($manifest), ['schema', 'targets']) !== []
            || array_diff(array_keys($definition), ['driver', 'database', 'server_uuid', 'socket_path', 'host', 'port',
                'username_reference', 'password_reference', 'tree_ids', 'read_only']) !== []
            || ($definition['driver'] ?? null) !== 'mysql' || ($definition['read_only'] ?? null) !== true
            || ! is_string($definition['database'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $definition['database'])
            || ! is_string($definition['server_uuid'] ?? null) || ! preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $definition['server_uuid'])
            || ! is_array($definition['tree_ids'] ?? null) || ! array_is_list($definition['tree_ids'])
            || ! in_array($selection['tree_id'], $definition['tree_ids'], true)) {
            throw new RuntimeException('An exact read-only MySQL binding with an allowed tree is required.');
        }
        foreach ($definition['tree_ids'] as $tree) {
            if (! is_int($tree) || $tree < 1) {
                throw new RuntimeException('Data binding tree IDs must be positive integers.');
            }
        }
        foreach (['username_reference', 'password_reference'] as $key) {
            if (! is_string($definition[$key] ?? null) || ! preg_match('/^host:[A-Za-z0-9][A-Za-z0-9_-]*:[A-Za-z_][A-Za-z0-9_]*$/D', $definition[$key])) {
                throw new RuntimeException('Database credentials require explicit local references.');
            }
        }
        if (isset($definition['socket_path'])) {
            $socket = $definition['socket_path'];
            if (isset($definition['host']) || isset($definition['port']) || ! is_string($socket)
                || ! str_starts_with($socket, '/') || preg_match('/[;\x00-\x1f\x7f]/', $socket)
                || realpath($socket) !== $socket || is_link($socket) || @filetype($socket) !== 'socket') {
                throw new RuntimeException('Data binding requires one exact existing local MySQL socket.');
            }
        } else {
            if (! is_string($definition['host'] ?? null) || @inet_pton($definition['host']) === false
                || ! is_int($definition['port'] ?? null) || $definition['port'] < 1 || $definition['port'] > 65535) {
                throw new RuntimeException('Database TCP bindings require a literal address and explicit port.');
            }
            $values = $configuration->effective($session)['values'];
            $host = str_contains($definition['host'], ':') ? '['.$definition['host'].']' : $definition['host'];
            (new CliNetworkBoundary)->requestOptions('http://'.$host.':'.$definition['port'], $values['cli.network'] ?? 'offline');
        }

        return [$selection, $definition];
    }
}
