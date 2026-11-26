<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Compliance;

use App\Exceptions\FinancialException;
use App\Services\Compliance\RealMoneyGate;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The master fail-closed switch, tested as a pure unit.
 *
 * WHY THIS SUITE DOES NOT BOOT LARAVEL
 * ------------------------------------
 * `RealMoneyGate` takes an `Illuminate\Config\Repository` and nothing else, and
 * its refusal message is a plain string rather than a translation key. It can
 * therefore be constructed directly, which is what makes these tests able to
 * assert the one property that matters most and that a booted application cannot
 * show: **what the gate does when the configuration is wrong, absent or of the
 * wrong type.** A `Tests\TestCase` suite always resolves a real config repository
 * over a real `.env`, so it can only ever test the values this deployment happens
 * to hold. Here the value is the variable.
 *
 * The application-level consequences of the same switch — what a closed gate does
 * to a withdrawal, a deposit and a bet — are covered by
 * `Tests\Feature\RealMoneyGateFeatureTest` and the pre-existing
 * `Tests\Feature\Compliance\RealMoneyGateTest`. This suite owns the truth table.
 *
 * THE PROPERTY UNDER TEST
 * -----------------------
 * `isOpen()` compares with `===` against the literal `true`. That is not
 * pedantry: `filter_var(env('REAL_MONEY_ENABLED', false), FILTER_VALIDATE_BOOLEAN)`
 * in config/real_money.php already normalises the common cases, so the values
 * that reach the gate are usually correct — which means the ones that do not are
 * the product of a runtime `config()` write, a `config:cache` artifact built
 * against a different env, or a hand-edited cached file. Every one of those is a
 * configuration mistake, and a configuration mistake must fail CLOSED.
 */
final class RealMoneyGateTest extends TestCase
{
    /**
     * A gate over a configuration holding exactly one value.
     */
    private function gate(mixed $enabled): RealMoneyGate
    {
        return new RealMoneyGate(new Repository(['real_money' => ['enabled' => $enabled]]));
    }

    public function test_the_gate_is_closed_when_the_configuration_key_is_absent(): void
    {
        // A fresh deployment, a config file that was never published, or a
        // `config:cache` artifact built before the key existed. All three must
        // answer "closed" rather than erroring or defaulting open.
        $gate = new RealMoneyGate(new Repository([]));

        self::assertFalse($gate->isOpen());
        self::expectException(FinancialException::class);
        $gate->assertOpen('deposit');
    }

    public function test_the_gate_is_closed_when_the_real_money_section_is_absent(): void
    {
        $gate = new RealMoneyGate(new Repository(['app' => ['env' => 'production']]));

        self::assertFalse($gate->isOpen());
    }

    public function test_the_gate_opens_only_for_the_literal_boolean_true(): void
    {
        self::assertTrue($this->gate(true)->isOpen());
        self::assertFalse($this->gate(false)->isOpen());
    }

    /**
     * Every truthy value that is not `true` must leave the gate closed.
     *
     * These are the real shapes a mis-configuration arrives in: `'1'` and `'yes'`
     * from an env var read as a string, `1` from an integer cast, `'true'` from a
     * JSON payload, and an array from a config key that was meant to hold a
     * boolean but was given a section.
     */
    public static function truthyButNotTrue(): array
    {
        return [
            'string one' => ['1'],
            'string yes' => ['yes'],
            'string true' => ['true'],
            'string on' => ['on'],
            'integer one' => [1],
            'float one' => [1.0],
            'non-empty string' => ['enabled'],
            'non-empty array' => [['enabled' => true]],
            'object-ish array' => [[true]],
            'null' => [null],
            'string zero' => ['0'],
            'integer zero' => [0],
            'empty string' => [''],
        ];
    }

    #[DataProvider('truthyButNotTrue')]
    public function test_a_value_that_is_not_exactly_true_does_not_open_the_gate(mixed $enabled): void
    {
        self::assertFalse(
            $this->gate($enabled)->isOpen(),
            sprintf('%s must not open a money gate.', get_debug_type($enabled)),
        );
    }

    #[DataProvider('truthyButNotTrue')]
    public function test_a_value_that_is_not_exactly_true_is_refused_by_name(mixed $enabled): void
    {
        $gate = $this->gate($enabled);

        try {
            $gate->assertOpen('withdrawal');
            self::fail(sprintf('%s was treated as an open gate.', get_debug_type($enabled)));
        } catch (FinancialException $e) {
            self::assertSame('real_money_disabled', $e->errorCode());
            self::assertSame('withdrawal', $e->contextValue('operation'));
        }
    }

    public function test_a_closed_gate_names_the_operation_it_refused(): void
    {
        try {
            $this->gate(false)->assertOpen('bet');
            self::fail('A closed gate let a bet through.');
        } catch (FinancialException $e) {
            self::assertSame('real_money_disabled', $e->errorCode());
            self::assertSame('bet', $e->contextValue('operation'));
        }
    }

    public function test_the_operation_name_defaults_so_a_caller_cannot_forget_it(): void
    {
        try {
            $this->gate(false)->assertOpen();
            self::fail('A closed gate let an unnamed operation through.');
        } catch (FinancialException $e) {
            self::assertSame('real_money_disabled', $e->errorCode());
            self::assertSame('real_money', $e->contextValue('operation'));
        }
    }

    public function test_an_open_gate_does_not_throw(): void
    {
        $gate = $this->gate(true);

        $gate->assertOpen('deposit');
        $gate->assertOpen('withdrawal');
        $gate->assertOpen('bet');
        $gate->assertOpen('payout');

        // Reaching here at all is the assertion: assertOpen() is void, so the
        // only way it can fail this test is by throwing.
        self::assertTrue($gate->isOpen());
    }

    /**
     * The refusal context is a diagnostic surface that ends up in logs and API
     * error bodies. It must carry the operation name and nothing else — never the
     * value that was found, because "real_money.enabled held 'sk_live_…'" is how a
     * secret gets written into a log line by a class whose job is to protect money.
     */
    public function test_the_refusal_context_carries_only_the_operation_name(): void
    {
        try {
            $this->gate('a-value-that-must-not-be-echoed')->assertOpen('deposit');
            self::fail('A non-boolean value was treated as an open gate.');
        } catch (FinancialException $e) {
            self::assertSame(['operation'], array_keys($e->context()));
            self::assertSame('deposit', $e->contextValue('operation'));
            self::assertStringNotContainsString('a-value-that-must-not-be-echoed', $e->getMessage());
            self::assertStringNotContainsString('a-value-that-must-not-be-echoed', json_encode($e->toLogContext(), JSON_THROW_ON_ERROR));
        }
    }

    /**
     * `isOpen()` must be idempotent and side-effect free: it is called from
     * `GloL6PurchaseCapabilityService`, `PublicPaymentMethodsService`,
     * `PlayerWebController` and both deposit/withdrawal method pages, sometimes
     * several times per request. A gate that changed state on read would make the
     * answer depend on how many pages asked.
     */
    public function test_repeated_reads_do_not_change_the_answer(): void
    {
        $open = $this->gate(true);
        $closed = $this->gate(false);

        self::assertTrue($open->isOpen());
        self::assertTrue($open->isOpen());
        self::assertTrue($open->isOpen());

        self::assertFalse($closed->isOpen());
        self::assertFalse($closed->isOpen());
        self::assertFalse($closed->isOpen());
    }

    /**
     * The gate reads through the repository on every call rather than capturing
     * the value at construction, so a runtime `config()` write is honoured. That
     * is what lets the money paths be tested with the gate closed and open in the
     * same process, and it is what makes a `config:cache`-free deployment able to
     * flip the switch without a restart of the code path.
     */
    public function test_the_gate_reads_the_repository_on_every_call(): void
    {
        $repository = new Repository(['real_money' => ['enabled' => false]]);
        $gate = new RealMoneyGate($repository);

        self::assertFalse($gate->isOpen());

        $repository->set('real_money.enabled', true);
        self::assertTrue($gate->isOpen(), 'The gate captured the value at construction instead of reading it.');

        $repository->set('real_money.enabled', 'yes');
        self::assertFalse($gate->isOpen(), 'A truthy string written at runtime must not open the gate.');
    }
}
