<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Enums\GloClaimStatus;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\GloPrizeClaim;
use App\Models\GloTicket;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\WalletService;
use App\Services\Lottery\GloL6AuthoritativeTicketEngineService;
use PHPUnit\Framework\Attributes\DataProvider;

/** The retired alternate writer must not infer payment authority from stored status. */
final class LegacyGloSettlementBoundaryTest extends FinancialMasterTestCase
{
    public static function claimStates(): array
    {
        return [
            'approved without winner proof' => [GloClaimStatus::Approved],
            'pending' => [GloClaimStatus::Pending],
            'eligible' => [GloClaimStatus::Eligible],
            'paid legacy row' => [GloClaimStatus::Paid],
        ];
    }

    #[DataProvider('claimStates')]
    public function test_alternate_wallet_settlement_refuses_every_claim_state_without_mutation(GloClaimStatus $status): void
    {
        $player = User::factory()->create(['date_of_birth' => now()->subYears(35)->toDateString()]);
        $this->verifyUserKycForTest($player);
        $operator = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($player, Currency::THB);
        $draw = $this->openBulkDrawForTest([]);
        $engine = app(GloL6AuthoritativeTicketEngineService::class);
        $engine->seatL6DrawSeries($draw, ['units_sold' => 500000]);
        $ticket = GloTicket::create([
            'draw_id' => $draw->id, 'product' => 'l6', 'ticket_number' => '123456',
            'owner_user_id' => $player->id,
            'ticket_reference' => GloTicket::buildReference($draw->id, 'l6', '123456'),
        ]);
        // Deliberate corrupted/legacy fixture, NOT the legitimate claim workflow.
        $claim = GloPrizeClaim::create([
            'claim_reference' => 'LEGACY-BOUNDARY-'.$status->value,
            'ticket_id' => $ticket->id, 'draw_id' => $draw->id, 'product' => 'l6',
            'ticket_number' => '123456', 'prize_category' => 'back_two_digit',
            'gross_prize' => '1000.00', 'stamp_duty' => '5.00', 'net_prize' => '995.00',
            'claimant_user_id' => $player->id, 'claim_channel' => 'glo_office', 'status' => $status,
            'payment_status' => 'pending', 'fingerprint' => hash('sha256', $status->value),
            'submitted_at' => now(),
        ]);
        $beforeClaim = $claim->fresh()->getRawOriginal();
        $counts = [FinancialTransaction::count(), LedgerEntry::count(), AuditLog::count()];
        try {
            $engine->settleVerifiedClaim($claim, $operator);
            $this->fail('The alternate GLO wallet writer must remain disabled. Balance: '.(string) $wallet->fresh()->balance);
        } catch (FinancialException $error) {
            $this->assertSame('glo_l6_direct_wallet_settlement_disabled', $error->errorCode());
        }
        $this->assertSame('0.00', (string) $wallet->fresh()->balance);
        $this->assertSame('0.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame($beforeClaim, $claim->fresh()->getRawOriginal());
        $this->assertSame($counts, [FinancialTransaction::count(), LedgerEntry::count(), AuditLog::count()]);
    }

    public function test_unpersisted_claim_cannot_trigger_wallet_creation_or_accounting(): void
    {
        $operator = User::factory()->create();
        $before = [Wallet::count(), FinancialTransaction::count(), LedgerEntry::count(), AuditLog::count()];
        try {
            app(GloL6AuthoritativeTicketEngineService::class)->settleVerifiedClaim(new GloPrizeClaim, $operator);
            $this->fail('Unpersisted payload must not reach an alternate money writer.');
        } catch (FinancialException $error) {
            $this->assertSame('glo_l6_direct_wallet_settlement_disabled', $error->errorCode());
        }
        $this->assertSame($before, [Wallet::count(), FinancialTransaction::count(), LedgerEntry::count(), AuditLog::count()]);
    }
}
