<?php

declare(strict_types=1);

namespace App\Support\Ops;

/**
 * One preflight result: a named check and its verdict.
 *
 * The value object exists so the check logic can live in a reusable class
 * (ThaiRailsChecks, RealtimeChecks, LaneFlagChecks) while the commands, the
 * ProductionPreflight report and the tests all read the SAME shape. A check is
 * a name, a verdict and a message — never a credential, never a raw
 * configuration value.
 *
 * VERDICTS
 *   PASS  the requirement is satisfied
 *   WARN  acceptable, but an operator should look at it
 *   FAIL  the release / the lane must not proceed
 *   SKIP  not applicable here (the lane is off, or its config file is absent)
 */
final class CheckResult
{
    public const PASS = 'PASS';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    public const SKIP = 'SKIP';

    /**
     * @param  list<string>  $failures  lanes whose FAIL verdicts must fail the run
     */
    private function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $message,
        private readonly array $failures = [],
    ) {}

    public static function pass(string $name, string $message): self
    {
        return new self($name, self::PASS, $message);
    }

    public static function warn(string $name, string $message): self
    {
        return new self($name, self::WARN, $message);
    }

    public static function fail(string $name, string $message): self
    {
        return new self($name, self::FAIL, $message, [$name]);
    }

    /**
     * A lane that is deliberately dark, or whose configuration file this build
     * does not carry. A skip is never a failure and never a success.
     */
    public static function skip(string $name, string $message): self
    {
        return new self($name, self::SKIP, $message);
    }

    public function isFailure(): bool
    {
        return $this->status === self::FAIL;
    }

    /**
     * The lanes whose failure this result contributes to. Empty for every
     * non-FAIL verdict, so a caller can accumulate the failing lane names with
     * one array_merge across the whole run.
     *
     * @return list<string>
     */
    public function failingLanes(): array
    {
        return $this->failures;
    }

    /**
     * The single line the console prints and the report stores.
     */
    public function line(): string
    {
        return sprintf('%s [%s] %s', $this->status, $this->name, $this->message);
    }

    /**
     * The report shape the console and the JSON emitter use: `id` and `detail`
     * are the names the realtime:check contract has always published, so a
     * deploy script parsing that JSON keeps working.
     *
     * @return array{id: string, status: string, detail: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->name,
            'status' => $this->status,
            'detail' => $this->message,
        ];
    }
}
