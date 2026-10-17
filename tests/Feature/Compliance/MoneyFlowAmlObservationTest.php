<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\AmlRiskLevel;
use App\Enums\Currency;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Compliance\AmlRiskService;
use App\Services\Compliance\MoneyFlowAmlObservationService;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Part 61 — the AML orchestrator now has callers on the money side.
 *
 * WHAT IS BEING PROVEN
 *  1. In the default `log` mode the observation records and NEVER refuses, even
 *     for an account the existing service calls Critical. Wiring a detector up
 *     must not start turning real money away.
 *  2. In `enforce` mode only a Critical verdict refuses. High risk does not:
 *     escalating a high-risk account is a case decision, not an automatic one.
 *  3. Failure behaviour differs by mode, on purpose: in `log` mode an
 *     unavailable assessment is recorded and swallowed (a detector not trusted
 *     to block must not be able to stop a deposit); in `enforce` mode the same
 *     failure refuses.
 *  4. The withdrawal flow really calls it — asserted with a recording double
 *     bound into the container, not by reading the source.
 *
 * WHAT IS NOT CLAIMED
 * No threshold, weight or watchlist is invented here or in config/aml.php; the
 * verdict is AmlRiskService's own. Nothing opens a compliance case, and no flow
 * is enforced.
 */
final class MoneyFlowAmlObservationTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LedgerAccountSeeder::class);
        config(['aml.freshness_seconds' => 0]);
        // Suite convention (see Tests\TestCase): money paths run with the
        // real-money launch gate open; RealMoneyGateTest closes it on purpose.
        config(['real_money.enabled' => true]);
    }

    private function account(): User
    {
        return User::factory()->create([
            'status' => UserStatus::Active,
            'password' => Hash::make('AmlFlowPass123!'),
        ])->fresh();
    }

    /** Bind an AmlRiskService double that reports one level, or throws. */
    private function reportingAml(?AmlRiskLevel $level, bool $throws = false): void
    {
        $double = new class($level, $throws) extends AmlRiskService
        {
            public function __construct(private readonly ?AmlRiskLevel $level, private readonly bool $throws)
            {
                parent::__construct(app(\App\Services\Compliance\AmlRiskAssessmentService::class));
            }

            public function measure(int $userId): array
            {
                if ($this->throws) {
                    throw new RuntimeException('assessment backend unavailable');
                }

                return [
                    'evidence' => ['stub' => true],
                    'score' => $this->level === AmlRiskLevel::Critical ? 100 : 10,
                    'level' => $this->level ?? AmlRiskLevel::Low,
                    'reason_codes' => ['STUB_VERDICT'],
                ];
            }
        };

        $this->instance(AmlRiskService::class, $double);
    }

    /** @return list<AuditLog> */
    private function observations(): array
    {
        return AuditLog::query()
            ->where('metadata->operation', 'aml_money_flow_observation')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function test_log_mode_records_and_never_refuses_even_a_prohibited_account(): void
    {
        $this->reportingAml(AmlRiskLevel::Critical);
        $user = $this->account();

        $result = app(MoneyFlowAmlObservationService::class)
            ->observe($user, MoneyFlowAmlObservationService::FLOW_DEPOSIT, ['amount' => '5000.00']);

        self::assertSame('log', $result['mode']);
        self::assertTrue($result['observed']);
        self::assertFalse($result['refused']);
        self::assertSame(AmlRiskLevel::Critical->value, $result['level']);

        $rows = $this->observations();
        self::assertCount(1, $rows);
        self::assertSame('deposit', $rows[0]->metadata['flow']);
        self::assertSame('observed', $rows[0]->metadata['outcome']);
        self::assertSame(['STUB_VERDICT'], $rows[0]->metadata['reason_codes']);
    }

    public function test_enforce_mode_refuses_only_a_prohibited_account(): void
    {
        $this->reportingAml(AmlRiskLevel::Critical);
        config(['aml.mode' => 'enforce']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('prohibited');

        app(MoneyFlowAmlObservationService::class)
            ->observe($this->account(), MoneyFlowAmlObservationService::FLOW_WITHDRAWAL);
    }

    public function test_enforce_mode_does_not_refuse_a_high_risk_account(): void
    {
        $this->reportingAml(AmlRiskLevel::High);
        config(['aml.mode' => 'enforce']);

        $result = app(MoneyFlowAmlObservationService::class)
            ->observe($this->account(), MoneyFlowAmlObservationService::FLOW_WITHDRAWAL);

        self::assertSame(AmlRiskLevel::High->value, $result['level']);
        self::assertFalse($result['refused']);
        self::assertCount(1, $this->observations());
    }

    public function test_an_unavailable_assessment_never_blocks_in_log_mode(): void
    {
        $this->reportingAml(null, throws: true);
        $user = $this->account();

        $result = app(MoneyFlowAmlObservationService::class)
            ->observe($user, MoneyFlowAmlObservationService::FLOW_DEPOSIT);

        self::assertFalse($result['observed']);
        self::assertFalse($result['refused']);

        $rows = $this->observations();
        self::assertCount(1, $rows);
        self::assertStringStartsWith('assessment_unavailable:', (string) $rows[0]->metadata['outcome']);
    }

    public function test_an_unavailable_assessment_refuses_in_enforce_mode(): void
    {
        $this->reportingAml(null, throws: true);
        config(['aml.mode' => 'enforce']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unavailable');

        app(MoneyFlowAmlObservationService::class)
            ->observe($this->account(), MoneyFlowAmlObservationService::FLOW_BET);
    }

    public function test_a_flow_can_be_observed_while_the_global_default_is_off(): void
    {
        $this->reportingAml(AmlRiskLevel::Critical);
        config(['aml.mode' => 'off', 'aml.flows.withdrawal' => 'log']);

        $off = app(MoneyFlowAmlObservationService::class)
            ->observe($this->account(), MoneyFlowAmlObservationService::FLOW_DEPOSIT);
        $on = app(MoneyFlowAmlObservationService::class)
            ->observe($this->account(), MoneyFlowAmlObservationService::FLOW_WITHDRAWAL);

        self::assertFalse($off['observed']);
        self::assertTrue($on['observed']);
        self::assertCount(1, $this->observations());
    }

    public function test_the_withdrawal_flow_really_calls_the_observation(): void
    {
        // The real service, with a stubbed verdict: the point is the wiring, and
        // the proof is the audit row the real service writes — not a double that
        // would only prove the double was called.
        $this->reportingAml(AmlRiskLevel::Low);

        $user = $this->account();
        $wallet = Wallet::factory()->for($user)->withBalance('1000.00')->create(['currency' => Currency::THB]);

        $withdrawal = app(WithdrawalService::class)->request(
            wallet: $wallet,
            amount: Money::of('400.00', Currency::THB),
        );

        self::assertTrue($withdrawal->exists);

        $rows = $this->observations();
        $flows = array_map(static fn (AuditLog $row): string => (string) $row->metadata['flow'], $rows);
        self::assertContains('withdrawal', $flows, 'the withdrawal request is observed');

        $row = $rows[array_search('withdrawal', $flows, true)];
        self::assertSame((int) $user->getKey(), (int) $row->user_id);
        self::assertSame('400.00', (string) $row->metadata['context']['amount']);
        self::assertSame('THB', (string) $row->metadata['context']['currency']);
    }
}
