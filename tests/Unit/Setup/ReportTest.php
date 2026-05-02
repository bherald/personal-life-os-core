<?php

namespace Tests\Unit\Setup;

use App\Support\Setup\CheckResult;
use App\Support\Setup\Report;
use PHPUnit\Framework\TestCase;

class ReportTest extends TestCase
{
    public function test_status_is_pass_when_only_pass_results(): void
    {
        $report = new Report('core', false, [
            CheckResult::pass('env', 'APP_KEY'),
            CheckResult::pass('php', 'ext.curl'),
        ]);

        $this->assertSame('pass', $report->status());
        $this->assertSame(0, $report->exitCode());
    }

    public function test_status_is_warn_when_warnings_present_without_strict(): void
    {
        $report = new Report('core', false, [
            CheckResult::pass('env', 'APP_KEY'),
            CheckResult::warn('binaries', 'ffmpeg', 'not found'),
        ]);

        $this->assertSame('warn', $report->status());
        $this->assertSame(0, $report->exitCode());
    }

    public function test_strict_promotes_warnings_to_failure(): void
    {
        $report = new Report('core', true, [
            CheckResult::pass('env', 'APP_KEY'),
            CheckResult::warn('binaries', 'ffmpeg', 'not found'),
        ]);

        $this->assertSame('fail', $report->status());
        $this->assertSame(1, $report->exitCode());
    }

    public function test_status_is_fail_when_any_check_failed_regardless_of_strict(): void
    {
        $report = new Report('core', false, [
            CheckResult::pass('env', 'APP_KEY'),
            CheckResult::fail('services', 'mysql', 'cannot connect'),
        ]);

        $this->assertSame('fail', $report->status());
        $this->assertSame(1, $report->exitCode());
    }

    public function test_status_is_skip_when_all_checks_skipped(): void
    {
        $report = new Report('core', false, [
            CheckResult::skip('services', 'mysql', 'skipped'),
            CheckResult::skip('docker', 'compose', 'skipped'),
        ]);

        $this->assertSame('skip', $report->status());
        $this->assertSame(0, $report->exitCode());
    }

    public function test_totals_count_each_status(): void
    {
        $report = new Report('media', false, [
            CheckResult::pass('env', 'a'),
            CheckResult::pass('env', 'b'),
            CheckResult::warn('binaries', 'ffmpeg'),
            CheckResult::fail('services', 'tika'),
            CheckResult::skip('docker', 'compose'),
        ]);

        $this->assertSame([
            'pass' => 2,
            'warn' => 1,
            'fail' => 1,
            'skip' => 1,
            'total' => 5,
        ], $report->totals());
    }

    public function test_to_array_emits_stable_top_level_shape(): void
    {
        $report = new Report('core', true, [
            CheckResult::pass('env', 'APP_KEY'),
        ]);

        $payload = $report->toArray();

        $this->assertSame(['profile', 'strict', 'status', 'totals', 'checks'], array_keys($payload));
        $this->assertSame('core', $payload['profile']);
        $this->assertTrue($payload['strict']);
        $this->assertSame('pass', $payload['status']);
        $this->assertIsArray($payload['checks']);
        $this->assertCount(1, $payload['checks']);
    }
}
