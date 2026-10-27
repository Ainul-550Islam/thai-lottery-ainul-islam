<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\Enums\ConversionFeeBearer;
use App\Enums\Currency;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionReconciliationState;
use App\Enums\WalletConversionRejectionReason;
use App\Enums\WalletConversionStatus;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletConversion;
use App\Services\Finance\Money;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real authenticated web routes and engine; no controller/view/money doubles. */
class ConversionRenderingTest extends ConversionTestCase
{
    public static function locales(): array
    {
        return ['English' => ['en'], 'Thai' => ['th']];
    }

    #[DataProvider('locales')]
    public function test_dynamic_labels_and_placeholders_resolve_without_english_fallback(string $locale): void
    {
        foreach ([WalletType::class, WalletConversionDirection::class, WalletConversionStatus::class,
            WalletConversionRejectionReason::class, ConversionFeeBearer::class,
            WalletConversionChannel::class, WalletConversionReconciliationState::class] as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertTrue(Lang::has($case->labelKey(), $locale, false), $case->labelKey());
                $this->assertNotSame($case->labelKey(), Lang::get($case->labelKey(), [], $locale, false));
            }
        }

        $english = require base_path('lang/en/wallet_conversion.php');
        $thai = require base_path('lang/th/wallet_conversion.php');
        $this->assertSame(array_keys($english), array_keys($thai));
        foreach ($english as $key => $text) {
            preg_match_all('/:[a-z_]+/', $text, $en);
            preg_match_all('/:[a-z_]+/', $thai[$key], $th);
            sort($en[0]);
            sort($th[0]);
            $this->assertSame($en[0], $th[0], $key.' placeholders');
            $this->assertMatchesRegularExpression('/[\x{0E00}-\x{0E7F}]/u', $thai[$key], $key.' Thai copy');
        }
    }

    #[DataProvider('locales')]
    public function test_open_page_renders_fee_limits_own_wallet_disclosures_and_script_messages(string $locale): void
    {
        $member = $this->makeFundedMember();
        $this->actingAs($member['user']);
        $response = $this->get('/wallet/convert?lang='.$locale);
        $this->assertTranslatedPage($response);
        foreach (['page_title', 'page_lead', 'nav_convert', 'fee_heading', 'limits_hint',
            'direction_cash_to_win', 'wallet_primary', 'wallet_win', 'script_amount_hint',
            'script_unpriced_note', 'script_no_crypto_note', 'script_unavailable'] as $key) {
            $response->assertSee(__('wallet_conversion.'.$key));
        }
        $response->assertSee('data-wc-conversion-form', false)
            ->assertSee('data-wc-currency="THB"', false)
            ->assertSee('data-wc-available="1000.00"', false)
            ->assertSee('1.00%')->assertSee(Money::of('99.00', Currency::THB)->format());
        $this->assertNoFundsMoved($member);
    }

    #[DataProvider('locales')]
    public function test_master_and_direction_closures_are_distinct_and_remove_the_form(string $locale): void
    {
        $member = $this->makeFundedMember();
        $this->actingAs($member['user']);
        config(['conversions.enabled' => false]);
        $master = $this->get('/wallet/convert?lang='.$locale);
        $this->assertTranslatedPage($master);
        $master->assertSee(__('wallet_conversion.closed_master_title'))
            ->assertSee(__('wallet_conversion.closed_master_body'))
            ->assertDontSee('data-wc-conversion-form', false)
            ->assertSee(__('wallet_conversion.fee_heading'));

        config(['conversions.enabled' => true, 'conversions.directions.cash_to_win.enabled' => false]);
        $closed = $this->get('/wallet/convert?direction=cash_to_win&lang='.$locale);
        $this->assertTranslatedPage($closed);
        $closed->assertSee(__('wallet_conversion.closed_direction_title', [
            'direction' => __('wallet_conversion.direction_cash_to_win'),
        ]))->assertSee(__('wallet_conversion.closed_direction_body'))
            ->assertDontSee(__('wallet_conversion.closed_master_title'))
            ->assertDontSee('data-wc-conversion-form', false);

        $open = $this->get('/wallet/convert?direction=win_to_cash&lang='.$locale);
        $this->assertTranslatedPage($open);
        $open->assertSee(__('wallet_conversion.blocked_title'))
            ->assertSee(__('wallet_conversion.rejection_insufficient_balance'))
            ->assertDontSee(__('wallet_conversion.closed_master_title'))
            ->assertDontSee(__('wallet_conversion.closed_direction_body'));
        $this->assertNoFundsMoved($member);
    }

    #[DataProvider('locales')]
    public function test_unverified_member_sees_the_policy_reason_not_a_form(string $locale): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $response = $this->get('/wallet/convert?lang='.$locale);
        $this->assertTranslatedPage($response);
        $response->assertSee(__('wallet_conversion.blocked_title'))
            ->assertSee(__('wallet_conversion.rejection_kyc_not_verified'))
            ->assertDontSee('data-wc-conversion-form', false);
        $this->assertSame(0, WalletConversion::query()->count());
    }

    #[DataProvider('locales')]
    public function test_unpublished_fee_is_not_displayed_as_a_free_conversion(string $locale): void
    {
        $member = $this->makeFundedMember();
        config(['fees.conversion.cash_to_win_percentage' => null]);
        $response = $this->actingAs($member['user'])->get('/wallet/convert?lang='.$locale);
        $this->assertTranslatedPage($response);
        $response->assertSee(__('wallet_conversion.fee_not_published'))
            ->assertSee(__('wallet_conversion.rejection_fee_rule_invalid'))
            ->assertDontSee('data-wc-conversion-form', false);
        $this->assertNoFundsMoved($member);
    }

    #[DataProvider('locales')]
    public function test_quote_execute_receipt_history_and_retry_are_localized_and_move_money_once(string $locale): void
    {
        $member = $this->makeFundedMember();
        $this->actingAs($member['user']);
        // Real locale middleware persists the member's choice for the POSTs.
        $this->get('/wallet/convert?lang='.$locale)->assertOk();
        $this->post('/wallet/convert/quote', ['direction' => 'cash_to_win', 'amount' => '100.00'])
            ->assertRedirect()->assertSessionHas('status', function ($message): bool {
                return str_contains($message, '1.00') && str_contains($message, '99.00')
                    && str_contains($message, 'THB');
            });
        $quoteMessage = session('status');
        $quote = session('conversion_quote_reference');
        $this->assertNotEmpty($quote);
        $this->assertSame('1000.00', $this->balanceOf($member['cash']));
        $quoted = $this->get('/wallet/convert');
        $this->assertTranslatedPage($quoted);
        $quoted->assertSee($quote)->assertSee('value="100.00"', false)
            ->assertSee(trans_choice('wallet_conversion.open_quotes_heading', 1))
            ->assertSee($quoteMessage);

        $payload = ['direction' => 'cash_to_win', 'amount' => '100.00',
            'quote_reference' => $quote, 'idempotency_key' => 'localized-convert-'.$locale.'-000001'];
        $this->post('/wallet/convert', $payload)->assertRedirect();
        $row = WalletConversion::query()->where('status', 'completed')->firstOrFail();
        $message = __('wallet_conversion.status_converted', ['net' => '99.00', 'currency' => 'THB',
            'wallet' => __('wallet_conversion.wallet_win'), 'fee' => '1.00', 'reference' => $row->reference]);
        $this->assertSame($message, session('status'));
        $receipt = $this->get('/wallet/convert/'.$row->reference);
        $this->assertTranslatedPage($receipt);
        foreach (['detail_title', 'wallet_primary', 'wallet_win', 'channel_web', 'status_completed',
            'event_completed'] as $key) {
            $receipt->assertSee(__('wallet_conversion.'.$key));
        }
        $receipt->assertSee($row->reference)->assertSee(Money::of('99.00', Currency::THB)->format());
        $ledgerRows = LedgerEntry::query()->count();
        $this->post('/wallet/convert', $payload)->assertRedirect()
            ->assertSessionHas('status', __('wallet_conversion.status_replayed', ['reference' => $row->reference]));
        $this->assertSame($ledgerRows, LedgerEntry::query()->count());
        $this->assertSame('900.00', $this->balanceOf($member['cash']));
        $this->assertSame('99.00', $this->balanceOf($member['win']));
        $this->assertSame(1, WalletConversion::query()->where('status', 'completed')->count());
        $history = $this->get('/wallet/convert');
        $this->assertTranslatedPage($history);
        $history->assertSee('100.00 THB')->assertSee('1.00 THB')->assertSee('99.00 THB')
            ->assertSee(trans_choice('wallet_conversion.summary_line', 1, ['completed' => 1]));
    }

    #[DataProvider('locales')]
    public function test_closed_quote_validation_uses_translated_copy_without_reaching_the_engine(string $locale): void
    {
        $member = $this->makeFundedMember();
        $this->actingAs($member['user'])->get('/wallet/convert?lang='.$locale)->assertOk();
        config(['conversions.enabled' => false]);
        $this->post('/wallet/convert/quote', ['direction' => 'cash_to_win', 'amount' => '100.00'])
            ->assertRedirect()->assertSessionHasErrors(['direction' => __('wallet_conversion.rejection_feature_disabled')]);
        $response = $this->get('/wallet/convert');
        $this->assertTranslatedPage($response);
        $response->assertSee(__('wallet_conversion.closed_master_body'));
        $this->assertNoFundsMoved($member);
    }

    #[DataProvider('locales')]
    public function test_preview_is_server_priced_read_only_and_locale_independent(string $locale): void
    {
        $member = $this->makeFundedMember();
        $response = $this->actingAs($member['user'])->getJson('/wallet/convert/preview?direction=cash_to_win&amount=100.00&currency=THB&lang='.$locale);
        $response->assertOk()->assertJsonPath('fee_amount', '1.00')
            ->assertJsonPath('amount_out', '99.00')->assertJsonPath('percentage', '1.00')
            ->assertJsonPath('currency', 'THB')->assertJsonPath('configured', true);
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertNoFundsMoved($member);
    }

    #[DataProvider('locales')]
    public function test_another_currency_wallet_cannot_replace_the_configured_currency_balance(string $locale): void
    {
        $member = $this->makeFundedMember();
        foreach ([WalletType::Primary, WalletType::Win] as $type) {
            $wallet = Wallet::query()->create(['user_id' => $member['user']->id, 'type' => $type, 'currency' => Currency::USD]);
            $wallet->forceFill(['status' => WalletStatus::Active, 'balance' => '777.00'])->save();
        }
        $response = $this->actingAs($member['user'])->get('/wallet/convert?lang='.$locale);
        $this->assertTranslatedPage($response);
        $response->assertViewHas('sourceWallet', fn (Wallet $wallet): bool => $wallet->id === $member['cash']->id)
            ->assertViewHas('destinationWallet', fn (Wallet $wallet): bool => $wallet->id === $member['win']->id)
            ->assertSee('data-wc-available="1000.00"', false)
            ->assertDontSee('777.00');
        $this->assertNoFundsMoved($member);
    }

    #[DataProvider('locales')]
    public function test_missing_and_other_members_receipts_have_the_same_localized_refusal(string $locale): void
    {
        $other = $this->makeFundedMember();
        $result = $this->convert($other['user']);
        $mine = $this->makeFundedMember();
        $this->actingAs($mine['user'])->get('/wallet/convert?lang='.$locale)->assertOk();
        foreach ([$result->reference, 'nonexistent-receipt-reference'] as $reference) {
            $this->get('/wallet/convert/'.$reference)->assertRedirect('/wallet/convert')
                ->assertSessionHas('error', __('wallet_conversion.conversion_not_found'));
        }
        $this->assertSame('1000.00', $this->balanceOf($mine['cash']));
    }

    #[DataProvider('locales')]
    public function test_engine_refusal_preserves_its_machine_code_and_localizes_the_reason(string $locale): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/wallet/convert?lang='.$locale)->assertOk();
        $this->post('/wallet/convert/quote', ['direction' => 'cash_to_win', 'amount' => '100.00'])
            ->assertRedirect()->assertSessionHas('conversion_error_code', 'kyc_not_verified')
            ->assertSessionHas('error', __('wallet_conversion.rejection_kyc_not_verified'));
        $response = $this->get('/wallet/convert');
        $this->assertTranslatedPage($response);
        $response->assertSee(__('wallet_conversion.rejection_kyc_not_verified'))->assertSee('kyc_not_verified');
        $this->assertSame(0, WalletConversion::query()->where('status', 'completed')->count());
    }

    #[DataProvider('locales')]
    public function test_invalid_amount_has_a_localized_field_error_and_no_money_effect(string $locale): void
    {
        $member = $this->makeFundedMember();
        $this->actingAs($member['user'])->get('/wallet/convert?lang='.$locale)->assertOk();
        $this->post('/wallet/convert/quote', ['direction' => 'cash_to_win', 'amount' => '10.001'])
            ->assertRedirect()->assertSessionHasErrors(['amount' => __('wallet_conversion.validation_amount_precision', ['attribute' => 'amount'])]);
        $this->assertNoFundsMoved($member);
    }

    private function assertTranslatedPage(TestResponse $response): void
    {
        $response->assertOk()->assertDontSee('wallet_conversion.', false);
        $this->assertDoesNotMatchRegularExpression('/:(?:count|seconds|completed|direction|remaining|currency|net|reference)\b/', $response->getContent());
    }

    private function assertNoFundsMoved(array $member): void
    {
        $this->assertSame('1000.00', $this->balanceOf($member['cash']));
        $this->assertSame('0.00', $this->balanceOf($member['win']));
        $this->assertSame(0, WalletConversion::query()->where('status', 'completed')->count());
    }
}
