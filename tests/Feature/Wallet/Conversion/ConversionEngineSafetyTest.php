<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\DTOs\Wallet\ConversionAuditContextData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Exceptions\FinancialException;
use App\Exceptions\Wallet\ConversionException;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\WalletConversion;
use App\Models\WalletConversionAudit;
use App\Models\WalletConversionQuote;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

final class ConversionEngineSafetyTest extends ConversionTestCase
{
    private function refusal(callable $action, string $code): void
    {
        try {
            $action();
            $this->fail('The financial refusal was not raised.');
        } catch (ConversionException $error) {
            $this->assertSame($code, $error->errorCode());
        }
    }

    #[Test]
    public function a_quote_moves_no_money_and_records_no_ledger_entries(): void
    {
        $member = $this->makeFundedMember();
        $quote = $this->quoteFor($member['user']);
        $this->assertTrue($quote->isConsistent());
        $this->assertSame('1000.00', $this->balanceOf($member['cash']));
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(1, WalletConversionQuote::query()->count());
    }

    #[Test]
    public function conversion_writes_a_single_balanced_transaction_and_durable_audit(): void
    {
        $member = $this->makeFundedMember();
        $result = $this->convert($member['user']);
        $this->assertTrue($result->movedMoney());
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(3, LedgerEntry::query()->count());
        $this->assertSame(1, WalletConversionAudit::query()->where('event', 'completed')->count());
        $this->assertSame(1, WalletConversionQuote::query()->where('status', 'consumed')->count());
        $this->assertTrue($this->ledgerIsBalanced()['balanced']);
        $row = WalletConversion::query()->firstOrFail();
        $this->assertSame('-100.00', $this->walletLedgerDelta($row, $member['cash']->id));
        $this->assertSame('99.00', $this->walletLedgerDelta($row, $member['win']->id));
        $this->assertSame('1.00', $this->feeRevenuePosted($row));
    }

    #[Test]
    public function a_new_key_cannot_consume_the_same_quote_twice(): void
    {
        $member = $this->makeFundedMember();
        $quote = $this->quoteFor($member['user']);
        $request = $this->request($member['user'], quoteReference: $quote->reference);
        $this->service()->convert($request);
        $this->refusal(fn () => $this->service()->convert($request->withIdempotencyKey('another-key-00000001')), 'quote_expired');
        $this->assertSame('900.00', $this->balanceOf($member['cash']));
        $this->assertSame(1, WalletConversion::query()->count());
    }

    #[Test]
    public function an_identical_retry_replays_even_after_quote_expiry_and_tariff_change(): void
    {
        $member = $this->makeFundedMember();
        $quote = $this->quoteFor($member['user']);
        $request = $this->request($member['user'], quoteReference: $quote->reference);
        $first = $this->service()->convert($request);
        try {
            Carbon::setTestNow(Carbon::now()->addHour());
            config(['fees.conversion.cash_to_win_percentage' => '8.00']);
            $retry = $this->service()->convert($request);
            $this->assertTrue($retry->replayed);
            $this->assertSame($first->reference, $retry->reference);
            $this->assertSame('1.00', $retry->feeAmount);
            $this->assertSame(1, FinancialTransaction::query()->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function changed_amount_with_the_same_key_is_a_conflict_not_a_new_movement(): void
    {
        $member = $this->makeFundedMember();
        $quote = $this->quoteFor($member['user']);
        $this->service()->convert($this->request($member['user'], quoteReference: $quote->reference));
        $this->refusal(fn () => $this->service()->convert($this->request($member['user'], amount: '150.00', quoteReference: $quote->reference)), 'idempotency_conflict');
        $this->assertSame(1, WalletConversion::query()->count());
        $this->assertSame('900.00', $this->balanceOf($member['cash']));
    }

    #[Test]
    public function a_quote_from_another_owner_is_not_disclosed_or_consumed(): void
    {
        $alice = $this->makeFundedMember();
        $bob = $this->makeFundedMember();
        $quote = $this->quoteFor($alice['user']);
        $this->refusal(fn () => $this->service()->convert($this->request($bob['user'], quoteReference: $quote->reference)), 'quote_not_found');
        $this->assertSame('1000.00', $this->balanceOf($bob['cash']));
        $this->assertSame(0, WalletConversion::query()->count());
    }

    #[Test]
    public function quote_expiry_is_checked_again_inside_execution(): void
    {
        $member = $this->makeFundedMember();
        $quote = $this->quoteFor($member['user']);
        try {
            Carbon::setTestNow(Carbon::instance($quote->expiresAt));
            $this->refusal(fn () => $this->service()->convert($this->request($member['user'], quoteReference: $quote->reference)), 'quote_expired');
            $this->assertSame(0, FinancialTransaction::query()->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function a_price_change_requires_a_new_quote_even_when_the_version_is_unchanged(): void
    {
        $member = $this->makeFundedMember();
        $quote = $this->quoteFor($member['user']);
        config(['fees.conversion.cash_to_win_percentage' => '2.00']);
        $this->refusal(fn () => $this->service()->convert($this->request($member['user'], quoteReference: $quote->reference)), 'quote_expired');
        $this->assertSame('1000.00', $this->balanceOf($member['cash']));
    }

    #[Test]
    public function a_missing_fee_account_rolls_back_both_wallets_and_all_financial_rows(): void
    {
        $member = $this->makeFundedMember();
        $quote = $this->quoteFor($member['user']);
        LedgerAccount::query()->where('code', '4100')->update(['is_active' => false]);
        try {
            $this->service()->convert($this->request($member['user'], quoteReference: $quote->reference));
            $this->fail('An inactive ledger account must refuse posting.');
        } catch (FinancialException $error) {
            $this->assertSame('ledger_account_not_postable', $error->errorCode());
        }
        $this->assertSame('1000.00', $this->balanceOf($member['cash']));
        $this->assertSame('0.00', $this->balanceOf($member['win']));
        $this->assertSame(0, WalletConversion::query()->count());
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertTrue(WalletConversionQuote::query()->firstOrFail()->isOpen());
    }

    #[Test]
    public function destination_balance_ceiling_rolls_back_the_prior_source_debit(): void
    {
        $member = $this->makeFundedMember();
        $quote = $this->quoteFor($member['user']);
        config(['finance.wallet.max_balance' => '50.00']);
        try {
            $this->service()->convert($this->request($member['user'], quoteReference: $quote->reference));
            $this->fail('The destination cap must be enforced.');
        } catch (FinancialException $error) {
            $this->assertSame('wallet_maximum_balance_exceeded', $error->errorCode());
        }
        $this->assertSame('1000.00', $this->balanceOf($member['cash']));
        $this->assertSame(0, WalletConversion::query()->count());
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    #[Test]
    public function money_spent_after_a_quote_is_not_spendable_again(): void
    {
        $member = $this->makeFundedMember();
        $quote = $this->quoteFor($member['user']);
        app(WalletService::class)->debit($member['cash'], Money::of('950.00', Currency::THB), FinancialTransactionType::Transfer, 'other-spend-key-00000001');
        $this->refusal(fn () => $this->service()->convert($this->request($member['user'], quoteReference: $quote->reference)), 'insufficient_balance');
        $this->assertSame('50.00', $this->balanceOf($member['cash']));
        $this->assertSame(0, WalletConversion::query()->count());
    }

    #[Test]
    public function daily_limit_is_rechecked_after_a_previously_issued_quote(): void
    {
        $member = $this->makeFundedMember();
        config(['conversions.limits.daily_limit' => '150.00']);
        $quote = $this->quoteFor($member['user']);
        $this->convert($member['user'], idempotencyKey: 'first-limit-key-000001');
        $this->refusal(fn () => $this->service()->convert($this->request($member['user'], quoteReference: $quote->reference)), 'daily_limit_exceeded');
        $this->assertSame(1, WalletConversion::query()->count());
    }

    #[Test]
    public function reserved_balance_cannot_be_spent_by_conversion(): void
    {
        $member = $this->makeFundedMember();
        app(WalletService::class)->lockFunds($member['cash'], Money::of('950.00', Currency::THB));
        $this->refusal(fn () => $this->quoteFor($member['user']), 'insufficient_balance');
        $this->assertSame('1000.00', $this->balanceOf($member['cash']));
    }

    #[Test]
    public function an_admin_channel_string_never_grants_reversal_permission(): void
    {
        $member = $this->makeFundedMember();
        $result = $this->convert($member['user']);
        $this->refusal(fn () => $this->service()->reverse($result->conversionId,
            new ConversionAuditContextData(WalletConversionChannel::Admin, $member['user']->id),
            'Member tries to impersonate an operator.'), 'reversal_forbidden');
        $this->assertSame('900.00', $this->balanceOf($member['cash']));
    }

    #[Test]
    public function a_null_console_actor_is_not_an_authorised_operator(): void
    {
        $member = $this->makeFundedMember();
        $result = $this->convert($member['user']);
        $this->refusal(fn () => $this->service()->reverse($result->conversionId,
            ConversionAuditContextData::forSystem(), 'Automated unapproved reversal attempt.'), 'reversal_forbidden');
        $this->assertSame(1, WalletConversion::query()->count());
    }

    #[Test]
    public function permitted_reversal_restores_fee_and_balances_and_replays_once(): void
    {
        $member = $this->makeFundedMember();
        $result = $this->convert($member['user']);
        $operator = $this->makeOperator();
        $context = new ConversionAuditContextData(WalletConversionChannel::Admin, $operator->id);
        $reason = 'Support confirmed an accidental duplicate intention.';
        $reverse = $this->service()->reverse($result->conversionId, $context, $reason, 'reverse-once-key-000001');
        $retry = $this->service()->reverse($result->conversionId, $context, $reason, 'reverse-once-key-000001');
        $this->assertTrue($retry->replayed);
        $this->assertSame($reverse->reference, $retry->reference);
        $this->assertSame('-1.00', $reverse->feeAmount);
        $this->assertSame(1, WalletConversionAudit::query()->where('reference', $result->reference)->where('event', 'reversed')->count());
        $this->assertSame(1, WalletConversionAudit::query()->where('reference', $reverse->reference)->where('event', 'completed')->count());
        $this->assertSame('1000.00', $this->balanceOf($member['cash']));
        $this->assertSame('0.00', $this->balanceOf($member['win']));
        $this->assertSame(2, FinancialTransaction::query()->count());
        $this->assertSame(6, LedgerEntry::query()->count());
        $this->assertTrue($this->ledgerIsBalanced()['balanced']);
        $this->refusal(fn () => $this->service()->reverse($result->conversionId, $context, $reason, 'new-reversal-key-000001'), 'reversal_unavailable');
    }

    #[Test]
    public function a_reversal_does_not_erase_the_original_rolling_limit_usage(): void
    {
        $member = $this->makeFundedMember();
        $result = $this->convert($member['user']);
        $operator = $this->makeOperator();
        $this->service()->reverse($result->conversionId, new ConversionAuditContextData(WalletConversionChannel::Admin, $operator->id), 'Correction approved by support operator.');
        config(['conversions.limits.daily_limit' => '150.00']);
        $this->refusal(fn () => $this->quoteFor($member['user']), 'daily_limit_exceeded');
    }

    #[Test]
    public function summary_history_and_member_page_resolve_the_real_bound_engine(): void
    {
        $member = $this->makeFundedMember();
        $this->convert($member['user']);
        Sanctum::actingAs($member['user']);
        $this->getJson('/api/v1/wallet/conversions/summary')->assertOk()->assertJsonPath('data.summary.completed', 1);
        $this->getJson('/api/v1/wallet/conversions')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->actingAs($member['user'])->get('/wallet/convert')->assertOk();
    }

    #[Test]
    public function a_failed_completed_audit_write_rolls_back_the_entire_movement(): void
    {
        $member = $this->makeFundedMember();
        $quote = $this->quoteFor($member['user']);
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This fault injector uses a SQLite trigger.');
        }
        DB::unprepared("CREATE TRIGGER conversion_test_audit_failure BEFORE INSERT ON wallet_conversion_audits WHEN NEW.event = 'completed' BEGIN SELECT RAISE(ABORT, 'test audit failure'); END");
        try {
            try {
                $this->service()->convert($this->request($member['user'], quoteReference: $quote->reference));
                $this->fail('Missing atomic audit evidence must abort the movement.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('test audit failure', $error->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER conversion_test_audit_failure');
        }
        $this->assertSame('1000.00', $this->balanceOf($member['cash']));
        $this->assertSame('0.00', $this->balanceOf($member['win']));
        $this->assertSame(0, WalletConversion::query()->count());
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertTrue(WalletConversionQuote::query()->firstOrFail()->isOpen());
    }

    #[Test]
    public function reversal_after_destination_funds_are_spent_cannot_overdraw_or_partially_refund(): void
    {
        $member = $this->makeFundedMember();
        $result = $this->convert($member['user']);
        app(WalletService::class)->debit($member['win'], Money::of('50.00', Currency::THB), FinancialTransactionType::Transfer, 'spend-win-funds-key-0001');
        $operator = $this->makeOperator();
        try {
            $this->service()->reverse($result->conversionId, new ConversionAuditContextData(WalletConversionChannel::Admin, $operator->id), 'Operator requests an approved correction.');
            $this->fail('A reversal cannot spend funds no longer available.');
        } catch (FinancialException $error) {
            $this->assertSame('insufficient_balance', $error->errorCode());
        }
        $this->assertSame('900.00', $this->balanceOf($member['cash']));
        $this->assertSame('49.00', $this->balanceOf($member['win']));
        $this->assertSame(1, WalletConversion::query()->count());
        $this->assertSame(2, FinancialTransaction::query()->count());
        $this->assertTrue($this->ledgerIsBalanced()['balanced']);
    }

    #[Test]
    public function absent_idempotency_key_never_reaches_the_money_transaction(): void
    {
        $member = $this->makeFundedMember();
        $quote = $this->quoteFor($member['user']);
        $this->refusal(fn () => $this->service()->convert($this->request($member['user'], quoteReference: $quote->reference)->withIdempotencyKey(null)), 'idempotency_key_required');
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame('1000.00', $this->balanceOf($member['cash']));
    }

    #[Test]
    public function web_quote_preserves_the_amount_and_execution_renders_its_audit_detail(): void
    {
        $member = $this->makeFundedMember();
        $this->actingAs($member['user'])->post('/wallet/convert/quote', [
            'direction' => 'cash_to_win', 'amount' => '123.45',
        ])->assertRedirect()->assertSessionHas('_old_input.amount', '123.45');
        $reference = session('conversion_quote_reference');
        $this->get('/wallet/convert')->assertOk()->assertSee('value="123.45"', false);
        $response = $this->post('/wallet/convert', [
            'direction' => 'cash_to_win', 'amount' => '123.45',
            'quote_reference' => $reference, 'idempotency_key' => 'web-complete-key-000001',
        ])->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('123.45');
        $this->assertSame('876.55', $this->balanceOf($member['cash']));
        $this->assertSame('122.22', $this->balanceOf($member['win']));
    }

    #[Test]
    public function blocked_member_page_renders_a_refusal_without_a_missing_enum_method(): void
    {
        $member = $this->makeFundedMember();
        $member['user']->kycDocuments()->update(['status' => 'rejected']);
        $this->actingAs($member['user'])->get('/wallet/convert')->assertOk();
        $this->assertSame(0, WalletConversion::query()->count());
    }

    #[Test]
    public function malformed_history_filters_are_rejected_without_exposing_other_owners(): void
    {
        $member = $this->makeFundedMember();
        Sanctum::actingAs($member['user']);
        $this->getJson('/api/v1/wallet/conversions?statuses[0][bad]=value')->assertStatus(422);
    }

    #[Test]
    public function engine_performs_no_implicit_currency_conversion(): void
    {
        $member = $this->makeFundedMember();
        $request = new ConversionRequestData(
            WalletConversionDirection::CashToWin, '100.00', Currency::USD,
            WalletConversionChannel::Api, $member['user']->id,
        );
        $this->refusal(fn () => $this->service()->quote($request), 'currency_mismatch');
        $this->assertSame(0, WalletConversionQuote::query()->count());
    }
}
