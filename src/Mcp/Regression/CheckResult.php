<?php

declare(strict_types=1);

namespace App\Mcp\Regression;

/**
 * The outcome of one regression check.
 */
final class CheckResult
{
    private function __construct(
        public readonly string $group,
        public readonly string $name,
        public readonly string $status,
        public readonly string $detail,
    ) {
    }

    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const SKIP = 'skip';

    public static function pass(string $group, string $name, string $detail = ''): self
    {
        return new self($group, $name, self::PASS, $detail);
    }

    public static function fail(string $group, string $name, string $detail): self
    {
        return new self($group, $name, self::FAIL, $detail);
    }

    /**
     * A check that could not run — the server does not expose what it needs, or
     * the client does not support it. Not a failure: the surface a server
     * exposes is a configuration choice.
     */
    public static function skip(string $group, string $name, string $detail): self
    {
        return new self($group, $name, self::SKIP, $detail);
    }

    public function isFailure(): bool
    {
        return self::FAIL === $this->status;
    }

    public function isPass(): bool
    {
        return self::PASS === $this->status;
    }
}
