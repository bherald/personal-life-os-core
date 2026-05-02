<?php

namespace Tests\Unit\Setup;

use App\Support\Setup\CheckResult;
use PHPUnit\Framework\TestCase;

class CheckResultTest extends TestCase
{
    public function test_factories_set_status_correctly(): void
    {
        $this->assertSame(CheckResult::STATUS_PASS, CheckResult::pass('env', 'APP_KEY')->status);
        $this->assertSame(CheckResult::STATUS_WARN, CheckResult::warn('env', 'APP_KEY')->status);
        $this->assertSame(CheckResult::STATUS_FAIL, CheckResult::fail('env', 'APP_KEY')->status);
        $this->assertSame(CheckResult::STATUS_SKIP, CheckResult::skip('env', 'APP_KEY')->status);
    }

    public function test_to_array_includes_all_fields(): void
    {
        $result = CheckResult::pass('php', 'ext.curl', 'curl loaded', ['version' => '8.3']);

        $this->assertSame([
            'group' => 'php',
            'name' => 'ext.curl',
            'status' => 'pass',
            'message' => 'curl loaded',
            'context' => ['version' => '8.3'],
        ], $result->toArray());
    }

    public function test_invalid_status_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CheckResult('env', 'APP_KEY', 'banana');
    }
}
