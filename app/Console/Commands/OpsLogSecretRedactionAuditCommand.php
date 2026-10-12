<?php

namespace App\Console\Commands;

use App\Services\Ops\LogSecretRedactionAuditService;
use Illuminate\Console\Command;

class OpsLogSecretRedactionAuditCommand extends Command
{
    protected $signature = 'ops:log-secret-redaction-audit
        {--json : Emit machine-readable JSON}
        {--compact : Emit the summary only with --json}
        {--fail-on-violations : Return failure when a redaction contract fails}';

    protected $description = 'Run an in-memory audit of structured secret/private-text redaction and configured log sinks';

    public function handle(LogSecretRedactionAuditService $service): int
    {
        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('The --compact option is only supported with --json.');

            return self::FAILURE;
        }

        $payload = $service->evaluate();

        if ($this->option('json')) {
            $output = $this->option('compact')
                ? [
                    'schema' => $payload['schema'],
                    'status' => $payload['status'],
                    'mode' => $payload['mode'],
                    'summary' => $payload['summary'],
                    'failed_checks' => $payload['failed_checks'],
                    'source_audit' => $payload['source_audit'],
                    'free_form_message_expression_classification_covered' => $payload['free_form_message_expression_classification_covered'],
                    'free_form_message_body_covered' => $payload['free_form_message_body_covered'],
                    'direct_exception_message_interpolation_covered' => $payload['direct_exception_message_interpolation_covered'],
                    'exception_object_body_covered' => $payload['exception_object_body_covered'],
                    'framework_emergency_fallback_covered' => $payload['framework_emergency_fallback_covered'],
                ]
                : $payload;
            $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                $this->error('Failed to encode log secret redaction audit JSON.');

                return self::FAILURE;
            }

            $this->line($json);
        } else {
            $summary = (array) ($payload['summary'] ?? []);
            $this->line(sprintf(
                'Log secret redaction audit: %s  checks=%d  passed=%d  failed=%d  unprotected_channels=%d',
                strtoupper((string) ($payload['status'] ?? 'fail')),
                (int) ($summary['checks'] ?? 0),
                (int) ($summary['passed'] ?? 0),
                (int) ($summary['failed'] ?? 0),
                (int) ($summary['unprotected_channels'] ?? 0),
            ));
        }

        return $this->option('fail-on-violations') && ($payload['status'] ?? 'fail') !== 'pass'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
