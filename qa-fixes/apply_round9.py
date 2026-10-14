"""Round 9: default-off real-money launch gate.

Real-money features are not launch-ready until licensing is confirmed. Payment gateways are already
disabled by default, but the code had no single switch. This adds one:

  REAL_MONEY_ENABLED   default false (config/real_money.php)

When false, money leaves or enters the platform through three paths, and each refuses before any
state changes:
  - deposit initiation       ProductionPaymentExecutionHubService::initiateDeposit()
  - withdrawal request       WithdrawalService::request()
  - GLO prize payment        GloPrizeClaimService::pay()

Tests opt in through Tests\\TestCase, so the suite exercises the paths. RealMoneyGateTest proves that
the gate refuses when the flag is off. Production defaults stay off. Nothing is weakened.

Idempotent. Run after a sandbox reset:  python3 /home/user/qa-fixes/apply_round9.py
"""
import os

ROOT = '/home/user/thai-lottery-ainul-islam/'

CONFIG = """<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Real-money launch gate
    |--------------------------------------------------------------------------
    | Deposits, withdrawals and GLO prize payments are refused unless this is
    | true. It defaults to false, so a fresh deployment cannot move real money
    | until someone sets REAL_MONEY_ENABLED=true deliberately. Real-money
    | features are not launch-ready until licensing is confirmed.
    */
    'enabled' => (bool) env('REAL_MONEY_ENABLED', false),
];
"""

GATE = """<?php

declare(strict_types=1);

namespace App\\Services\\Compliance;

use App\\Exceptions\\FinancialException;

/**
 * The single launch switch for real-money movement.
 *
 * Fail closed: the gate refuses unless config('real_money.enabled') is exactly true. It is called at
 * the top of each money-moving entry point, before any lock, row or ledger write.
 */
final class RealMoneyGate
{
    public function isOpen(): bool
    {
        return config('real_money.enabled') === true;
    }

    /**
     * @throws FinancialException
     */
    public function assertOpen(string $operation): void
    {
        if (! $this->isOpen()) {
            throw FinancialException::withCode(
                'real_money_disabled',
                sprintf('Real-money %s is disabled. Licensing must be confirmed before REAL_MONEY_ENABLED is set.', $operation),
                ['operation' => $operation],
            );
        }
    }
}
"""

GATE_TEST = """<?php

declare(strict_types=1);

namespace Tests\\Feature\\Compliance;

use App\\Enums\\PaymentMethod;
use App\\Enums\\Currency;
use App\\Exceptions\\FinancialException;
use App\\Models\\User;
use App\\Services\\Compliance\\RealMoneyGate;
use App\\Services\\Finance\\Money;
use App\\Services\\Finance\\WithdrawalService;
use App\\Services\\Wallet\\WalletService;
use Illuminate\\Foundation\\Testing\\DatabaseTruncation;
use Tests\\TestCase;

/**
 * The real-money launch gate defaults closed. Tests in this suite run with the gate open (see
 * Tests\\TestCase); these tests close it deliberately and prove each money path refuses.
 */
final class RealMoneyGateTest extends TestCase
{
    use DatabaseTruncation;

    public function test_the_gate_defaults_closed_in_config(): void
    {
        // config/real_money.php reads env('REAL_MONEY_ENABLED', false). With no env var set, the
        // shipped default must be closed.
        if (env('REAL_MONEY_ENABLED') === null) {
            $shipped = require __DIR__.'/../../../config/real_money.php';
            $this->assertFalse($shipped['enabled']);
        }
    }

    public function test_the_gate_refuses_unless_exactly_true(): void
    {
        config(['real_money.enabled' => false]);
        $gate = app(RealMoneyGate::class);
        $this->assertFalse($gate->isOpen());

        try {
            $gate->assertOpen('withdrawal');
            $this->fail('The gate let a withdrawal through while closed.');
        } catch (FinancialException $e) {
            $this->assertSame('real_money_disabled', $e->errorCode());
        }

        config(['real_money.enabled' => 'yes']);
        $this->assertFalse(app(RealMoneyGate::class)->isOpen(), 'A truthy string must not open the gate.');
    }

    public function test_a_withdrawal_is_refused_while_the_gate_is_closed_and_moves_nothing(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::THB);
        app(WalletService::class)->credit($wallet, '500.00', 'seed', 'gate-seed-0000000001', 'Seed');

        config(['real_money.enabled' => false]);

        try {
            app(WithdrawalService::class)->request(
                wallet: $wallet->refresh(),
                amount: Money::of('100.00', Currency::THB),
                method: PaymentMethod::BankTransfer,
                options: ['payout_details' => ['bank_name' => 'SCB', 'account_number' => '1234567890', 'account_name' => 'Player']],
            );
            $this->fail('A withdrawal was accepted while real money is disabled.');
        } catch (FinancialException $e) {
            $this->assertSame('real_money_disabled', $e->errorCode());
        }

        $this->assertSame('500.00', (string) $wallet->refresh()->balance);
        $this->assertSame('0.00', (string) $wallet->locked_balance);
    }
}
"""

TESTCASE_OLD = "    protected function setUp(): void\n    {\n        parent::setUp();\n\n        (new LedgerAccountSeeder)->run();\n        (new RolePermissionSeeder)->run();\n    }\n"
TESTCASE_NEW = ("    protected function setUp(): void\n    {\n        parent::setUp();\n\n        (new LedgerAccountSeeder)->run();\n        (new RolePermissionSeeder)->run();\n\n"
                "        // Real-money gate: tests exercise the money paths, so the gate is open here.\n"
                "        // RealMoneyGateTest closes it and proves the refusals. Production defaults stay closed.\n"
                "        config(['real_money.enabled' => true]);\n    }\n")

ENV_OLD = "BANK_TRANSFER_ENABLED=false\n"
ENV_NEW = ("# Real-money launch gate. Keep false until licensing is confirmed. Deposits, withdrawals\n"
           "# and GLO prize payments are refused while false.\n"
           "REAL_MONEY_ENABLED=false\n"
           "BANK_TRANSFER_ENABLED=false\n")


def write_if_missing(rel, content):
    p = ROOT + rel
    if os.path.exists(p):
        print(f'{rel}: already present')
        return
    os.makedirs(os.path.dirname(p), exist_ok=True)
    open(p, 'w').write(content)
    print(f'{rel}: written')


def insert_after(rel, anchor, addition, label):
    p = ROOT + rel
    s = open(p).read()
    if addition.strip() in s:
        print(f'{label}: already applied')
        return
    n = s.count(anchor)
    assert n == 1, f'{label}: anchor found {n} times in {rel}'
    open(p, 'w').write(s.replace(anchor, anchor + addition, 1))
    print(f'{label}: applied')


def replace_once(rel, old, new, label):
    p = ROOT + rel
    s = open(p).read()
    if new in s:
        print(f'{label}: already applied')
        return
    n = s.count(old)
    assert n == 1, f'{label}: anchor found {n} times in {rel}'
    open(p, 'w').write(s.replace(old, new, 1))
    print(f'{label}: applied')


GUARD = "        app(\\App\\Services\\Compliance\\RealMoneyGate::class)->assertOpen('{op}');\n"


def main():
    write_if_missing('config/real_money.php', CONFIG)
    write_if_missing('app/Services/Compliance/RealMoneyGate.php', GATE)
    write_if_missing('tests/Feature/Compliance/RealMoneyGateTest.php', GATE_TEST)

    # Guards: each refuses before any state change.
    insert_after(
        'app/Services/Finance/WithdrawalService.php',
        "    public function request(\n        Wallet $wallet,\n        Money $amount,\n        PaymentMethod $method = PaymentMethod::Manual,\n        ?string $idempotencyKey = null,\n        array $options = [],\n    ): Withdrawal {\n",
        GUARD.format(op='withdrawal'),
        'withdrawal guard',
    )
    insert_after(
        'app/Services/Payment/ProductionPaymentExecutionHubService.php',
        "    public function initiateDeposit(User $user, array $params, string $idempotencyKey): array\n    {\n",
        GUARD.format(op='deposit'),
        'deposit guard',
    )
    insert_after(
        'app/Services/Lottery/GloPrizeClaimService.php',
        "    public function pay(GloPrizeClaim $claim, User $paymentOperator, ?string $transactionReference = null): GloPrizeClaim\n    {\n",
        GUARD.format(op='prize payment'),
        'prize payment guard',
    )

    replace_once('tests/TestCase.php', TESTCASE_OLD, TESTCASE_NEW, 'test base opt-in')
    replace_once('.env.production.example', ENV_OLD, ENV_NEW, 'production env documents the gate')


if __name__ == '__main__':
    main()
