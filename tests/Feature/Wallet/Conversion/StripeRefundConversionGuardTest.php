<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\DTOs\Wallet\ConversionAuditContextData;
use App\Enums\Currency;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletType;
use App\Exceptions\Wallet\ConversionException;
use App\Models\FinancialTransaction;
use App\Models\Wallet;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Payment\StripeRefundRecoveryService;
use App\Services\Wallet\Conversion\StripeRefundConversionGuard;
use App\Services\Wallet\Conversion\WalletConversionService;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\StripeRefundConversionFixture;

final class StripeRefundConversionGuardTest extends FinancialMasterTestCase
{
    use StripeRefundConversionFixture;

    private function refused(callable $operation, string $code = 'conversion_refund_exposure_pending'): void
    {
        $before = $this->conversionPaper();
        $calls = Http::recorded()->count();
        try {
            $operation();
            $this->fail('Pending or unproved original recovery cannot authorize a new conversion.');
        } catch (ConversionException $error) {
            $this->assertSame($code, $error->errorCode());
            $this->assertSame($code === 'idempotency_conflict' ? 409 : 422, $error->httpStatus());
            $this->assertStringNotContainsString('SYNTHETIC', $error->getMessage());
        }
        $this->assertSame($before, $this->conversionPaper());
        Http::assertSentCount($calls);
    }

    public static function directions(): array
    {
        return [[WalletConversionDirection::CashToWin], [WalletConversionDirection::WinToCash]];
    }

    #[DataProvider('directions')]
    public function test_both_new_directions_refuse_without_consuming_quote_or_moving_money(WalletConversionDirection $direction): void
    {
        $f = $this->conversionRefundFixture();
        $this->publishConversionRefund($f);
        $request = $this->quotedConversionRequest($f, direction: $direction);
        $this->refused(fn () => app(WalletConversionService::class)->convert($request));
        $this->assertDatabaseHas('wallet_conversion_audits', ['event' => 'rejected', 'error_code' => 'conversion_refund_exposure_pending']);
    }

    public function test_a_quote_issued_before_publication_is_not_new_authority(): void
    {
        $f = $this->conversionRefundFixture();
        $request = $this->quotedConversionRequest($f);
        $this->publishConversionRefund($f);
        $this->refused(fn () => app(WalletConversionService::class)->convert($request));
    }

    public function test_exact_completed_replay_under_new_custody_does_not_move_or_consume_again(): void
    {
        $f = $this->conversionRefundFixture();
        $request = $this->quotedConversionRequest($f);
        $first = app(WalletConversionService::class)->convert($request);
        $this->publishConversionRefund($f);
        $before = $this->conversionPaper();
        $replay = app(WalletConversionService::class)->convert($request);
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->reference, $replay->reference);
        $this->assertSame($before, $this->conversionPaper());
        $changed = $this->conversionRequest($f, $request->idempotencyKey, WalletConversionDirection::WinToCash, $request->quoteReference);
        $this->refused(fn () => app(WalletConversionService::class)->convert($changed), 'idempotency_conflict');
    }

    public function test_authenticated_api_returns_safe_source_refusal_and_keeps_the_quote(): void
    {
        $f = $this->conversionRefundFixture();
        $request = $this->quotedConversionRequest($f);
        $this->publishConversionRefund($f);
        $before = $this->conversionPaper();
        $this->actingAs($f['user'], 'sanctum')->postJson('/api/v1/wallet/conversions', [
            'direction' => 'cash_to_win', 'amount' => '10.00', 'quote_reference' => $request->quoteReference,
        ], ['Idempotency-Key' => $request->idempotencyKey])->assertStatus(422)
            ->assertJsonPath('error.code', 'conversion_refund_exposure_pending')->assertDontSee('SYNTHETIC')->assertDontSee('response_cipher');
        $this->assertSame($before, $this->conversionPaper());
    }

    public function test_expiry_disabled_recovery_and_revoked_staff_never_clear_custody(): void
    {
        $f = $this->conversionRefundFixture();
        $this->publishConversionRefund($f);
        config(['payment.refund_recovery.stripe.enabled' => false, 'payment.deposit_review.stripe.enabled' => false]);
        $f['actor']->syncPermissions([]);
        $this->travel(2)->days();
        $request = $this->quotedConversionRequest($f);
        $this->refused(fn () => app(WalletConversionService::class)->convert($request));
    }

    public static function corruptions(): array
    {
        return [['intent'], ['receipt'], ['missing'], ['financial'], ['chart']];
    }

    #[DataProvider('corruptions')]
    public function test_unproved_custody_or_false_financial_closure_never_authorizes_conversion(string $kind): void
    {
        $f = $this->conversionRefundFixture();
        $request = $this->quotedConversionRequest($f);
        if (in_array($kind, ['financial', 'chart'], true)) {
            app(WalletService::class)->credit($f['wallet']->fresh(), Money::of('30', Currency::THB), idempotencyKey: 'conversion-recovery-topup');
            $done = $this->recoverRefund($f);
            if ($kind === 'financial') {
                DB::table('financial_transactions')->where('id', $done['financial_transaction_id'])->update(['description' => 'SYNTHETIC changed original']);
            } else {
                DB::table('ledger_accounts')->where('code', '1000')->update(['currency' => 'USD']);
            }
        } else {
            $this->publishConversionRefund($f);
            match ($kind) {
                'intent' => DB::table(StripeRefundRecoveryService::INTENTS)->update(['response_hash' => str_repeat('0', 64)]),
                'receipt' => DB::table(StripeRefundRecoveryService::RECEIPTS)->update(['response_hash' => str_repeat('0', 64)]),
                'missing' => DB::table(StripeRefundRecoveryService::RECEIPTS)->delete(),
            };
        }
        $this->refused(fn () => app(WalletConversionService::class)->convert($request), 'conversion_refund_exposure_unproven');
    }

    public function test_guard_requires_a_real_sql_transaction(): void
    {
        $f = $this->conversionRefundFixture();
        $this->refused(fn () => app(StripeRefundConversionGuard::class)->assertAllowed($f['user']->id, Currency::THB), 'conversion_refund_exposure_unproven');
    }

    public function test_ambient_caller_cannot_catch_refusal_and_commit_any_money_or_quote_change(): void
    {
        $f = $this->conversionRefundFixture();
        $request = $this->quotedConversionRequest($f);
        $this->publishConversionRefund($f);
        DB::transaction(fn () => $this->refused(fn () => app(WalletConversionService::class)->convert($request)));
        $this->assertDatabaseCount('wallet_conversions', 0);
    }

    public function test_actual_proved_recovery_allows_new_balanced_conversion_without_a_second_recovery(): void
    {
        $f = $this->conversionRefundFixture();
        app(WalletService::class)->credit($f['wallet']->fresh(), Money::of('30', Currency::THB), idempotencyKey: 'convert-closed-source-topup');
        $this->recoverRefund($f);
        $request = $this->quotedConversionRequest($f);
        $beforeRecoveries = DB::table(StripeRefundRecoveryService::RECOVERIES)->get()->toJson();
        $result = app(WalletConversionService::class)->convert($request);
        $this->assertFalse($result->replayed);
        $this->assertSame('10.00', $f['wallet']->fresh()->balance);
        $this->assertSame('109.90', $f['win']->fresh()->balance);
        $this->assertSame($beforeRecoveries, DB::table(StripeRefundRecoveryService::RECOVERIES)->get()->toJson());
        app(LedgerBalanceValidator::class)->assertTransactionBalanced(FinancialTransaction::findOrFail($result->financialTransactionId));
    }

    public function test_another_owner_is_not_frozen_by_this_original_player_source(): void
    {
        $f = $this->conversionRefundFixture();
        $this->publishConversionRefund($f);
        $other = $this->createPlayer('0.00');
        $this->verifyUserKycForTest($other['user']);
        app(WalletService::class)->credit($other['wallet'], Money::of('20', Currency::THB), idempotencyKey: 'other-convert-owner-funds');
        $result = app(WalletConversionService::class)->convert($this->quotedConversionRequest($other));
        $this->assertFalse($result->replayed);
        $this->assertSame('10.00', $other['wallet']->fresh()->balance);
    }

    public function test_same_player_other_currency_is_not_cross_currency_debt_clearance(): void
    {
        $f = $this->conversionRefundFixture();
        $this->publishConversionRefund($f);
        config(['conversions.currency' => 'USD', 'fees.categories.cash_to_win.currency' => 'USD', 'fees.categories.win_to_cash.currency' => 'USD']);
        $usd = Wallet::factory()->create(['user_id' => $f['user']->id, 'currency' => Currency::USD, 'type' => WalletType::Primary]);
        app(WalletService::class)->credit($usd, Money::of('20', Currency::USD), idempotencyKey: 'other-convert-currency-funds');
        $result = app(WalletConversionService::class)->convert($this->quotedConversionRequest($f, currency: Currency::USD));
        $this->assertFalse($result->replayed);
        $this->assertSame('10.00', $usd->fresh()->balance);
    }

    public function test_signed_inbox_alone_does_not_create_unproved_debt_and_generic_credit_is_not_frozen(): void
    {
        $f = $this->conversionRefundFixture();
        $this->assertFalse(app(WalletConversionService::class)->convert($this->quotedConversionRequest($f))->replayed);
        $this->assertDatabaseCount(StripeRefundRecoveryService::INTENTS, 0);
        Http::assertSentCount(0);
        $this->publishConversionRefund($f);
        app(WalletService::class)->credit($f['wallet']->fresh(), Money::of('1', Currency::THB), idempotencyKey: 'generic-credit-not-conversion-admission');
        $this->assertSame('81.00', $f['wallet']->fresh()->balance);
    }

    public function test_permitted_original_reversal_remains_separate_from_new_conversion_admission(): void
    {
        $f = $this->conversionRefundFixture();
        $result = app(WalletConversionService::class)->convert($this->quotedConversionRequest($f));
        $this->publishConversionRefund($f);
        $f['actor']->givePermissionTo(AdminAccess::MANAGE_WALLET);
        $context = new ConversionAuditContextData(WalletConversionChannel::Admin, $f['actor']->id);
        $reversal = app(WalletConversionService::class)->reverse($result->conversionId, $context, 'Restore the proved original conversion.', 'refund-existing-reversal-key');
        $this->assertFalse($reversal->replayed);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame('100.00', $f['win']->fresh()->balance);
        $this->assertDatabaseCount(StripeRefundRecoveryService::INTENTS, 1);
    }
}
