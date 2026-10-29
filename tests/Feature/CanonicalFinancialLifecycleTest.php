<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AgentStatus;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Exceptions\DrawResultException;
use App\Models\AgentCommission;
use App\Models\DrawResult;
use App\Models\FinancialTransaction;
use App\Models\Payout;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultIngestionService;
use App\Services\Draw\RealPrizeSettlementService;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositService;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\Money;
use Tests\Feature\Agent\AgentTestCase;

/**
 * Synthetic manual-deposit fixture, not evidence of a live payment provider.
 * Exercises one canonical Bet through funding, commission, review and payout.
 */
final class CanonicalFinancialLifecycleTest extends AgentTestCase
{
    public function test_canonical_funding_purchase_commissions_review_and_payout_are_balanced_and_idempotent(): void
    {
        config([
            'finance.deposit.fee_percentage' => '0.00',
            'finance.prize_payout.safety_mode' => 'LIVE',
            'finance.prize_payout.auto_approve_below' => '1000000.00',
            'finance.prize_payout.require_approval_at_or_above' => '1000000.00',
        ]);
        $parent = $this->createAgent('0.0200', AgentStatus::Active);
        $agent = $this->createAgent('0.0500', AgentStatus::Active, $parent->id);
        $player = $this->createPlayer('0.00', $agent);

        $deposit = app(DepositService::class)->request(
            $player['wallet'], Money::of('1000.00', Currency::THB), PaymentMethod::BankTransfer, 'e2e-deposit-request',
        );
        $this->assertSame('0.00', (string) $player['wallet']->fresh()->balance, 'A request is not a credit.');
        app(DepositApprovalService::class)->approve($deposit);
        $funding = app(DepositCompletionService::class)->complete(
            $deposit->fresh(), options: ['provider_reference' => 'SYNTHETIC-MANUAL-RECEIPT-E2E'],
        );
        $this->assertTrue($funding['credited']);
        $this->assertTrue(app(DepositCompletionService::class)->complete($deposit->fresh())['replayed']);
        $this->assertSame('1000.00', (string) $player['wallet']->fresh()->balance);

        $draw = $this->createOpenDraw();
        $purchase = $this->purchaseBet($player['user'], $draw, '3d_direct', '123', '100.00', 'e2e-purchase');
        $replay = $this->purchaseBet($player['user'], $draw, '3d_direct', '123', '100.00', 'e2e-purchase');
        $this->assertFalse($purchase->isReplay());
        $this->assertTrue($replay->isReplay());
        $this->assertSame($purchase->betId(), $replay->betId());
        $this->assertSame('900.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame('5.00', (string) AgentCommission::query()->where('bet_id', $purchase->betId())->where('agent_id', $agent->id)->sole()->commission_amount);
        $this->assertSame('1.00', (string) AgentCommission::query()->where('bet_id', $purchase->betId())->where('agent_id', $parent->id)->sole()->commission_amount);

        $lifecycle = app(DrawLifecycleService::class);
        $draw = $lifecycle->markResultPending($lifecycle->close($draw));
        $card = ['first_prize' => '456123', 'bottom_two' => '45'];
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        app(DrawResultIngestionService::class)->ingest($draw->id, $card, 'internal', $maker->id);
        $this->assertSame(0, DrawResult::query()->where('draw_id', $draw->id)->count());
        try {
            app(DrawResultConfirmationService::class)->confirm($draw->id, $maker->id, $card);
            $this->fail('The maker cannot also confirm.');
        } catch (DrawResultException $exception) {
            $this->assertSame(DrawResultException::CODE_CONFIRMATION_FORBIDDEN, $exception->errorCode());
        }
        $this->assertSame(0, DrawResult::query()->where('draw_id', $draw->id)->count());
        $this->assertTrue(app(DrawResultConfirmationService::class)->confirm($draw->id, $checker->id, $card)['published']);

        $result = app(RealPrizeSettlementService::class)->settle($draw->id);
        $this->assertSame(1, $result->payoutsCreated);
        $this->assertSame('90000.00', $result->totalPrize);
        $this->assertSame('90900.00', (string) $player['wallet']->fresh()->balance);
        $this->assertTrue(app(RealPrizeSettlementService::class)->settle($draw->id)->alreadySettled);
        $this->assertSame(1, Payout::query()->where('draw_id', $draw->id)->count());
        $this->assertSame('90900.00', (string) $player['wallet']->fresh()->balance);

        $commission = $this->settlementService()->settleForDraw($draw->id);
        $this->assertSame(2, $commission->commissionsSettled);
        $this->assertSame('6.00', $commission->totalCommissionPaid);
        $this->assertSame('5.00', (string) Wallet::query()->where('user_id', $agent->user_id)->sole()->balance);
        $this->assertSame('1.00', (string) Wallet::query()->where('user_id', $parent->user_id)->sole()->balance);
        $this->assertSame(0, $this->settlementService()->settleForDraw($draw->id)->commissionsSettled);

        $validator = app(LedgerBalanceValidator::class);
        $transactions = FinancialTransaction::query()->get();
        $this->assertCount(5, $transactions, 'Funding, purchase, payout, and two commissions each post once.');
        foreach ($transactions as $transaction) {
            $validator->assertTransactionBalanced($transaction);
            $this->assertSame(2, $transaction->ledgerEntries()->count());
        }
    }
}
