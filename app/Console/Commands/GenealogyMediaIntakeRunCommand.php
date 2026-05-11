<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyMediaIntakeRunService;
use Illuminate\Console\Command;

class GenealogyMediaIntakeRunCommand extends Command
{
    protected $signature = 'genealogy:media-intake-run
        {--tree=4 : Tree ID to operate on}
        {--root= : File registry / Nextcloud root; defaults to genealogy.nextcloud_root}
        {--limit=50 : Max rows passed to subcommands}
        {--stage : Run intake staging preview}
        {--save-run : Persist staged intake snapshot; requires --stage and --confirm-noncanonical-write}
        {--confirm-noncanonical-write : Required for --save-run; never enables canonical genealogy writes}
        {--transcribe-dry-run : List HTR-eligible media without writing transcripts}
        {--enrich-dry-run : List enrichment-eligible media without generating proposals or links}
        {--evidence-assets : Inspect pending review packet evidence assets without downloads or writes}
        {--capture-preflight : Inspect approved evidence capture rows without downloads, storage writes, or links}
        {--post-capture-dry-run : Run the read-only handoff checks needed after captures: capture preflight, HTR dry-run, and enrichment dry-run}
        {--dry-run : Plan selected steps without invoking subcommands}
        {--json : Emit machine-readable JSON}
        {--compact : Omit path/output previews for operator dashboards and MCP}';

    protected $description = 'Write-gated Genea media intake wrapper for safe staging, dry-runs, and review-first orchestration';

    public function handle(GenealogyMediaIntakeRunService $runner): int
    {
        $payload = $runner->run([
            'tree_id' => (int) $this->option('tree'),
            'root' => $this->option('root') ? (string) $this->option('root') : null,
            'limit' => (int) $this->option('limit'),
            'stage' => (bool) $this->option('stage'),
            'save_run' => (bool) $this->option('save-run'),
            'confirm_noncanonical_write' => (bool) $this->option('confirm-noncanonical-write'),
            'transcribe_dry_run' => (bool) $this->option('transcribe-dry-run'),
            'enrich_dry_run' => (bool) $this->option('enrich-dry-run'),
            'evidence_assets' => (bool) $this->option('evidence-assets'),
            'capture_preflight' => (bool) $this->option('capture-preflight'),
            'post_capture_dry_run' => (bool) $this->option('post-capture-dry-run'),
            'dry_run' => (bool) $this->option('dry-run'),
            'compact' => (bool) $this->option('compact'),
        ]);

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode genealogy media intake run JSON.');

                return self::FAILURE;
            }

            $this->line($json);
        } else {
            $this->line($runner->toText($payload));
        }

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
