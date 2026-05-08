<?php

namespace App\Console\Commands;

use App\Services\Ops\OfflineSmokeReportService;
use Illuminate\Console\Command;

class OpsOfflineSmokeCommand extends Command
{
    protected $signature = 'ops:offline-smoke
        {--json : Emit machine-readable JSON}
        {--compact : Emit redacted compact JSON; requires --json}
        {--profile=offline_review : Offline profile to inspect}
        {--hours=24 : Audit summary window in hours}';

    protected $description = 'Manual read-only offline smoke report for status, audit, MCP catalog, and local runtime posture';

    public function handle(OfflineSmokeReportService $service): int
    {
        $profile = (string) $this->option('profile');
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);

        if (! array_key_exists($profile, (array) config('offline_policy.profiles', []))) {
            $this->error("Unknown offline profile: {$profile}");

            return self::INVALID;
        }

        if (! is_int($hours) || $hours < 1 || $hours > 168) {
            $this->error('Hours must be an integer from 1 to 168.');

            return self::INVALID;
        }

        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('Use --compact with --json.');

            return self::INVALID;
        }

        $payload = $service->collect($profile, $hours);

        if ($this->option('json')) {
            $jsonPayload = $this->option('compact') ? $service->compactPayload($payload) : $payload;
            $json = json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode offline smoke JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'offline smoke: %s profile=%s hours=%d healthy=%d watch=%d degraded=%d blocked=%d',
            $payload['status'] ?? 'unknown',
            $payload['profile'] ?? $profile,
            (int) ($payload['window_hours'] ?? $hours),
            (int) ($payload['summary']['healthy'] ?? 0),
            (int) ($payload['summary']['watch'] ?? 0),
            (int) ($payload['summary']['degraded'] ?? 0),
            (int) ($payload['summary']['blocked'] ?? 0),
        ));

        foreach (($payload['sections'] ?? []) as $name => $section) {
            $this->line(sprintf(
                '%s: %s - %s',
                str_replace('_', '-', (string) $name),
                (string) ($section['status'] ?? 'unknown'),
                (string) ($section['detail'] ?? 'no detail'),
            ));
        }

        return self::SUCCESS;
    }
}
