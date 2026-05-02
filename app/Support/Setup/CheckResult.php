<?php

namespace App\Support\Setup;

/**
 * Single read-only check outcome. Status: pass|warn|fail|skip.
 */
final class CheckResult
{
    public const STATUS_PASS = 'pass';

    public const STATUS_WARN = 'warn';

    public const STATUS_FAIL = 'fail';

    public const STATUS_SKIP = 'skip';

    private const ALLOWED_STATUSES = [
        self::STATUS_PASS,
        self::STATUS_WARN,
        self::STATUS_FAIL,
        self::STATUS_SKIP,
    ];

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $group,
        public readonly string $name,
        public readonly string $status,
        public readonly string $message = '',
        public readonly array $context = [],
    ) {
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status '{$status}' for setup check '{$group}.{$name}'");
        }
    }

    public static function pass(string $group, string $name, string $message = '', array $context = []): self
    {
        return new self($group, $name, self::STATUS_PASS, $message, $context);
    }

    public static function warn(string $group, string $name, string $message = '', array $context = []): self
    {
        return new self($group, $name, self::STATUS_WARN, $message, $context);
    }

    public static function fail(string $group, string $name, string $message = '', array $context = []): self
    {
        return new self($group, $name, self::STATUS_FAIL, $message, $context);
    }

    public static function skip(string $group, string $name, string $message = '', array $context = []): self
    {
        return new self($group, $name, self::STATUS_SKIP, $message, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'group' => $this->group,
            'name' => $this->name,
            'status' => $this->status,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
