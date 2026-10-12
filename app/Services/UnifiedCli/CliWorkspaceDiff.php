<?php

namespace App\Services\UnifiedCli;

use App\Services\OfflinePolicyService;
use Closure;
use RuntimeException;

/** Fixed Git reads through the existing native sandbox; no general shell input. */
final class CliWorkspaceDiff
{
    public function __construct(private readonly Closure $run, private readonly string $workspace) {}

    public function read(bool $staged = false): array
    {
        if (! is_dir($this->workspace.'/.git') && ! is_file($this->workspace.'/.git')) {
            throw new RuntimeException('The selected installation is not a Git checkout. Select an actual checkout workspace or attach a diff/source for review; deployed release trees are not Git repositories.');
        }
        $git = 'env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git --no-optional-locks --no-pager -c core.fsmonitor=false -c core.hooksPath=/dev/null';
        $root = trim(($this->run)($git.' rev-parse --show-toplevel'));
        if (realpath($root) !== realpath($this->workspace)) {
            throw new RuntimeException('Diff requires the selected workspace to be the Git root. Select the repository workspace or attach the source/diff you want reviewed.');
        }
        $base = $git.' diff --no-ext-diff --no-textconv --no-renames'.($staged ? ' --cached' : '');
        $paths = explode("\0", ($this->run)($base.' --name-only -z --'));
        $policy = new OfflinePolicyService(workspaceRoot: $this->workspace);
        $allowed = [];
        $excluded = [];
        foreach ($paths as $path) {
            if ($path === '') {
                continue;
            }
            if ($policy->classifyPath($path, 'read') !== 'repo_read') {
                $excluded[] = $path;
            } else {
                $allowed[] = $path;
            }
        }
        $bytes = '';
        foreach (array_chunk($allowed, 64) as $paths) {
            $arguments = implode(' ', array_map(fn ($path) => escapeshellarg(':(literal)'.$path), $paths));
            $bytes .= ($this->run)($base.' --binary -- '.$arguments);
        }

        return ['diff' => $bytes, 'staged' => $staged, 'paths' => $allowed, 'excluded_paths' => $excluded,
            'scope' => 'Tracked changes only; untracked files are not included. Protected paths are excluded before reading contents.'];
    }
}
