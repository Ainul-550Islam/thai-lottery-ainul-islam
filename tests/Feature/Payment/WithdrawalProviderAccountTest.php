<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\WithdrawalProviderAccountService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class WithdrawalProviderAccountTest extends PaymentTestCase
{
    private function row(PaymentMethod $method = PaymentMethod::BankTransfer)
    {
        $p = $this->createPlayer('1000.00', Currency::THB);
        $row = app(WithdrawalService::class)->request($p['wallet'], Money::of('400.00', Currency::THB), $method);
        app(WithdrawalApprovalService::class)->approve($row);

        return $row->fresh();
    }

    public function test_different_explicit_accounts_can_use_same_reference_without_sharing_an_owner(): void
    {
        config(['payment.withdrawal_reference_accounts.bank_transfer' => 'MERCHANT-A']);
        $a = $this->row();
        app(WithdrawalCompletionService::class)->complete($a, options: ['provider' => 'bank_transfer', 'provider_reference' => 'SYNTHETIC-REFERENCE']);
        config(['payment.withdrawal_reference_accounts.bank_transfer' => 'MERCHANT-B']);
        $b = $this->row();
        app(WithdrawalCompletionService::class)->complete($b, options: ['provider' => 'bank_transfer', 'provider_reference' => 'SYNTHETIC-REFERENCE']);
        $this->assertSame(2, FinancialTransaction::query()->count());
        $this->assertSame(['MERCHANT-A', 'MERCHANT-B'], DB::table('withdrawal_provider_references')->orderBy('id')->pluck('account_scope')->all());
        $this->assertSame('MERCHANT-A', $a->fresh()->metadata['outbound_provider_account']);
        $this->assertSame('MERCHANT-B', $b->fresh()->metadata['outbound_provider_account']);
    }

    public function test_manual_alias_uses_the_same_operator_bank_namespace(): void
    {
        config(['payment.withdrawal_reference_accounts.bank_transfer' => 'BANK-PRIMARY']);
        $row = $this->row(PaymentMethod::Manual);
        $this->assertSame('BANK-PRIMARY', app(WithdrawalProviderAccountService::class)->assertActive($row));
        $this->assertSame('bank_transfer', app(WithdrawalProviderAccountService::class)->canonical('manual'));
    }

    #[DataProvider('invalidScopes')]
    public function test_invalid_configured_namespace_is_not_defaulted_or_truncated(mixed $scope): void
    {
        config(['payment.withdrawal_reference_accounts.bank_transfer' => $scope]);
        $this->expectException(FinancialException::class);
        $this->row();
    }

    public static function invalidScopes(): array
    {
        return [[null], [''], [' account'], ['https://untrusted.invalid'], [str_repeat('a', 81)], [12], [[]]];
    }

    public function test_unscoped_legacy_record_is_not_reinterpreted_after_merchant_switch(): void
    {
        $row = $this->row();
        $metadata = $row->metadata;
        unset($metadata['outbound_provider_account']);
        $row->metadata = $metadata;
        $row->save();
        config(['payment.withdrawal_reference_accounts.bank_transfer' => 'NEW-MERCHANT']);
        $this->assertSame('legacy-primary', app(WithdrawalProviderAccountService::class)->stored($row));
        $this->expectException(FinancialException::class);
        app(WithdrawalProviderAccountService::class)->assertActive($row);
    }

    public function test_legacy_claim_cannot_be_relabelled_into_a_new_account(): void
    {
        $row = $this->row();
        $metadata = $row->metadata;
        $metadata['outbound_dispatch'] = ['version' => 1, 'state' => 'legacy_unknown'];
        $metadata['outbound_provider_account'] = 'NEW-MERCHANT';
        $row->metadata = $metadata;
        $this->expectException(FinancialException::class);
        app(WithdrawalProviderAccountService::class)->stored($row);
    }

    public function test_missing_configuration_keeps_explicit_legacy_compatibility_not_live_authorization(): void
    {
        config(['payment.withdrawal_reference_accounts' => []]);
        $this->assertSame('legacy-primary', app(WithdrawalProviderAccountService::class)->assertActive($this->row()));
    }
}
