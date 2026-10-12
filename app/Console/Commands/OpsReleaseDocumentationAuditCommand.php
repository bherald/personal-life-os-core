<?php

namespace App\Console\Commands;

use App\Services\Ops\ReleaseDocumentationAuditService;
use Illuminate\Console\Command;
use RuntimeException;

class OpsReleaseDocumentationAuditCommand extends Command
{
    protected $signature = 'ops:release-documentation-audit
        {--base=HEAD^ : Base Git revision for a committed release range}
        {--head=HEAD : Head Git revision for a committed release range}
        {--staged : Audit the staged index instead of a committed range}
        {--json : Emit machine-readable JSON}
        {--compact : Omit passing rule and changed-path detail}
        {--fail-on-gaps : Return failure when required release documentation is absent}';

    protected $description = 'Audit changed production surfaces for required release, operations, architecture, and provenance documentation';

    public function handle(ReleaseDocumentationAuditService $service): int
    {
        try {
            $files = $service->collectChangedFiles(
                (string) $this->option('base'),
                (string) $this->option('head'),
                (bool) $this->option('staged')
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $payload = $service->evaluate($files);
        $output = $this->option('compact') ? $service->compactPayload($payload) : $payload;

        if ($this->option('json')) {
            $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode release documentation audit JSON.');

                return self::FAILURE;
            }

            $this->line($json);
        } else {
            $summary = (array) ($payload['summary'] ?? []);
            $this->line(sprintf(
                'Release documentation audit: %s  production_files=%d  docs=%d  rules=%d  gaps=%d',
                strtoupper((string) ($payload['status'] ?? 'fail')),
                (int) ($summary['production_files'] ?? 0),
                (int) ($summary['documentation_files'] ?? 0),
                (int) ($summary['triggered_rules'] ?? 0),
                (int) ($summary['missing_documents'] ?? 0),
            ));

            foreach ((array) ($payload['rules'] ?? []) as $result) {
                if (! is_array($result) || ($result['passed'] ?? false)) {
                    continue;
                }
                $this->warn(sprintf(
                    '%s: %s',
                    (string) ($result['reason_code'] ?? 'required_documentation_missing'),
                    implode(', ', (array) ($result['missing_documents'] ?? []))
                ));
            }
        }

        return $this->option('fail-on-gaps') && ($payload['status'] ?? 'fail') !== 'pass'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
