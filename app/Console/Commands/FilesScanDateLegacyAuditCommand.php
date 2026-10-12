<?php

namespace App\Console\Commands;

use App\Services\Files\ScanDateLegacyMetadataAuditService;
use App\Services\ScanDateLegacyAuditPolicyService;
use Illuminate\Console\Command;
use RuntimeException;

class FilesScanDateLegacyAuditCommand extends Command
{
    protected $signature = 'files:audit-legacy-scan-dates
                            {--limit=  : Maximum legacy rows to inspect}
                            {--offset=0 : Zero-based page offset}
                            {--json    : Emit machine-readable JSON}
                            {--compact : Omit per-file comparison packets}
                            {--weak-dates : Inspect checked weak catalog dates as evidence only}';

    protected $description = 'Read-only comparison of legacy AI scan dates against current file metadata';

    public function handle(
        ScanDateLegacyMetadataAuditService $audit,
        ScanDateLegacyAuditPolicyService $policyService,
    ): int {
        try {
            $policy = $policyService->required();
            $limit = $policyService->resolveLimit($this->option('limit'), $policy);
            $offset = $policyService->resolveOffset($this->option('offset'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $compact = (bool) $this->option('compact');
        $weakDates = (bool) $this->option('weak-dates');
        $payload = $audit->{ $weakDates ? 'auditWeakDates' : 'audit' }(
            $limit,
            $offset,
            $policy['exiftool_timeout_seconds'],
            ! $compact,
        );
        $payload['policy_source'] = ScanDateLegacyAuditPolicyService::CONFIG_KEY;

        if ((bool) $this->option('json')) {
            $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
            if (! $compact) {
                $flags |= JSON_PRETTY_PRINT;
            }
            $this->line(json_encode($payload, $flags));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            $weakDates
                ? 'Weak-date evidence audit: selected=%d total=%d remaining=%d; no catalog or file changes.'
                : 'Legacy scan-date audit: selected=%d total=%d remaining=%d; no catalog or file changes.',
            $payload['selected'],
            $payload[$weakDates ? 'total_weak_rows' : 'total_legacy_rows'],
            $payload['remaining_after_page'],
        ));
        $this->table(['Classification', 'Count'], array_map(
            static fn (string $classification, int $count): array => [$classification, $count],
            array_keys($payload['classifications']),
            array_values($payload['classifications']),
        ));
        $this->warn($weakDates
            ? 'Evidence only; no capture date is confirmed or automatically corrected.'
            : 'Automatic correction is unavailable: the legacy writer did not retain previous field values.');

        return self::SUCCESS;
    }
}
