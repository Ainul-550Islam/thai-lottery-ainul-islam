<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Config;

use App\Support\Config\StrictBoolean;
use PHPUnit\Framework\TestCase;

/**
 * The strict boolean parser that governs every money switch.
 *
 * WHY THIS IS A PURE UNIT TEST
 * ----------------------------
 * The parser runs while `config/*.php` is being evaluated, which is before the
 * framework exists, so it must not depend on the container, the environment or
 * the database. Testing it without booting Laravel is not a convenience - it is
 * the only way to prove the property that actually matters, which is that the
 * parser behaves identically before and after the framework is up.
 *
 * The contract under test is asymmetric on purpose. Opening a money gate
 * requires a value that was deliberately written as a boolean; closing one
 * happens by default, by accident, by omission and by corruption alike. Every
 * test below is an instance of one of those two directions.
 */
final class StrictBooleanTest extends TestCase
{
    // =====================================================================
    // §1 Only a boolean is a boolean.
    // =====================================================================

    public function test_real_booleans_parse_to_themselves(): void
    {
        self::assertTrue(StrictBoolean::parse(true));
        self::assertFalse(StrictBoolean::parse(false));
        self::assertTrue(StrictBoolean::isBoolean(true));
        self::assertTrue(StrictBoolean::isBoolean(false));
    }

    public function test_the_exact_spellings_true_and_false_parse(): void
    {
        self::assertTrue(StrictBoolean::parse('true'));
        self::assertFalse(StrictBoolean::parse('false'));
    }

    public function test_surrounding_whitespace_and_case_are_tolerated(): void
    {
        // Tolerated because a deploy manifest, a YAML value and a hand-edited
        // .env file differ in whitespace and nobody means anything by it. Case
        // and padding are not ambiguity.
        foreach (['TRUE', 'True', ' true ', "\ttrue\n"] as $value) {
            self::assertTrue(StrictBoolean::parse($value), 'Rejected: '.json_encode($value));
        }

        foreach (['FALSE', 'False', ' false '] as $value) {
            self::assertFalse(StrictBoolean::parse($value), 'Rejected: '.json_encode($value));
        }
    }

    // =====================================================================
    // §2 The loose truthy spellings that opened gates by accident.
    // =====================================================================

    public function test_truthy_spellings_are_not_booleans(): void
    {
        // Each of these is accepted as TRUE by FILTER_VALIDATE_BOOLEAN, and each
        // of them casts to TRUE under `(bool)`. That is the exact mechanism by
        // which a copied .env line, a Helm value or a CI variable opens a money
        // gate nobody decided to open.
        foreach (['yes', 'y', 'on', '1', 'enabled', 'TRUEISH', 'tru', '-1', '2', '01'] as $value) {
            self::assertNull(StrictBoolean::parse($value), 'Accepted as a boolean: '.json_encode($value));
            self::assertFalse(StrictBoolean::isBoolean($value));
            self::assertFalse(StrictBoolean::orClosed($value), 'orClosed() opened on: '.json_encode($value));
        }
    }

    public function test_falsy_spellings_are_not_booleans_either(): void
    {
        // Symmetry matters. Accepting 'no' as FALSE while rejecting 'yes' would
        // mean the parser has an opinion about which direction is safe, and then
        // a value nobody wrote as a boolean would still be deciding behaviour.
        foreach (['no', 'n', 'off', '0', 'disabled', 'nothing'] as $value) {
            self::assertNull(StrictBoolean::parse($value), 'Accepted as a boolean: '.json_encode($value));
            self::assertFalse(StrictBoolean::orClosed($value));
        }
    }

    // =====================================================================
    // §3 Wrong types, absent values and corruption all answer CLOSED.
    // =====================================================================

    public function test_non_string_scalars_are_not_booleans(): void
    {
        foreach ([0, 1, -1, 0.0, 1.0] as $value) {
            self::assertNull(StrictBoolean::parse($value), 'Accepted as a boolean: '.var_export($value, true));
            self::assertFalse(StrictBoolean::orClosed($value));
        }
    }

    public function test_null_and_empty_strings_are_not_booleans(): void
    {
        self::assertNull(StrictBoolean::parse(null));
        self::assertNull(StrictBoolean::parse(''));
        self::assertNull(StrictBoolean::parse('   '));
        self::assertFalse(StrictBoolean::orClosed(null));
        self::assertFalse(StrictBoolean::orClosed(''));
    }

    public function test_compound_values_are_not_booleans(): void
    {
        self::assertNull(StrictBoolean::parse([]));
        self::assertNull(StrictBoolean::parse(['true']));
        self::assertNull(StrictBoolean::parse(new \stdClass));
        self::assertFalse(StrictBoolean::orClosed([]));
    }

    public function test_or_closed_never_opens_without_a_boolean_true(): void
    {
        // The whole contract in one line: nothing but a boolean true - or a
        // string that says exactly 'true' - can open anything.
        self::assertTrue(StrictBoolean::orClosed(true));
        self::assertTrue(StrictBoolean::orClosed('true'));
        self::assertTrue(StrictBoolean::orClosed('TRUE '), 'Case and padding are not ambiguity.');
        self::assertFalse(StrictBoolean::orClosed(false));
        self::assertFalse(StrictBoolean::orClosed(null));
        self::assertFalse(StrictBoolean::orClosed('yes'));
        self::assertFalse(StrictBoolean::orClosed(1));
    }

    // =====================================================================
    // §4 Defaults for switches whose safe direction is ON.
    // =====================================================================

    public function test_or_default_keeps_the_protection_when_the_value_is_absent_or_malformed(): void
    {
        // A readiness requirement, a replay guard or an idempotency switch must
        // survive a deleted line and a mistyped one. Falling back to false there
        // would turn a configuration typo into a disabled safety control.
        self::assertTrue(StrictBoolean::orDefault(null, true));
        self::assertTrue(StrictBoolean::orDefault('yes', true));
        self::assertTrue(StrictBoolean::orDefault('', true));
        self::assertTrue(StrictBoolean::orDefault(0, true));
        self::assertFalse(StrictBoolean::orDefault('false', true), 'An explicit false must still be honoured.');
        self::assertFalse(StrictBoolean::orDefault(false, true));
        self::assertTrue(StrictBoolean::orDefault('true', true));
    }

    public function test_or_default_with_a_false_default_behaves_like_or_closed(): void
    {
        self::assertFalse(StrictBoolean::orDefault(null, false));
        self::assertFalse(StrictBoolean::orDefault('yes', false));
        self::assertTrue(StrictBoolean::orDefault('true', false));
    }

    // =====================================================================
    // §5 Diagnostics classify without echoing.
    // =====================================================================

    public function test_describe_classifies_a_parsed_boolean(): void
    {
        self::assertSame('true', StrictBoolean::describe(true));
        self::assertSame('true', StrictBoolean::describe('true'));
        self::assertSame('false', StrictBoolean::describe(false));
        self::assertSame('false', StrictBoolean::describe('FALSE'));
    }

    public function test_describe_separates_absent_from_blank(): void
    {
        // Different fixes: a missing line has to be written, a blank one has to
        // be given a value. Both are closed, and an operator told only "closed"
        // cannot tell which mistake they made.
        self::assertSame('missing', StrictBoolean::describe(null));
        self::assertSame('empty', StrictBoolean::describe(''));
        self::assertSame('empty', StrictBoolean::describe('   '));
    }

    public function test_describe_names_the_type_of_a_malformed_value(): void
    {
        self::assertSame('malformed:string', StrictBoolean::describe('yes'));
        self::assertSame('malformed:integer', StrictBoolean::describe(1));
        self::assertSame('malformed:double', StrictBoolean::describe(1.5));
        self::assertSame('malformed:array', StrictBoolean::describe([]));
        self::assertSame('malformed:object', StrictBoolean::describe(new \stdClass));
    }

    public function test_describe_never_echoes_the_value_it_was_given(): void
    {
        // A switch that was meant to hold a boolean may have been pasted with a
        // secret in it, and `describe()` output is printed to terminals, CI
        // transcripts and JSON that operators forward to each other. The
        // classification must therefore never contain the value.
        $secret = 'sk_' . 'live_' . 'FIXTURE';

        foreach ([$secret, 'yes', '1', "false\ntrue", str_repeat('A', 500)] as $value) {
            $description = StrictBoolean::describe($value);

            self::assertStringNotContainsString($value, $description);
            self::assertStringNotContainsString('sk_live', $description);
            self::assertMatchesRegularExpression('/\A(?:true|false|missing|empty|malformed:[a-z]+)\z/', $description);
        }
    }
}
