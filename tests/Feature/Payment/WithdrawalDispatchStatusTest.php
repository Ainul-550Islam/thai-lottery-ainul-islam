<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use Illuminate\Support\Facades\Artisan;

final class WithdrawalDispatchStatusTest extends PaymentTestCase
{
    private function fixture(): array
    {
        $player = $this->createPlayer('1000.00');
        $row = app(WithdrawalService::class)->request($player['wallet'], Money::of('400.00', Currency::THB), PaymentMethod::BankTransfer,
            options: ['payout_details' => ['account_number' => 'SYNTHETIC-PRIVATE-DESTINATION']]);
        app(WithdrawalApprovalService::class)->approve($row);
        app(WithdrawalApprovalService::class)->markProcessing($row);

        return [$row->fresh(), $player['wallet']->fresh()];
    }

    public function test_inspection_redacts_routing_and_claim_secrets_and_writes_no_money(): void
    {
        [$row, $wallet] = $this->fixture();
        $metadata = $row->metadata;
        $metadata['outbound_dispatch'] = ['state' => 'unknown', 'token' => 'SYNTHETIC-PRIVATE-TOKEN', 'fingerprint' => 'SYNTHETIC-PRIVATE-FINGERPRINT'];
        $row->update(['metadata' => $metadata]);
        $before = [$row->fresh()->getRawOriginal(), $wallet->fresh()->getRawOriginal()];
        $this->assertSame(0, Artisan::call('payments:withdrawal-dispatch-status', ['--withdrawal' => (string) $row->id]));
        $text = Artisan::output();
        $report = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['read_only']);
        $this->assertTrue($report['items'][0]['reconciliation_required']);
        $this->assertFalse($report['items'][0]['automatic_resend_allowed']);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE', $text);
        $this->assertSame($before, [$row->fresh()->getRawOriginal(), $wallet->fresh()->getRawOriginal()]);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_legacy_processing_is_unknown_not_an_unclaimed_send_permission(): void
    {
        [$row] = $this->fixture();
        $this->assertSame(0, Artisan::call('payments:withdrawal-dispatch-status', ['--withdrawal' => $row->reference_number]));
        $report = json_decode(Artisan::output(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('legacy_unknown', $report['items'][0]['dispatch_state']);
        $this->assertTrue($report['items'][0]['reconciliation_required']);
    }

    public function test_attention_listing_is_bounded_and_does_not_repair_or_reset_claims(): void
    {
        [$row, $wallet] = $this->fixture();
        $before = [$row->fresh()->getRawOriginal(), $wallet->fresh()->getRawOriginal()];
        $this->assertSame(0, Artisan::call('payments:withdrawal-dispatch-status', ['--attention' => true]));
        $report = json_decode(Artisan::output(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['bounded_listing']);
        $this->assertCount(1, $report['items']);
        $this->assertFalse($report['items'][0]['automatic_resend_allowed']);
        $this->assertSame($before, [$row->fresh()->getRawOriginal(), $wallet->fresh()->getRawOriginal()]);
    }

    public function test_missing_or_conflicting_selectors_are_controlled_refusals(): void
    {
        $this->assertSame(1, Artisan::call('payments:withdrawal-dispatch-status'));
        $this->assertSame(1, Artisan::call('payments:withdrawal-dispatch-status', ['--withdrawal' => '99999']));
        $this->assertSame(1, Artisan::call('payments:withdrawal-dispatch-status', ['--attention' => true, '--withdrawal' => '99999']));
    }
}
