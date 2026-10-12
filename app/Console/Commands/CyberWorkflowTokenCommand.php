<?php

namespace App\Console\Commands;

use App\Services\CyberWorkflowTokenService;
use Illuminate\Console\Command;
use Throwable;

/** Local administrator command. Bearers are written only to a new owner-private file. */
final class CyberWorkflowTokenCommand extends Command
{
    protected $signature = 'cyber:workflow-token {action : issue or revoke} {--user= : Existing owner ID} {--name= : Token label} {--scope=* : Exact CYBER scope, repeated as needed} {--ttl=3600 : Lifetime seconds, 60 through 86400} {--output= : New file in an existing private directory} {--id= : Exact token ID to revoke}';

    protected $description = 'Issue or revoke a bounded owner-scoped CYBER job token without printing its bearer';

    public function handle(CyberWorkflowTokenService $tokens): int
    {
        $user = $this->option('user');
        if (! is_string($user) || ! ctype_digit($user) || (int) $user < 1) {
            $this->error('Specify an existing positive owner ID using --user.');

            return self::FAILURE;
        }
        $issued = null;
        $stream = null;
        $path = null;
        try {
            if ($this->argument('action') === 'revoke') {
                $id = $this->option('id');
                if (! is_string($id) || ! preg_match('/^[a-f0-9]{80}$/D', $id) || ! $tokens->revoke((int) $user, $id)) {
                    $this->error('No matching active CYBER personal token was revoked for that owner.');

                    return self::FAILURE;
                }
                $this->line(json_encode(['revoked' => true, 'owner_id' => (int) $user, 'id' => $id], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            if ($this->argument('action') !== 'issue') {
                $this->error('Action must be issue or revoke.');

                return self::FAILURE;
            }
            $path = $this->option('output');
            $ttl = $this->option('ttl');
            if (! is_string($path) || ! str_starts_with($path, '/') || realpath(dirname($path)) !== dirname($path)
                || is_link($path) || file_exists($path) || (fileperms(dirname($path)) & 0077) !== 0
                || ! function_exists('posix_geteuid') || fileowner(dirname($path)) !== posix_geteuid()
                || ! is_string($ttl) || ! ctype_digit($ttl)) {
                $this->error('Use a new absolute output file in an existing private directory and an integer lifetime.');

                return self::FAILURE;
            }
            $stream = fopen($path, 'x');
            if ($stream === false || ! chmod($path, 0600)) {
                throw new \RuntimeException('Cannot reserve private output.');
            }
            $issued = $tokens->issue((int) $user, (string) $this->option('name'), $this->option('scope'), (int) $ttl);
            $contents = $issued['access_token'];
            if (fwrite($stream, $contents) !== strlen($contents) || ! fflush($stream)) {
                throw new \RuntimeException('Cannot write complete private output.');
            }
            fclose($stream);
            $stream = null;
            unset($issued['access_token']);
            $this->line(json_encode($issued + ['token_file' => $path, 'bearer_printed' => false], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            if (isset($issued['id'])) {
                $tokens->revoke((int) $user, $issued['id']);
            }
            if (is_resource($stream)) {
                fclose($stream);
                if (is_string($path)) {
                    unlink($path);
                }
            }
            $this->error('CYBER token operation failed; no bearer was printed.');

            return self::FAILURE;
        }
    }
}
