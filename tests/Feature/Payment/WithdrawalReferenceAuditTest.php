<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\WithdrawalProviderReferenceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class WithdrawalReferenceAuditTest extends PaymentTestCase
{
    private function row()
    {
        $p = $this->createPlayer('1000.00', Currency::THB);
        $row = app(WithdrawalService::class)->request($p['wallet'], Money::of('400.00', Currency::THB), PaymentMethod::BankTransfer,
            options: ['payout_details' => ['account_number' => 'PRIVATE-SYNTHETIC-DESTINATION']]);
        app(WithdrawalApprovalService::class)->approve($row);
        $row->provider_reference = 'PRIVATE-SYNTHETIC-PROVIDER-REF';
        $row->save();

        return $row->fresh();
    }

    public function test_redacted_audit_detects_unindexed_history_and_never_writes(): void
    {
        $row = $this->row();
        $before = $row->getRawOriginal();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->assertSame(0, Artisan::call('payments:withdrawal-reference-audit', ['--withdrawal' => $row->reference_number]));
        $text = Artisan::output();
        $data = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($data['read_only']);
        $this->assertContains('historical_reference_not_indexed', $data['items'][0]['issues']);
        $this->assertFalse($data['items'][0]['automatic_resend_allowed']);
        foreach (['PRIVATE-SYNTHETIC', 'payout_details', 'identity_key', 'fingerprint', 'token'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertFalse(array_any($queries, fn ($sql) => preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $sql) === 1));
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $this->assertSame(0, DB::table('withdrawal_provider_references')->count());
    }

    public function test_historical_duplicates_are_reported_without_selecting_a_winner(): void
    {
        $a = $this->row();
        $b = $this->row();
        $this->assertSame(0, Artisan::call('payments:withdrawal-reference-audit', ['--withdrawal' => $a->uuid]));
        $item = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['items'][0];
        $this->assertContains('historical_ownership_conflict', $item['issues']);
        $this->assertSame(0, DB::table('withdrawal_provider_references')->count());
    }

    public function test_already_indexed_compatible_reference_has_no_false_missing_index_warning(): void
    {
        $row = $this->row();
        // The redacted projection must still load financial identity needed for
        // historical checks, without outputting it or the private destination.
        Payment::query()->create(['reference_number' => 'SYNTHETIC-AUDIT-PAYMENT',
            'user_id' => $row->user_id, 'payable_type' => $row::class, 'payable_id' => $row->id,
            'method' => PaymentMethod::BankTransfer, 'gateway' => 'bank_transfer', 'status' => PaymentStatus::Pending,
            'currency' => Currency::THB, 'amount' => '400.00', 'fee' => (string) $row->fee,
            'gateway_reference' => $row->provider_reference]);
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($row, 'bank_transfer', $row->provider_reference));
        $this->assertSame(0, Artisan::call('payments:withdrawal-reference-audit', ['--withdrawal' => (string) $row->id]));
        $item = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['items'][0];
        $this->assertTrue($item['indexed']);
        $this->assertSame([], $item['issues']);
    }

    public function test_attention_is_explicitly_bounded_and_oldest_first(): void
    {
        $row = $this->row();
        $this->assertSame(0, Artisan::call('payments:withdrawal-reference-audit', ['--attention' => true]));
        $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($data['bounded_listing']);
        $this->assertLessThanOrEqual(200, count($data['items']));
        $this->assertSame($row->id, $data['items'][0]['withdrawal_id']);
    }

    public function test_missing_or_conflicting_selectors_are_refused(): void
    {
        $this->assertSame(1, Artisan::call('payments:withdrawal-reference-audit'));
        $this->assertSame(1, Artisan::call('payments:withdrawal-reference-audit', ['--withdrawal' => 'MISSING']));
        $this->assertSame(1, Artisan::call('payments:withdrawal-reference-audit', ['--withdrawal' => '1', '--attention' => true]));
    }
}
