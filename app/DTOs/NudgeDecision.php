<?php

namespace App\DTOs;

class NudgeDecision
{
    public const ACTION_CONTINUE = 'continue';
    public const ACTION_UNWIND_GRACEFUL = 'unwind_graceful';
    public const ACTION_UNWIND_HARD = 'unwind_hard';

    public string $action;
    public ?string $trigger;
    public ?string $detail;

    public function __construct(string $action, ?string $trigger = null, ?string $detail = null)
    {
        $this->action = $action;
        $this->trigger = $trigger;
        $this->detail = $detail;
    }

    public static function continue(): self
    {
        return new self(self::ACTION_CONTINUE);
    }

    public static function unwind(string $trigger, ?string $detail = null, bool $hard = false): self
    {
        return new self(
            $hard ? self::ACTION_UNWIND_HARD : self::ACTION_UNWIND_GRACEFUL,
            $trigger,
            $detail
        );
    }

    public function shouldContinue(): bool
    {
        return $this->action === self::ACTION_CONTINUE;
    }

    public function isHard(): bool
    {
        return $this->action === self::ACTION_UNWIND_HARD;
    }
}
