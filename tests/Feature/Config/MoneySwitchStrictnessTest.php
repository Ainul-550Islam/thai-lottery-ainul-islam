<?php

declare(strict_types=1);

namespace Tests\Feature\Config;

use App\Services\Compliance\RealMoneyGate;
use App\Services\Compliance\RealMoneyTechnicalReadinessService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The master switch and the gateway switches accept only a boolean.
 *
 * WHAT IS ACTUALLY UNDER TEST
 * ---------------------------
 * Two layers, and the distinction between them is the point:
 *
 *   ENVIRONMENT -> CONFIG. `config/real_money.php` and `config/payment.php` are
 *     re-evaluated here with one environment variable set, exactly as a deploy
 *     does. Laravel's own `env()` already converts the reserved spellings
 *     'true' and 'false' into real booleans before this code sees them; what
 *     `StrictBoolean` adds is the refusal of everything ELSE - 'yes', 'on', '1',
 *     a blank value - which `filter_var(..., FILTER_VALIDATE_BOOLEAN)` accepted
 *     as TRUE and which therefore opened a money gate nobody decided to open.
 *
 *   CONFIG -> GATE. A raw string placed into configuration at runtime - by a
 *     test, a service provider or a config override in a release script - must
 *     not open the gate either. `RealMoneyGate::isOpen()` compares against the
 *     literal `true`, so 'true' as a string stays closed. That is asserted here
 *     rather than assumed, because the whole design rests on it.
 *
 * Every case in both layers answers CLOSED unless the value was written as a
 * boolean. There is no case in which a malformed switch opens anything, and no
 * case in which a malformed switch is silently treated as an intentional false:
 * the readiness report names it, because a deployment that is closed for a
 * reason nobody can see gets "fixed" by somebody guessing.
 */
final class MoneySwitchStrictnessTest extends TestCase
{
    /**
     * Evaluate a config file with one environment variable set.
     *
     * The variable is written to all three places an env adapter can read
     * (putenv, $_ENV and $_SERVER) and restored afterwards, so a case that sets
     * a switch cannot leak into the next one.
     *
     * @return array<string, mixed>
     */
    private function configWithEnv(string $file, string $key, ?string $value): array
    {
        $previous = [
            'getenv' => getenv($key),
            'env' => $_ENV[$key] ?? null,
            'server' => $_SERVER[$key] ?? null,
        ];

        $this->setEnv($key, $value);

        try {
            /** @var array<string, mixed> $config */
            $config = require base_path('config/'.$file);

            return $config;
        } finally {
            foreach ($previous as $scope => $old) {
                $this->setEnvFrom($key, $scope, $old);
            }
        }
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function setEnvFrom(string $key, string $scope, mixed $old): void
    {
        if (! is_string($old)) {
            $this->setEnv($key, null);

            return;
        }

        $this->setEnv($key, $old);
    }

    // =====================================================================
    // §1 The master switch: environment -> configuration.
    //    Acceptance items 1, 2, 3 and 5.
    // =====================================================================

    public function test_a_missing_master_switch_remains_closed(): void
    {
        $config = $this->configWithEnv('real_money.php', 'REAL_MONEY_ENABLED', null);

        self::assertFalse($config['enabled'], 'An absent REAL_MONEY_ENABLED opened the master switch.');
        self::assertSame('missing', $config['enabled_source'], 'The report would not be able to tell an unset switch from a malformed one.');
    }

    public function test_an_explicit_boolean_false_remains_closed(): void
    {
        $config = $this->configWithEnv('real_money.php', 'REAL_MONEY_ENABLED', 'false');

        self::assertFalse($config['enabled']);
        self::assertSame('false', $config['enabled_source'], 'A deliberate false must not be reported as a defect.');
    }

    public function test_the_string_false_remains_closed_and_is_not_a_defect(): void
    {
        // 'false' arrives from the environment as a string and is the value most
        // at risk from a `(bool)` cast, which reads it as TRUE. It must close the
        // gate, and it must be classified as a valid false rather than as
        // malformed: an operator who wrote the correct value should not be told
        // they wrote a broken one.
        $config = $this->configWithEnv('real_money.php', 'REAL_MONEY_ENABLED', 'false');

        self::assertFalse($config['enabled']);
        self::assertSame('false', $config['enabled_source']);

        // And the same spelling must not be reported as a strictness defect.
        $this->applyConfig($config);

        self::assertFalse($this->strictnessDefects()['real_money.enabled_source'] ?? false);
    }

    public function test_truthy_spellings_never_open_the_master_switch(): void
    {
        // Every value here is TRUE under FILTER_VALIDATE_BOOLEAN, which is what
        // config/real_money.php used. Each one is now closed, and each one is
        // named as malformed so the closure is explainable.
        foreach (['yes', 'on', '1', 'enabled', 'TRUEISH'] as $value) {
            $config = $this->configWithEnv('real_money.php', 'REAL_MONEY_ENABLED', $value);

            self::assertFalse($config['enabled'], 'REAL_MONEY_ENABLED='.$value.' opened the master switch.');
            self::assertSame('malformed:string', $config['enabled_source'], 'REAL_MONEY_ENABLED='.$value.' was not reported as malformed.');
        }
    }

    public function test_a_blank_master_switch_remains_closed_and_is_reported_as_blank(): void
    {
        // The classic templated-manifest artefact: the key is present, the value
        // is empty. Closed, and distinguished from 'missing' because the fix is
        // different - write a value rather than add a line.
        $config = $this->configWithEnv('real_money.php', 'REAL_MONEY_ENABLED', '');

        self::assertFalse($config['enabled']);
        self::assertSame('empty', $config['enabled_source']);
    }

    public function test_only_an_explicit_true_opens_the_master_switch(): void
    {
        $config = $this->configWithEnv('real_money.php', 'REAL_MONEY_ENABLED', 'true');

        self::assertTrue($config['enabled'], 'A deliberately written true was refused.');
        self::assertSame('true', $config['enabled_source']);

        foreach (['TRUE', ' true '] as $value) {
            $tolerated = $this->configWithEnv('real_money.php', 'REAL_MONEY_ENABLED', $value);

            self::assertTrue($tolerated['enabled'], 'REAL_MONEY_ENABLED='.json_encode($value).' was refused.');
        }
    }

    // =====================================================================
    // §2 The master switch: configuration -> gate. Acceptance item 4.
    // =====================================================================

    public function test_a_raw_string_true_in_configuration_does_not_open_the_gate(): void
    {
        // This is the layer the environment cannot reach: something wrote a
        // string into configuration at runtime. `isOpen()` compares against the
        // literal true, so the gate stays closed - and the strictness check says
        // why, because a closed gate with no explanation gets "fixed" by guesswork.
        foreach (['true', 'TRUE', '1', 'yes', 'on'] as $value) {
            config(['real_money.enabled' => $value]);

            self::assertFalse(app(RealMoneyGate::class)->isOpen(), 'config(real_money.enabled)='.json_encode($value).' opened the gate.');

            $threw = null;

            try {
                app(RealMoneyGate::class)->assertOpen('deposit');
            } catch (\Throwable $exception) {
                $threw = $exception;
            }

            self::assertNotNull($threw, 'assertOpen() allowed a deposit on a string switch.');
            self::assertInstanceOf(\App\Exceptions\FinancialException::class, $threw);
            self::assertSame('real_money_disabled', $threw->errorCode());
        }
    }

    public function test_a_malformed_switch_is_reported_by_the_readiness_audit(): void
    {
        config(['real_money.enabled' => 'yes']);

        $checks = app(RealMoneyTechnicalReadinessService::class)->checks();
        $strictness = $checks[RealMoneyTechnicalReadinessService::CHECK_CONFIGURATION_STRICTNESS];

        self::assertSame('FAIL', $strictness->status, 'A switch holding a string was not reported as a configuration defect.');
        self::assertStringContainsString('real_money.enabled', $strictness->message);
        self::assertStringContainsString('malformed:string', $strictness->message);

        // Named as a defect, and still closed: the report explains the refusal
        // instead of leaving an operator to reverse-engineer it.
        self::assertFalse(app(RealMoneyGate::class)->isOpen());
    }

    public function test_a_strict_boolean_switch_is_not_reported_as_a_defect(): void
    {
        config(['real_money.enabled' => true]);

        $checks = app(RealMoneyTechnicalReadinessService::class)->checks();
        $strictness = $checks[RealMoneyTechnicalReadinessService::CHECK_CONFIGURATION_STRICTNESS];

        self::assertNotSame('FAIL', $strictness->status, 'A correctly typed switch was reported as a defect: '.$strictness->message);
    }

    // =====================================================================
    // §3 The gateway switches.
    // =====================================================================

    public function test_a_truthy_spelling_never_enables_a_payment_gateway(): void
    {
        // `(bool) env('BKASH_ENABLED', false)` read 'yes' as TRUE. A gateway that
        // switches on because of a typo is a lane that can be selected by the
        // router, quoted to a player and dispatched to - so the parsing has to be
        // as strict as the master switch it sits behind.
        foreach ([['BKASH_ENABLED', 'bkash'], ['NAGAD_ENABLED', 'nagad'], ['STRIPE_ENABLED', 'stripe'], ['PROMPTPAY_ENABLED', 'promptpay'], ['BANK_TRANSFER_ENABLED', 'bank_transfer'], ['THAI_PAYOUT_ENABLED', 'thai_bank']] as [$variable, $gateway]) {
            foreach (['yes', 'on', '1'] as $value) {
                $config = $this->configWithEnv('payment.php', $variable, $value);

                self::assertFalse(
                    $config['gateways'][$gateway]['enabled'],
                    $variable.'='.$value.' enabled the '.$gateway.' gateway.',
                );
            }
        }
    }

    public function test_an_explicit_boolean_still_controls_a_gateway(): void
    {
        $opened = $this->configWithEnv('payment.php', 'BKASH_ENABLED', 'true');
        $closed = $this->configWithEnv('payment.php', 'BKASH_ENABLED', 'false');

        self::assertTrue($opened['gateways']['bkash']['enabled'], 'A deliberate true was refused.');
        self::assertFalse($closed['gateways']['bkash']['enabled']);
    }

    public function test_the_sandbox_flags_default_to_the_safe_side(): void
    {
        // A sandbox flag whose safe direction is ON must survive a deleted line
        // and a malformed one: falling back to false there would silently put a
        // lane into live mode because of a configuration typo.
        foreach ([null, 'yes', '', 'nonsense'] as $value) {
            $config = $this->configWithEnv('payment.php', 'BKASH_SANDBOX', $value);

            self::assertTrue(
                $config['gateways']['bkash']['sandbox'],
                'BKASH_SANDBOX='.json_encode($value).' took the lane out of sandbox.',
            );
        }

        $explicit = $this->configWithEnv('payment.php', 'BKASH_SANDBOX', 'false');

        self::assertFalse($explicit['gateways']['bkash']['sandbox'], 'An explicit false must still be honoured.');
    }

    public function test_the_deposit_auto_confirm_switch_cannot_be_opened_by_a_typo(): void
    {
        // Auto-confirming a deposit is the single most dangerous boolean in the
        // payment configuration: it credits a player without a provider having
        // settled anything. 'yes' used to enable it.
        foreach (['yes', 'on', '1', null, ''] as $value) {
            $config = $this->configWithEnv('payment.php', 'FINANCE_DEPOSIT_AUTO_CONFIRM', $value);

            self::assertFalse(
                $config['deposit']['auto_confirm'],
                'FINANCE_DEPOSIT_AUTO_CONFIRM='.json_encode($value).' enabled automatic deposit confirmation.',
            );
        }

        $deliberate = $this->configWithEnv('payment.php', 'FINANCE_DEPOSIT_AUTO_CONFIRM', 'true');

        self::assertTrue($deliberate['deposit']['auto_confirm']);
    }

    // =====================================================================
    // §4 The GLO L6 ticket switch, which was previously undefined.
    // =====================================================================

    public function test_the_glo_l6_purchase_switch_is_declared_and_defaults_closed(): void
    {
        // `GloL6PurchaseCapabilityService` read this key with an inline fallback,
        // but config/glo.php never declared it: ticket selling was closed by
        // accident rather than by decision, and no operator could see the switch.
        $config = $this->configWithEnv('glo.php', 'GLO_L6_PURCHASE_ENABLED', null);

        self::assertArrayHasKey('purchase', $config['l6'], 'The purchase block is still undeclared.');
        self::assertArrayHasKey('enabled', $config['l6']['purchase']);
        self::assertFalse($config['l6']['purchase']['enabled']);

        foreach (['yes', 'on', '1'] as $value) {
            $typed = $this->configWithEnv('glo.php', 'GLO_L6_PURCHASE_ENABLED', $value);

            self::assertFalse($typed['l6']['purchase']['enabled'], 'GLO_L6_PURCHASE_ENABLED='.$value.' enabled ticket selling.');
        }

        $deliberate = $this->configWithEnv('glo.php', 'GLO_L6_PURCHASE_ENABLED', 'true');

        self::assertTrue($deliberate['l6']['purchase']['enabled'], 'A deliberately written true was refused.');
    }

    public function test_the_declared_purchase_defaults_match_the_values_the_service_already_used(): void
    {
        // Declaring a key that code was already reading with an inline default
        // must not change behaviour. These are the exact fallbacks
        // GloL6PurchaseCapabilityService used before the block existed.
        $purchase = $this->configWithEnv('glo.php', 'GLO_L6_PURCHASE_ENABLED', null)['l6']['purchase'];

        self::assertTrue($purchase['require_official_result_source']);
        self::assertSame('not_configured', $purchase['inventory_provider_mode']);
        self::assertSame('', $purchase['custodian_provider_ref']);
        self::assertTrue($purchase['require_seated_inventory']);
        self::assertTrue($purchase['require_legal_publications']);
        self::assertSame('glo-l6-2026.1', $purchase['pricing_rule_version']);
        self::assertSame(300, $purchase['reservation_ttl_seconds']);
    }

    // =====================================================================
    // §5 A malformed switch never moves money, and never writes.
    // =====================================================================

    public function test_a_malformed_switch_refuses_admission_without_touching_the_estate(): void
    {
        $tables = ['ledger_entries', 'financial_transactions', 'deposits', 'withdrawals', 'payments', 'payment_intents'];

        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        config(['real_money.enabled' => 'yes']);

        $decision = app(\App\Services\Compliance\MoneyOperationControlService::class)
            ->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00', 'THB');

        self::assertFalse($decision['allowed']);
        self::assertSame('real_money_disabled', $decision['reason']);

        $after = [];
        foreach ($tables as $table) {
            $after[$table] = DB::table($table)->count();
        }

        self::assertSame($before, $after, 'A refused operation changed the money estate.');
    }

    /**
     * Apply an evaluated config array to the running application.
     *
     * @param  array<string, mixed>  $config
     */
    private function applyConfig(array $config): void
    {
        config(['real_money' => $config]);
    }

    /**
     * Does the strictness audit consider the master switch malformed?
     *
     * @return array<string, bool>
     */
    private function strictnessDefects(): array
    {
        $check = app(RealMoneyTechnicalReadinessService::class)
            ->checks()[RealMoneyTechnicalReadinessService::CHECK_CONFIGURATION_STRICTNESS];

        return [
            'real_money.enabled_source' => $check->isFailure()
                && str_contains($check->message, 'REAL_MONEY_ENABLED was written as'),
        ];
    }
}
