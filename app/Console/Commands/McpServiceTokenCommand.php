<?php

namespace App\Console\Commands;

use App\Services\McpServiceTokenService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/** Existing Passport issuance, explicit local administrator execution, private output only. */
class McpServiceTokenCommand extends Command
{
    protected $signature = 'mcp:service-token {action : issue, status or revoke} {--user= : Existing owner ID} {--name= : Token label} {--scope=* : Exact MCP scope, repeated as needed} {--ttl= : Explicit lifetime, 60 through 7776000 seconds (90 days)} {--output= : New file in an existing private directory} {--id= : Exact token ID to revoke} {--execute : Execute the reviewed operation; default is dry run}';

    protected $description = 'Plan, issue or revoke an owner-scoped MCP token without printing its bearer';

    public function handle(McpServiceTokenService $tokens): int
    {
        $issued = null;
        $stream = null;
        $path = null;
        try {
            $user = $this->option('user');
            if (! is_string($user) || ! ctype_digit($user) || (int) $user < 1) {
                throw new RuntimeException('Specify an existing positive --user owner ID.');
            }
            if ($this->argument('action') === 'status') {
                $status = $tokens->status((int) $user, (string) $this->option('id'));
                $this->line(json_encode($status, JSON_THROW_ON_ERROR));

                return $status['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
            }
            if ($this->argument('action') === 'revoke') {
                $plan = $tokens->revocationPlan((int) $user, (string) $this->option('id'));
                if (! $this->option('execute')) {
                    $this->line(json_encode(['dry_run' => true, 'action' => 'revoke'] + $plan, JSON_THROW_ON_ERROR));

                    return self::SUCCESS;
                }
                if (! $tokens->revoke((int) $user, $plan['id'])) {
                    throw new RuntimeException('Exact MCP token was not revoked.');
                }
                $this->line(json_encode(['revoked' => true] + $plan, JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            if ($this->argument('action') !== 'issue') {
                throw new RuntimeException('Action must be issue, status or revoke.');
            }
            $path = $this->option('output');
            $ttl = $this->option('ttl');
            if (! is_string($ttl) || ! ctype_digit($ttl)) {
                throw new RuntimeException('Supply an explicit integer lifetime.');
            }
            if (! is_string($path) || ! str_starts_with($path, '/') || realpath(dirname($path)) !== dirname($path)
                || is_link($path) || file_exists($path) || (fileperms(dirname($path)) & 0077) !== 0
                || ! function_exists('posix_geteuid') || fileowner(dirname($path)) !== posix_geteuid()) {
                throw new RuntimeException('Use a new absolute output file in an existing owner-private directory.');
            }
            $plan = $tokens->plan((int) $user, (string) $this->option('name'), $this->option('scope'), (int) $ttl);
            if (! $this->option('execute')) {
                $this->line(json_encode(['dry_run' => true, 'action' => 'issue', 'token_file' => $path] + $plan, JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            $stream = fopen($path, 'x');
            if ($stream === false || ! chmod($path, 0600)) {
                throw new RuntimeException('Private output reservation failed.');
            }
            $issued = $tokens->issue((int) $user, (string) $this->option('name'), $this->option('scope'), (int) $ttl);
            if (fwrite($stream, $issued['access_token']) !== strlen($issued['access_token']) || ! fflush($stream)) {
                throw new RuntimeException('Private output was incomplete.');
            }
            fclose($stream);
            $stream = null;
            unset($issued['access_token']);
            $this->line(json_encode($issued + ['token_file' => $path, 'bearer_printed' => false], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $rollback = null;
            if (isset($issued['id'])) {
                try {
                    $rollback = $tokens->revoke((int) $user, $issued['id']);
                } catch (Throwable) {
                    $rollback = false;
                }
            }
            if (is_resource($stream)) {
                fclose($stream);
                if (is_string($path) && $rollback !== false) {
                    unlink($path);
                }
            }
            $this->error('MCP token operation failed; no bearer printed.');
            if ($rollback === false) {
                $this->line(json_encode(['token_id' => $issued['id'], 'owner_id' => (int) $user,
                    'revocation_required' => true, 'automatic_retry_allowed' => false], JSON_THROW_ON_ERROR));
            }

            return self::FAILURE;
        }
    }
}
