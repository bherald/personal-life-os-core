<?php

namespace App\Console\Commands;

use App\Services\Ops\UpstreamDocumentationConsistencyService;
use Illuminate\Console\Command;

class WhiteroomProvenanceAuditCommand extends Command
{
    protected $signature = 'whiteroom:provenance-audit
        {--json : Emit machine-readable JSON}
        {--compact : Omit passing check detail}
        {--fail-on-drift : Return failure when a contracted documentation row is missing or stale}';

    protected $description = 'Audit contracted upstream source revisions against provenance and watchlist documentation';

    public function handle(UpstreamDocumentationConsistencyService $service): int
    {
        $payload = $service->audit();
        $output = (bool) $this->option('compact') ? $service->compact($payload) : $payload;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $summary = (array) ($payload['summary'] ?? []);
            $this->line(sprintf(
                'Upstream documentation provenance: %s  projects=%d  checks=%d  failures=%d',
                strtoupper((string) ($payload['status'] ?? 'fail')),
                (int) ($summary['contract_projects'] ?? 0),
                (int) ($summary['checks'] ?? 0),
                (int) ($summary['failed'] ?? 0)
            ));

            foreach ((array) ($payload['checks'] ?? []) as $check) {
                if (! is_array($check) || (bool) ($check['passed'] ?? false)) {
                    continue;
                }

                $this->warn(sprintf(
                    '%s: %s in %s',
                    (string) ($check['reason_code'] ?? 'documentation_revision_stale'),
                    (string) ($check['project_key'] ?? 'unknown'),
                    (string) ($check['document'] ?? 'contract')
                ));
            }
        }

        return (bool) $this->option('fail-on-drift') && ($payload['status'] ?? 'fail') !== 'pass'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
