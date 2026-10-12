<?php

namespace App\Console\Commands;

use App\Services\JoplinReviewMirrorService;
use Illuminate\Console\Command;
use Throwable;

class JoplinReviewMirrorCommand extends Command
{
    protected $signature = 'joplin:review-mirror {bundle : Private manifest bundle JSON} {--previous-bundle= : Exact private predecessor bundle used to verify normalized or PLOS-misrouted notes} {--apply : Publish changed owned notes and attachments} {--prune : Remove verified owned notes absent from the bundle; requires --apply and a complete run} {--prune-source-path=* : Limit pruning to these exact unlisted owned source paths} {--limit=0 : Bound notes published for a pilot; zero means all} {--json : JSON report}';

    protected $description = 'Preview or incrementally publish the private review manifest to the configured Joplin notebook';

    public function handle(JoplinReviewMirrorService $service): int
    {
        try {
            $result = $service->run(
                (string) $this->argument('bundle'),
                (bool) $this->option('apply'),
                (int) $this->option('limit'),
                (bool) $this->option('prune'),
                $this->option('previous-bundle') ? (string) $this->option('previous-bundle') : null,
                (array) $this->option('prune-source-path'),
            );
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line(json_encode(['status' => 'blocked', 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
