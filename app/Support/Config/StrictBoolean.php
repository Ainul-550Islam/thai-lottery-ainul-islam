<?php

declare(strict_types=1);

namespace App\Support\Config;

/**
 * Strict boolean parsing for money-governing configuration switches.
 *
 * WHY THIS CLASS EXISTS
 * A real-money switch is not a "feature flag". The two idioms Laravel
 * configuration normally reaches for are both wrong for one:
 *
 *   filter_var(env(SOME_SWITCH, false), FILTER_VALIDATE_BOOLEAN)
 *       accepts 'yes', 'on' and '1' as TRUE. A typo, a Helm value, a
 *       CI variable or a copied .env line therefore OPENS a money gate
 *       that nobody deliberately opened.
 *
 *   (bool) env(SOME_SWITCH, false)
 *       casts any non-empty string to TRUE - including the string
 *       'false', which is the single most dangerous value an operator
 *       can type while believing they are turning something off.
 *
 * The switch names above are deliberately written as bare placeholders and
 * not as quoted env() literals: EnvTemplateCompletenessTest scans every PHP
 * file under app/ for that pattern, so an illustrative snippet in a docblock
 * would be audited as a real, undocumented configuration switch.
 *
 * Both coerce. Coercion means the configuration no longer says what the
 * deployment does, and a gate that opens by accident cannot be audited.
 *
 * THE CONTRACT
 * `parse()` answers with a boolean ONLY when the value unambiguously is
 * one: a real bool, or a string whose trimmed, lower-cased form is
 * exactly 'true' or exactly 'false'. Everything else - null, an empty
 * string, an int, '1', 'yes', 'on', 'TRUE ' with trailing junk, an
 * array - returns null, meaning "this is not a boolean".
 *
 * Callers then decide what "not a boolean" means, and for money the
 * answer is always the same: `orClosed()`. A missing, null, malformed or
 * incorrectly typed switch value results in CLOSED. Nothing here ever
 * guesses an operator's intention, and nothing here can be talked into
 * opening a gate by a value that was not written as a boolean.
 *
 * READ-ONLY AND SIDE-EFFECT FREE
 * Parsing never reads the environment itself, never touches the container
 * and never logs: it is a pure function of the value handed to it, so it
 * is safe to call while `config/*.php` is being evaluated (which happens
 * before the framework is booted) and safe to call from `config:cache`.
 *
 * DIAGNOSTICS WITHOUT LEAKAGE
 * `describe()` classifies a value for a readiness report as one of
 * 'true', 'false', 'missing', 'empty' or 'malformed:<type>'. It never
 * echoes the value itself: a switch that was meant to be boolean may have
 * been pasted with a secret in it, and a readiness report is printed to
 * terminals, CI logs and JSON that operators forward to each other.
 */
final class StrictBoolean
{
    /**
     * The only two spellings that mean TRUE / FALSE.
     *
     * Deliberately short: 'yes', 'on', '1', 'enabled' and 'y' are NOT
     * accepted, because accepting them is precisely the loose truthy
     * comparison a money switch must not depend on.
     */
    private const TRUE_SPELLING = 'true';

    private const FALSE_SPELLING = 'false';

    /**
     * This class is a namespace for pure functions; it is never instantiated.
     *
     * @codeCoverageIgnore
     */
    private function __construct() {}

    /**
     * Parse a configuration value as a boolean, or answer null.
     *
     * null means "this value is not a boolean", which is a different fact
     * from "this value is false". Callers that govern money must be able
     * to tell those apart, because one is a decision and the other is a
     * configuration defect that has to be reported.
     */
    public static function parse(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = strtolower(trim($value));

        if ($trimmed === self::TRUE_SPELLING) {
            return true;
        }

        if ($trimmed === self::FALSE_SPELLING) {
            return false;
        }

        return null;
    }

    /**
     * Parse, and treat anything that is not a boolean as CLOSED.
     *
     * This is the only acceptable reading for a real-money switch: the
     * value must have been written as a boolean by somebody who meant it,
     * or the gate stays shut.
     */
    public static function orClosed(mixed $value): bool
    {
        return self::parse($value) ?? false;
    }

    /**
     * Parse, falling back to an explicit default when the value is absent
     * or malformed.
     *
     * For switches whose safe direction is ON - a readiness requirement, an
     * idempotency guard, a reconciliation job - the default is `true`, so
     * deleting the line keeps the protection and a malformed line is
     * reported by `describe()` instead of silently disabling it.
     */
    public static function orDefault(mixed $value, bool $default): bool
    {
        return self::parse($value) ?? $default;
    }

    /**
     * Is this value a properly parsed boolean at all?
     *
     * Used by readiness reporting to separate "the operator chose false"
     * (a valid, closed deployment) from "nobody can tell what this means"
     * (a configuration defect that blocks technical readiness).
     */
    public static function isBoolean(mixed $value): bool
    {
        return self::parse($value) !== null;
    }

    /**
     * Classify a value for a report, without ever echoing the value.
     *
     * @return 'true'|'false'|'missing'|'empty'|'malformed:null'|'malformed:integer'|'malformed:double'|'malformed:string'|'malformed:array'|'malformed:object'|'malformed:resource'|'malformed:unknown'
     */
    public static function describe(mixed $value): string
    {
        $parsed = self::parse($value);

        if ($parsed === true) {
            return 'true';
        }

        if ($parsed === false) {
            return 'false';
        }

        if ($value === null) {
            // An env key that was never set and a config key with no default
            // both arrive as null. Either way nobody wrote a boolean.
            return 'missing';
        }

        if (is_string($value) && trim($value) === '') {
            // 'X_ENABLED=' in a .env file: present but blank. Distinguished
            // from 'missing' because the fix is different (delete the line
            // versus write one), and because a blank value is the classic
            // artefact of a templated deployment manifest.
            return 'empty';
        }

        return 'malformed:'.self::typeName($value);
    }

    /**
     * A stable, non-revealing type name for diagnostics.
     */
    private static function typeName(mixed $value): string
    {
        if (is_string($value)) {
            return 'string';
        }

        if (is_int($value)) {
            return 'integer';
        }

        if (is_float($value)) {
            return 'double';
        }

        if (is_array($value)) {
            return 'array';
        }

        if (is_object($value)) {
            return 'object';
        }

        if (is_resource($value)) {
            return 'resource';
        }

        return 'unknown';
    }
}
