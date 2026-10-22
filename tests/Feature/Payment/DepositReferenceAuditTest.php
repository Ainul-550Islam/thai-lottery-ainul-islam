<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\Payment\FinancialProviderReferenceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class DepositReferenceAuditTest extends PaymentTestCase
{
    public function test_redacted_original_credit_audit_is_read_only_and_never_repairs_historical_proof(): void
    {
        $f = $this->createPlayer('100.00');
        $row = app(DepositService::class)->request($f['wallet'], Money::of('400.00', Currency::THB), PaymentMethod::BankTransfer);
        app(DepositApprovalService::class)->approve($row);
        app(DepositCompletionService::class)->complete($row, options: ['provider_reference' => 'SYNTHETIC-PRIVATE-REFERENCE']);
        $before = DB::table(FinancialProviderReferenceService::TABLE)->sole();
        $this->assertSame(0, Artisan::call('payments:deposit-reference-audit', ['--deposit' => (string) $row->id]));
        $out = Artisan::output();
        $this->assertSame('canonical_credit_proved', json_decode($out, true)['items'][0]['proof']);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-REFERENCE', $out);
        $this->assertStringNotContainsString($before->facts_hash, $out);
        $this->assertEquals($before, DB::table(FinancialProviderReferenceService::TABLE)->sole());
        DB::table(FinancialProviderReferenceService::TABLE)->delete();
        $this->assertSame(0, Artisan::call('payments:deposit-reference-audit', ['--deposit' => $row->uuid]));
        $this->assertSame('held_original_evidence', json_decode(Artisan::output(), true)['items'][0]['proof']);
        $this->assertDatabaseCount(FinancialProviderReferenceService::TABLE, 0);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertSame('500.00', $f['wallet']->fresh()->balance);
    }

    public function test_operator_must_choose_one_bounded_or_exact_selector(): void
    {
        $this->assertSame(1, Artisan::call('payments:deposit-reference-audit'));
        $this->assertSame(1, Artisan::call('payments:deposit-reference-audit', ['--deposit' => '1', '--attention' => true]));
        $this->assertSame(1, Artisan::call('payments:deposit-reference-audit', ['--deposit' => 'not-found']));
        $this->assertSame(0, Artisan::call('payments:deposit-reference-audit', ['--attention' => true]));
        $this->assertTrue(json_decode(Artisan::output(), true)['bounded_listing']);
        $this->assertDatabaseCount('financial_transactions', 0);
    }
}
