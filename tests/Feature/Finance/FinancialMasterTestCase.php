<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\DTOs\Betting\BulkBetSelectionData;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\PaymentMethod;
use App\Models\Draw;
use App\Models\GloPrizeClaim;
use App\Models\GloTicket;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultIngestionService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalService;
use App\Services\Lottery\GloPrizeClaimService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Payment\PaymentTestCase;

/** Shared disposable-database fixtures; never a production money API. */
abstract class FinancialMasterTestCase extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function creditFixture(Wallet $wallet, string $amount, string $description): void
    {
        app(WalletService::class)->credit($wallet, Money::of($amount, $wallet->currency), idempotencyKey: 'fixture-credit:'.$wallet->id.':'.hash('sha256', $description), options: ['description' => $description]);
    }

    protected function debitFixture(Wallet $wallet, string $amount, string $description): void
    {
        app(WalletService::class)->debit($wallet, Money::of($amount, $wallet->currency), idempotencyKey: 'fixture-debit:'.$wallet->id.':'.hash('sha256', $description), options: ['description' => $description]);
    }

    /** @param list<BulkBetSelectionData> $selections */
    protected function openBulkDrawForTest(array $selections): Draw
    {
        $draw = Draw::factory()->create(['type' => DrawType::ThreeD]);
        $draw->status = DrawStatus::Open;
        $draw->betting_open_at = now()->subHour();
        $draw->betting_close_at = now()->addHour();
        $draw->opened_at = now()->subHour();
        $draw->save();
        foreach ($selections as $selection) {
            $this->provisionBetLimitForTest($draw, $selection->marketKey, $selection->number);
        }

        return $draw;
    }

    protected function pendingWithdrawalForMaster(Wallet $wallet, string $amount): Withdrawal
    {
        $this->verifyUserKycForTest($wallet->user);

        return app(WithdrawalService::class)->request(
            $wallet, Money::of($amount, $wallet->currency), PaymentMethod::BankTransfer,
            'master-withdrawal:'.$wallet->id.':'.$amount,
            ['payout_details' => ['bank_name' => 'KBANK', 'account_number' => '1234567890', 'account_name' => 'Test claimant']],
        );
    }

    /**
     * Internal maker/checker fixture, not evidence of an official upstream result.
     *
     * @return array{claim: GloPrizeClaim, claimant: User, approver: User, draw: Draw, ticket: GloTicket}
     */
    protected function verifiedClaimFixtureForMaster(): array
    {
        $claimant = User::factory()->create(['date_of_birth' => now()->subYears(35)->toDateString()]);
        $this->verifyUserKycForTest($claimant);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $this->seed(RolePermissionSeeder::class);
        $approver = User::factory()->create();
        $approver->syncRoles(['admin']);
        $draw = $this->openBulkDrawForTest([]);
        $lifecycle = app(DrawLifecycleService::class);
        $draw = $lifecycle->close($draw);
        // The sales fixture opens a future draw. Its result fixture must model
        // a held draw before confirmation/public source disclosure, not pretend
        // a future announcement was already eligible for public reading.
        $draw->scheduled_at = now()->subMinute();
        $draw->save();
        $draw = $lifecycle->markResultPending($draw);
        $card = ['first_prize' => '123456', 'bottom_two' => '45'];
        app(DrawResultIngestionService::class)->ingest($draw->id, $card, 'internal', $maker->id);
        app(DrawResultConfirmationService::class)->confirm($draw->id, $checker->id, $card);
        $ticket = GloTicket::create([
            'draw_id' => $draw->id, 'product' => 'l6', 'ticket_number' => '123456',
            'owner_user_id' => $claimant->id,
            'ticket_reference' => GloTicket::buildReference($draw->id, 'l6', '123456'),
            'metadata' => [],
        ]);
        $claim = app(GloPrizeClaimService::class)->submit([
            'ticket_id' => $ticket->id, 'prize_category' => 'first', 'claim_channel' => 'glo_office',
            'original_ticket_evidenced' => true, 'identity_document_evidenced' => true,
        ], $claimant);

        return compact('claim', 'claimant', 'approver', 'draw', 'ticket');
    }
}
