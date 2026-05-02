<?php

namespace App\Support\Setup;

/**
 * Aggregate of CheckResult rows for a single doctor run.
 *
 * Status precedence: any fail → fail; with strict, any warn → fail; else
 * any warn → warn; else any pass → pass; else skip.
 */
final class Report
{
    /**
     * @param  list<CheckResult>  $checks
     */
    public function __construct(
        public readonly string $profile,
        public readonly bool $strict,
        public readonly array $checks,
    ) {}

    public function status(): string
    {
        $hasPass = false;
        $hasWarn = false;

        foreach ($this->checks as $check) {
            if ($check->status === CheckResult::STATUS_FAIL) {
                return CheckResult::STATUS_FAIL;
            }
            if ($check->status === CheckResult::STATUS_WARN) {
                $hasWarn = true;
            }
            if ($check->status === CheckResult::STATUS_PASS) {
                $hasPass = true;
            }
        }

        if ($hasWarn) {
            return $this->strict ? CheckResult::STATUS_FAIL : CheckResult::STATUS_WARN;
        }

        return $hasPass ? CheckResult::STATUS_PASS : CheckResult::STATUS_SKIP;
    }

    public function exitCode(): int
    {
        return $this->status() === CheckResult::STATUS_FAIL ? 1 : 0;
    }

    /**
     * @return array{pass:int,warn:int,fail:int,skip:int,total:int}
     */
    public function totals(): array
    {
        $totals = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0, 'total' => 0];
        foreach ($this->checks as $check) {
            $totals[$check->status]++;
            $totals['total']++;
        }

        return $totals;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile,
            'strict' => $this->strict,
            'status' => $this->status(),
            'totals' => $this->totals(),
            'checks' => array_map(fn (CheckResult $r) => $r->toArray(), $this->checks),
        ];
    }
}
