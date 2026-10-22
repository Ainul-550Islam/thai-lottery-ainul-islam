<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Filament\Resources\DepositResource\DepositDecisionActions;
use App\Models\Deposit;
use App\Models\User;
use App\Services\Finance\Money;
use App\Services\Payment\DepositCheckoutAccess;
use App\Services\Payment\DepositCheckoutService;
use App\Services\Payment\PaymentInitiationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

final class StripeCheckoutReconciliationTest extends PaymentTestCase
{
    private function fixture(): array
    {
        Http::preventStrayRequests();
        $this->seed(RolePermissionSeeder::class);
        config(['payment.checkout_lookup.stripe.enabled' => true]);
        $f = $this->createPlayer('0.00');
        $f['actor'] = User::factory()->create();
        $f['actor']->assignRole('admin');
        $f['actor']->givePermissionTo(DepositCheckoutAccess::RECONCILE);
        Http::fake(['api.stripe.com/*' => Http::response([], 500)]);
        $this->refuse(fn () => app(PaymentInitiationService::class)->initiateDeposit($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::Stripe, 'synthetic-lookup-original-key'), 'checkout_outcome_unknown');
        $f['deposit'] = Deposit::query()->sole();
        Http::swap(new Factory);
        Http::preventStrayRequests();

        return $f;
    }

    private function refuse(callable $call, string $code): void
    {
        try {
            $call();
            $this->fail('Unsafe lookup action was accepted.');
        } catch (FinancialException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }

    private function providerSession(array $f): array
    {
        $a = DB::table(DepositCheckoutService::TABLE)->sole();
        $d = $f['deposit'];

        return ['object' => 'checkout.session', 'id' => 'cs_test_SYNTHETIC123456', 'mode' => 'payment',
            'status' => 'open', 'payment_status' => 'unpaid', 'livemode' => false,
            'url' => 'https://checkout.stripe.com/c/pay/SYNTHETIC123456', 'created' => time(), 'expires_at' => time() + 3600,
            'client_reference_id' => $d->reference_number, 'amount_total' => 10000, 'currency' => 'thb',
            'metadata' => ['checkout_attempt' => $a->uuid, 'deposit_id' => (string) $d->id,
                'deposit_reference' => $d->reference_number, 'wallet_id' => (string) $d->wallet_id, 'user_id' => (string) $d->user_id],
            'private_test_fact' => 'SYNTHETIC-NOT-A-LIVE-SECRET'];
    }

    private function fake(array $sessions, ?\Closure $during = null, ?array $detail = null, bool $more = false): void
    {
        Http::fake(['api.stripe.com/*' => function ($request) use ($sessions, $during, $detail, $more) {
            $this->assertSame('GET', $request->method(), 'Reconciliation must never call a POST, expire or refund endpoint.');
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            if ($during) {
                $during();
            }
            if (str_contains(explode('?', $request->url())[0], '/sessions/')) {
                return Http::response($detail ?? $sessions[0]);
            }

            return Http::response(['object' => 'list', 'data' => $sessions, 'has_more' => $more]);
        }]);
    }

    private function lookup(array $f): array
    {
        return app(DepositCheckoutService::class)->reconcileStripe($f['deposit'], $f['actor']);
    }

    public function test_positive_only_read_lookup_custodies_and_projects_without_credit_or_another_post(): void
    {
        $f = $this->fixture();
        $this->fake([$this->providerSession($f)]);
        $this->lookup($f);
        $a = DB::table(DepositCheckoutService::TABLE)->sole();
        $receipt = DB::table('deposit_checkout_lookup_receipts')->sole();
        $this->assertSame('projected', $a->state);
        $this->assertSame(1, (int) $a->attempt_count);
        $this->assertSame($receipt->uuid, $a->lookup_receipt_uuid);
        $this->assertSame($receipt->response_cipher, $a->response_cipher);
        $this->assertStringNotContainsString('SYNTHETIC-NOT-A-LIVE-SECRET', $a->response_cipher);
        $this->assertStringContainsString('SYNTHETIC-NOT-A-LIVE-SECRET', Crypt::decryptString($a->response_cipher));
        $before = (array) $a;
        $this->lookup($f);
        $this->assertSame($before, (array) DB::table(DepositCheckoutService::TABLE)->sole());
        Http::assertSentCount(2);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame('pending', $f['deposit']->fresh()->status->value);
    }

    public static function mismatches(): array
    {
        return [['amount_total', 100.0], ['amount_total', 9000], ['currency', 'usd'], ['mode', 'subscription'],
            ['client_reference_id', 'OTHER'], ['livemode', true], ['created', 1], ['url', 'https://evil.example/steal'],
            ['url', 'https://checkout.stripe.com@evil.example/steal'], ['status', 'unknown'], ['expires_at', 1],
            ['metadata.user_id', '999999'], ['metadata.deposit_id', '999999'], ['metadata.wallet_id', '999999'],
            ['metadata.deposit_reference', 'OTHER'], ['metadata.checkout_attempt', 'OTHER']];
    }

    #[DataProvider('mismatches')]
    public function test_wrong_money_original_metadata_account_mode_or_redirect_keeps_hold(string $field, mixed $value): void
    {
        $f = $this->fixture();
        $s = $this->providerSession($f);
        data_set($s, $field, $value);
        $this->fake([$s]);
        $before = (array) DB::table(DepositCheckoutService::TABLE)->sole();
        $this->refuse(fn () => $this->lookup($f), 'checkout_lookup_inconclusive');
        $this->assertSame($before, (array) DB::table(DepositCheckoutService::TABLE)->sole());
        $this->assertDatabaseCount('deposit_checkout_lookup_receipts', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public static function negativeLists(): array
    {
        return [['empty'], ['duplicate'], ['truncated'], ['http_error'], ['malformed']];
    }

    #[DataProvider('negativeLists')]
    public function test_empty_failed_ambiguous_or_incomplete_lookup_never_proves_nonacceptance(string $kind): void
    {
        $f = $this->fixture();
        $s = $this->providerSession($f);
        if ($kind === 'http_error' || $kind === 'malformed') {
            Http::fake(['api.stripe.com/*' => Http::response($kind === 'http_error' ? [] : ['data' => []], $kind === 'http_error' ? 503 : 200)]);
        } else {
            $second = array_merge($s, ['id' => 'cs_test_SYNTHETICDIFFERENT']);
            $this->fake($kind === 'empty' ? [] : ($kind === 'duplicate' ? [$s, $second] : [$s]), more: $kind === 'truncated');
        }
        $this->refuse(fn () => $this->lookup($f), 'checkout_lookup_inconclusive');
        $this->assertSame('unknown', DB::table(DepositCheckoutService::TABLE)->sole()->state);
        $this->assertDatabaseCount('deposit_checkout_lookup_receipts', 0);
    }

    public function test_retrieved_session_must_independently_match_the_original_list_candidate(): void
    {
        $f = $this->fixture();
        $s = $this->providerSession($f);
        $this->fake([$s], detail: array_merge($s, ['amount_total' => 9999]));
        $this->refuse(fn () => $this->lookup($f), 'checkout_lookup_inconclusive');
        $this->assertDatabaseCount('deposit_checkout_lookup_receipts', 0);
    }

    public function test_paginated_positive_lookup_validates_cursor_and_never_follows_arbitrary_urls(): void
    {
        $f = $this->fixture();
        $s = $this->providerSession($f);
        $other = array_merge($s, ['id' => 'cs_test_OTHERSYNTHETIC123', 'metadata' => ['checkout_attempt' => 'OTHER']]);
        $calls = 0;
        Http::fake(['api.stripe.com/*' => function ($request) use ($s, $other, &$calls) {
            $calls++;
            $this->assertSame('GET', $request->method());
            if ($calls === 1) {
                return Http::response(['object' => 'list', 'data' => [$other], 'has_more' => true]);
            }
            if ($calls === 2) {
                $this->assertSame($other['id'], $request['starting_after']);

                return Http::response(['object' => 'list', 'data' => [$s], 'has_more' => false]);
            }

            return Http::response($s);
        }]);
        $this->lookup($f);
        $this->assertSame(3, $calls);
        $this->assertDatabaseCount('deposit_checkout_lookup_receipts', 1);
    }

    public function test_three_page_hard_cap_never_turns_a_partial_scan_into_nonacceptance(): void
    {
        $f = $this->fixture();
        $calls = 0;
        Http::fake(['api.stripe.com/*' => function ($request) use (&$calls) {
            $this->assertSame('GET', $request->method());
            $calls++;

            return Http::response(['object' => 'list', 'has_more' => true, 'data' => [
                ['id' => 'cs_test_OTHERPAGE'.$calls, 'metadata' => ['checkout_attempt' => 'OTHER']],
            ]]);
        }]);
        $this->refuse(fn () => $this->lookup($f), 'checkout_lookup_inconclusive');
        $this->assertSame(3, $calls);
        $this->assertSame('unknown', DB::table(DepositCheckoutService::TABLE)->sole()->state);
        $this->assertDatabaseCount('deposit_checkout_lookup_receipts', 0);
    }

    public function test_lost_lookup_response_keeps_original_unknown_without_automatic_get_retry(): void
    {
        $f = $this->fixture();
        Http::fake(['api.stripe.com/*' => fn () => throw new ConnectionException('Synthetic lost GET response')]);
        $this->refuse(fn () => $this->lookup($f), 'checkout_lookup_inconclusive');
        $a = DB::table(DepositCheckoutService::TABLE)->sole();
        $this->assertSame('unknown', $a->state);
        $this->assertSame(1, (int) $a->attempt_count);
        $this->assertNull($a->lookup_receipt_uuid);
        $this->assertDatabaseCount('deposit_checkout_lookup_receipts', 0);
    }

    public function test_receipt_original_binding_is_required_for_all_later_sql_replay(): void
    {
        $f = $this->fixture();
        $this->fake([$this->providerSession($f)]);
        $this->lookup($f);
        DB::table('deposit_checkout_lookup_receipts')->update(['fence' => str_repeat('0', 64)]);
        $this->refuse(fn () => app(DepositCheckoutService::class)->recover($f['deposit']), 'checkout_response_unproven');
        Http::assertSentCount(2);
    }

    public function test_disabled_lookup_refuses_without_http(): void
    {
        $f = $this->fixture();
        config(['payment.checkout_lookup.stripe.enabled' => false]);
        Http::fake();
        $this->refuse(fn () => $this->lookup($f), 'checkout_lookup_inconclusive');
        Http::assertNothingSent();
    }

    public static function authorities(): array
    {
        return [['player'], ['no_permission'], ['banned'], ['deleted']];
    }

    #[DataProvider('authorities')]
    public function test_current_explicit_staff_duty_is_required_even_with_a_stale_actor_object(string $kind): void
    {
        $f = $this->fixture();
        if ($kind === 'player') {
            $f['actor']->removeRole('admin');
        } elseif ($kind === 'no_permission') {
            $f['actor']->revokePermissionTo(DepositCheckoutAccess::RECONCILE);
        } else {
            DB::table('users')->where('id', $f['actor']->id)->update($kind === 'banned' ? ['status' => 'banned'] : ['deleted_at' => now()]);
        }
        Http::fake();
        try {
            $this->lookup($f);
            $this->fail('Stale authority was accepted.');
        } catch (AuthorizationException) {
            Http::assertNothingSent();
        }
    }

    public function test_authority_revocation_during_get_prevents_persisting_lookup_as_authority(): void
    {
        $f = $this->fixture();
        $this->fake([$this->providerSession($f)], fn () => $f['actor']->revokePermissionTo(DepositCheckoutAccess::RECONCILE));
        try {
            $this->lookup($f);
            $this->fail('Revoked authority persisted a receipt.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('deposit_checkout_lookup_receipts', 0);
            $this->assertSame('unknown', DB::table(DepositCheckoutService::TABLE)->sole()->state);
        }
    }

    public function test_late_projection_failure_keeps_independent_receipt_for_customer_sql_only_recovery(): void
    {
        $f = $this->fixture();
        $this->fake([$this->providerSession($f)]);
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'payments')) {
                $armed = false;
                throw new \RuntimeException('SYNTHETIC late lookup projection failure');
            }
        });
        try {
            $this->lookup($f);
            $this->fail('Fault did not fire.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SYNTHETIC late lookup projection failure', $e->getMessage());
        }
        $this->assertSame('response_received', DB::table(DepositCheckoutService::TABLE)->sole()->state);
        $this->assertDatabaseCount('deposit_checkout_lookup_receipts', 1);
        app(DepositCheckoutService::class)->recover($f['deposit']);
        Http::assertSentCount(2);
        $this->assertSame('projected', DB::table(DepositCheckoutService::TABLE)->sole()->state);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_configuration_change_during_get_cannot_rebind_the_original_account(): void
    {
        $f = $this->fixture();
        $this->fake([$this->providerSession($f)], fn () => config(['payment.gateways.stripe.secret' => 'sk_test_DIFFERENT_ACCOUNT']));
        $this->refuse(fn () => $this->lookup($f), 'checkout_original_conflict');
        $this->assertDatabaseCount('deposit_checkout_lookup_receipts', 0);
    }

    public function test_removed_lookup_receipt_cannot_silently_become_a_normal_creator_response(): void
    {
        $f = $this->fixture();
        $this->fake([$this->providerSession($f)]);
        $this->lookup($f);
        DB::table('deposit_checkout_lookup_receipts')->delete();
        $this->refuse(fn () => app(DepositCheckoutService::class)->recover($f['deposit']), 'checkout_response_unproven');
        Http::assertSentCount(2);
    }

    public static function sessionStatuses(): array
    {
        return [['complete'], ['expired']];
    }

    #[DataProvider('sessionStatuses')]
    public function test_complete_or_expired_session_is_not_credit_or_release_authority(string $status): void
    {
        $f = $this->fixture();
        $s = array_merge($this->providerSession($f), ['status' => $status, 'url' => null]);
        $this->fake([$s]);
        $out = $this->lookup($f);
        $this->assertNull($out['gateway_response']->redirectUrl);
        $this->assertSame('pending', $out['payment']->status->value);
        $this->assertSame('pending', $out['deposit']->status->value);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
    }

    public function test_admin_action_uses_current_operator_and_never_calls_a_creation_path(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['actor']);
        $this->fake([$this->providerSession($f)]);
        $spec = collect(DepositDecisionActions::specs())->firstWhere('name', 'lookupCheckout');
        $this->assertTrue(($spec['visible'])($f['deposit']));
        ($spec['action'])($f['deposit']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('deposit_checkout_lookup_receipts', 1);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_caller_transaction_is_refused_before_lookup_or_any_new_receipt(): void
    {
        $f = $this->fixture();
        Http::fake();
        DB::beginTransaction();
        try {
            $this->refuse(fn () => $this->lookup($f), 'checkout_requires_top_level');
            Http::assertNothingSent();
        } finally {
            DB::rollBack();
        }
    }
}
