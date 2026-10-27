<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Filament\Resources\DepositResource\DepositDecisionActions;
use App\Models\User;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\Money;
use App\Services\Payment\DepositCheckoutService;
use App\Services\Payment\DepositSettlementAccess;
use App\Services\Payment\PaymentInitiationService;
use App\Services\Payment\PaymentReconciliationService;
use App\Services\Payment\StripeDepositSettlementService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

final class StripeDepositSettlementTest extends PaymentTestCase
{
    private function fixture(string $suffix = 'FIRST'): array
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->seed(RolePermissionSeeder::class);
        config(['payment.deposit_settlement.stripe.enabled' => true]);
        $f = $this->createPlayer('0.00');
        $f['actor'] = User::factory()->create();
        $f['actor']->assignRole('admin');
        $f['actor']->givePermissionTo(DepositSettlementAccess::RECONCILE);
        Http::fake(['api.stripe.com/*' => fn ($request) => Http::response([
            'id' => 'cs_test_SYNTHETIC'.$suffix, 'url' => 'https://checkout.stripe.com/c/pay/SYNTHETIC',
            'object' => 'checkout.session', 'mode' => 'payment', 'status' => 'open', 'payment_status' => 'unpaid',
            'created' => now()->timestamp, 'expires_at' => now()->timestamp + 3600, 'livemode' => false,
            'amount_total' => 10000, 'currency' => 'thb', 'client_reference_id' => $request['client_reference_id'],
            'metadata' => $request['metadata'],
        ])]);
        $out = app(PaymentInitiationService::class)->initiateDeposit($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::Stripe, 'synthetic-capture-key-'.$suffix);
        $f['deposit'] = $out['deposit'];
        $f['payment'] = $out['payment'];
        Http::swap(new Factory);
        Http::preventStrayRequests();

        return $f;
    }

    private function proof(array $f, string $suffix = 'FIRST'): array
    {
        $a = DB::table(DepositCheckoutService::TABLE)->where('deposit_id', $f['deposit']->id)->sole();
        $common = ['currency' => 'thb', 'amount' => 10000, 'livemode' => false, 'created' => now()->timestamp];
        $session = array_merge($common, ['object' => 'checkout.session', 'id' => $f['payment']->gateway_reference,
            'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_SYNTHETIC'.$suffix,
            'amount_total' => 10000, 'client_reference_id' => $f['deposit']->reference_number,
            'metadata' => ['checkout_attempt' => $a->uuid, 'deposit_id' => (string) $f['deposit']->id,
                'deposit_reference' => $f['deposit']->reference_number, 'wallet_id' => (string) $f['wallet']->id,
                'user_id' => (string) $f['user']->id]]);
        $intent = array_merge($common, ['object' => 'payment_intent', 'id' => 'pi_SYNTHETIC'.$suffix,
            'status' => 'succeeded', 'amount_received' => 10000, 'amount_capturable' => 0, 'latest_charge' => 'ch_SYNTHETIC'.$suffix,
            'client_secret' => 'SYNTHETIC-PRIVATE-CAPTURE-FACT']);
        $charge = array_merge($common, ['object' => 'charge', 'id' => 'ch_SYNTHETIC'.$suffix,
            'status' => 'succeeded', 'paid' => true, 'captured' => true, 'refunded' => false, 'disputed' => false,
            'amount_captured' => 10000, 'amount_refunded' => 0, 'payment_intent' => $intent['id'], 'failure_code' => null, 'review' => null]);

        $refunds = ['object' => 'list', 'has_more' => false, 'data' => []];

        return compact('session', 'intent', 'charge', 'refunds');
    }

    private function fakeProof(array $p, ?\Closure $during = null): void
    {
        Http::fake(['api.stripe.com/*' => function ($request) use ($p, $during) {
            $this->assertSame('GET', $request->method());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame('Bearer '.self::STRIPE_SECRET, $request->header('Authorization')[0]);
            if ($during) {
                $during();
            }
            $path = parse_url($request->url(), PHP_URL_PATH);
            $key = str_contains($path, '/checkout/sessions/') ? 'session' : (str_contains($path, '/payment_intents/') ? 'intent' : (str_contains($path, '/charges/') ? 'charge' : 'refunds'));
            if ($key === 'refunds') {
                $this->assertSame($p['charge']['id'], $request['charge']);
                $this->assertSame(1, $request['limit']);
            }

            return Http::response(json_encode($p[$key], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']);
        }]);
    }

    private function settle(array $f): array
    {
        return app(StripeDepositSettlementService::class)->reconcile($f['deposit'], $f['actor']);
    }

    private function refuse(callable $call, string $code): void
    {
        try {
            $call();
            $this->fail('Unsafe capture authority was accepted.');
        } catch (FinancialException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }

    public function test_authenticated_original_capture_credits_canonical_net_once_and_replays_without_http(): void
    {
        $f = $this->fixture();
        $this->fakeProof($this->proof($f));
        $out = $this->settle($f);
        $this->assertTrue($out['credited']);
        $this->assertSame((string) $f['deposit']->net_amount, $f['wallet']->fresh()->balance);
        $this->assertSame('confirmed', $f['deposit']->fresh()->status->value);
        $this->assertSame('captured', $f['payment']->fresh()->status->value);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseCount('payment_webhooks', 0);
        $r = DB::table(StripeDepositSettlementService::TABLE)->sole();
        $this->assertNotNull($r->applied_at);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-CAPTURE-FACT', $r->response_cipher);
        $this->assertStringContainsString('SYNTHETIC-PRIVATE-CAPTURE-FACT', Crypt::decryptString($r->response_cipher));
        $this->assertFalse($this->settle($f)['credited']);
        Http::assertSentCount(4);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount(StripeDepositSettlementService::TABLE, 1);
        app(DepositCompletionService::class)->verifySettled($f['deposit']->fresh());
        $report = app(PaymentReconciliationService::class)->reconcilePayment($f['payment']->id);
        $this->assertSame('matched', $report->status->value);
        $this->assertSame('100.00', (string) $report->observed_amount);
    }

    public static function mismatches(): array
    {
        return [['session.payment_status', 'unpaid'], ['session.status', 'open'], ['session.mode', 'subscription'],
            ['session.amount_total', 9999], ['session.currency', 'usd'], ['session.livemode', true],
            ['session.metadata.checkout_attempt', 'OTHER'], ['session.metadata.user_id', 'OTHER'],
            ['session.client_reference_id', 'OTHER'], ['session.id', 'cs_test_OTHERREFERENCE'],
            ['intent.id', 'pi_OTHERREFERENCE'], ['intent.status', 'processing'], ['intent.amount', 9999],
            ['intent.amount_received', 9999], ['intent.amount_received', 10000.0], ['intent.currency', 'usd'],
            ['intent.livemode', true], ['intent.amount_capturable', 1], ['intent.transfer_data', ['destination' => 'OTHER']],
            ['charge.id', 'ch_OTHERREFERENCE'], ['charge.payment_intent', 'pi_OTHERREFERENCE'],
            ['charge.status', 'pending'], ['charge.paid', false], ['charge.captured', false],
            ['charge.amount_captured', 9999], ['charge.amount_refunded', 1], ['charge.refunded', true],
            ['refunds.data', [['id' => 're_SYNTHETIC', 'status' => 'pending']]], ['refunds.has_more', true], ['charge.disputed', true], ['charge.currency', 'usd'], ['charge.livemode', true], ['charge.review', 'prv_OTHER']];
    }

    #[DataProvider('mismatches')]
    public function test_partial_uncaptured_refunded_disputed_or_misbound_facts_never_credit(string $field, mixed $value): void
    {
        $f = $this->fixture();
        $p = $this->proof($f);
        data_set($p, $field, $value);
        $this->fakeProof($p);
        $this->refuse(fn () => $this->settle($f), 'stripe_capture_inconclusive');
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertSame('pending', $f['deposit']->fresh()->status->value);
        $this->assertDatabaseCount(StripeDepositSettlementService::TABLE, 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_disabled_feature_and_missing_duty_refuse_before_http(): void
    {
        $f = $this->fixture();
        Http::fake();
        config(['payment.deposit_settlement.stripe.enabled' => false]);
        $this->refuse(fn () => $this->settle($f), 'stripe_settlement_unproven');
        config(['payment.deposit_settlement.stripe.enabled' => true]);
        $f['actor']->revokePermissionTo(DepositSettlementAccess::RECONCILE);
        $this->expectException(AuthorizationException::class);
        try {
            $this->settle($f);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_staff_revocation_during_get_prevents_receipt_and_credit(): void
    {
        $f = $this->fixture();
        $this->fakeProof($this->proof($f), fn () => $f['actor']->revokePermissionTo(DepositSettlementAccess::RECONCILE));
        $this->expectException(AuthorizationException::class);
        try {
            $this->settle($f);
        } finally {
            $this->assertDatabaseCount(StripeDepositSettlementService::TABLE, 0);
            $this->assertDatabaseCount('financial_transactions', 0);
        }
    }

    public function test_original_config_drift_during_get_cannot_credit_a_different_account(): void
    {
        $f = $this->fixture();
        $this->fakeProof($this->proof($f), fn () => config(['payment.gateways.stripe.key' => 'SYNTHETIC-OTHER-ACCOUNT']));
        $this->refuse(fn () => $this->settle($f), 'checkout_original_conflict');
        $this->assertDatabaseCount(StripeDepositSettlementService::TABLE, 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_late_credit_failure_keeps_independent_receipt_for_sql_only_retry(): void
    {
        $f = $this->fixture();
        $this->fakeProof($this->proof($f));
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'payments')) {
                $armed = false;
                throw new \RuntimeException('SYNTHETIC late settlement failure');
            }
        });
        try {
            $this->settle($f);
            $this->fail('Fault did not execute.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SYNTHETIC late settlement failure', $e->getMessage());
        }
        $this->assertDatabaseCount(StripeDepositSettlementService::TABLE, 1);
        $this->assertNull(DB::table(StripeDepositSettlementService::TABLE)->sole()->applied_at);
        $this->assertSame('pending', $f['payment']->fresh()->status->value);
        $this->assertSame('pending', $f['deposit']->fresh()->status->value);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertTrue($this->settle($f)['credited']);
        Http::assertSentCount(4);
    }

    public function test_current_player_admission_can_hold_verified_capture_without_losing_custody(): void
    {
        $f = $this->fixture();
        $this->fakeProof($this->proof($f), fn () => DB::table('users')->where('id', $f['user']->id)->update(['status' => 'banned']));
        $this->refuse(fn () => $this->settle($f), 'deposit_player_unavailable');
        $this->assertDatabaseCount(StripeDepositSettlementService::TABLE, 1);
        $this->assertNull(DB::table(StripeDepositSettlementService::TABLE)->sole()->applied_at);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame('pending', $f['payment']->fresh()->status->value);
        DB::table('users')->where('id', $f['user']->id)->update(['status' => 'active']);
        $this->assertTrue($this->settle($f)['credited']);
        Http::assertSentCount(4);
    }

    public function test_old_held_receipt_requires_fresh_gets_and_preserves_previous_evidence(): void
    {
        $f = $this->fixture();
        $p = $this->proof($f);
        $this->fakeProof($p, fn () => DB::table('users')->where('id', $f['user']->id)->update(['status' => 'banned']));
        $this->refuse(fn () => $this->settle($f), 'deposit_player_unavailable');
        $old = DB::table(StripeDepositSettlementService::TABLE)->sole();
        $this->travel(301)->seconds();
        DB::table('users')->where('id', $f['user']->id)->update(['status' => 'active']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeProof($p);
        $this->assertTrue($this->settle($f)['credited']);
        Http::assertSentCount(4);
        $this->assertDatabaseCount(StripeDepositSettlementService::TABLE, 2);
        $this->assertSame($old->response_cipher, DB::table(StripeDepositSettlementService::TABLE)->where('id', $old->id)->value('response_cipher'));
        $this->assertDatabaseCount('stripe_deposit_capture_owners', 1);
    }

    public function test_one_intent_and_charge_cannot_fund_two_original_sessions(): void
    {
        $f = $this->fixture();
        $this->fakeProof($this->proof($f));
        $this->settle($f);
        $g = $this->fixture('SECOND');
        $this->fakeProof($this->proof($g)); // Different original metadata/session; same PI and Charge must still refuse.
        $this->refuse(fn () => $this->settle($g), 'stripe_settlement_unproven');
        $this->assertSame('0.00', $g['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('stripe_deposit_capture_owners', 1);
    }

    public function test_receipt_cipher_or_required_marker_tampering_cannot_hide_behind_paid_labels(): void
    {
        $f = $this->fixture();
        $this->fakeProof($this->proof($f));
        $this->settle($f);
        DB::table(StripeDepositSettlementService::TABLE)->update(['response_hash' => str_repeat('0', 64)]);
        $this->refuse(fn () => $this->settle($f), 'stripe_settlement_unproven');
        $this->refuse(fn () => app(PaymentReconciliationService::class)->reconcilePayment($f['payment']->id), 'stripe_settlement_unproven');
        Http::assertSentCount(4);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_removed_attempt_receipt_marker_refuses_even_with_original_applied_receipt(): void
    {
        $f = $this->fixture();
        $this->fakeProof($this->proof($f));
        $this->settle($f);
        DB::table(DepositCheckoutService::TABLE)->update(['settlement_receipt_uuid' => null]);
        $this->refuse(fn () => $this->settle($f), 'stripe_settlement_unproven');
        Http::assertSentCount(4);
    }

    public function test_real_admin_spec_uses_authenticated_actor_and_domain_credit_lane(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['actor']);
        $this->fakeProof($this->proof($f));
        $spec = collect(DepositDecisionActions::specs())->firstWhere('name', 'reconcileStripeSettlement');
        $this->assertTrue($spec['confirm']);
        $this->assertTrue(($spec['visible'])($f['deposit']));
        ($spec['action'])($f['deposit']);
        $this->assertSame('confirmed', $f['deposit']->fresh()->status->value);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public static function staleStaff(): array
    {
        return [['none'], ['banned'], ['deleted'], ['no_role']];
    }

    #[DataProvider('staleStaff')]
    public function test_fresh_active_staff_role_is_required_not_just_old_permission(string $kind): void
    {
        $f = $this->fixture();
        if ($kind === 'none') {
            $f['actor'] = null;
        } elseif ($kind === 'no_role') {
            $f['actor']->removeRole('admin');
        } else {
            DB::table('users')->where('id', $f['actor']->id)->update($kind === 'banned' ? ['status' => 'banned'] : ['deleted_at' => now()]);
        }
        Http::fake();
        $this->expectException(AuthorizationException::class);
        try {
            $this->settle($f);
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('financial_transactions', 0);
        }
    }

    public function test_stale_held_capture_cannot_credit_after_a_later_pending_refund_is_observed(): void
    {
        $f = $this->fixture();
        $p = $this->proof($f);
        $this->fakeProof($p, fn () => DB::table('users')->where('id', $f['user']->id)->update(['status' => 'banned']));
        $this->refuse(fn () => $this->settle($f), 'deposit_player_unavailable');
        $old = DB::table(StripeDepositSettlementService::TABLE)->sole();
        $this->travel(301)->seconds();
        DB::table('users')->where('id', $f['user']->id)->update(['status' => 'active']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $p['refunds']['data'] = [['id' => 're_SYNTHETIC', 'status' => 'pending']];
        $this->fakeProof($p);
        $this->refuse(fn () => $this->settle($f), 'stripe_capture_inconclusive');
        Http::assertSentCount(4);
        $this->assertSame($old->response_cipher, DB::table(StripeDepositSettlementService::TABLE)->sole()->response_cipher);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
    }

    public function test_lost_authenticated_get_response_is_inconclusive_not_credit_or_failure_release(): void
    {
        $f = $this->fixture();
        $calls = 0;
        Http::fake(['api.stripe.com/*' => function () use (&$calls) {
            $calls++;
            throw new ConnectionException('SYNTHETIC lost response');
        }]);
        $this->refuse(fn () => $this->settle($f), 'stripe_capture_inconclusive');
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount(StripeDepositSettlementService::TABLE, 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame('pending', $f['deposit']->fresh()->status->value);
    }

    public function test_applied_webhook_observation_is_not_masked_by_historical_capture_receipt(): void
    {
        $f = $this->fixture();
        $this->fakeProof($this->proof($f));
        $this->settle($f);
        // A synthetic persisted Applied envelope fixture, not a claim that this test verified a real signature.
        DB::table('payment_webhooks')->insert(['webhook_key' => hash('sha256', 'SYNTHETIC-LATE-EVENT'),
            'provider' => 'stripe', 'event_id' => 'SYNTHETIC-LATE-EVENT', 'event_type' => 'SYNTHETIC',
            'payload' => json_encode(['reference' => $f['payment']->gateway_reference, 'amount' => '100.00', 'status' => 'refunded']),
            'payload_fingerprint' => hash('sha256', 'SYNTHETIC-LATE-PAYLOAD'), 'status' => 'applied',
            'received_at' => now(), 'verified_at' => now(), 'applied_at' => now()]);
        $report = app(PaymentReconciliationService::class)->reconcilePayment($f['payment']->id);
        $this->assertSame('drift_detected', $report->status->value);
        $this->assertSame('reversed', $report->observed_status);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public static function linkedAppliedEvents(): array
    {
        return [['charge_refund'], ['pending_refund'], ['unrelated_charge'], ['paid_intent']];
    }

    #[DataProvider('linkedAppliedEvents')]
    public function test_real_charge_or_refund_reference_is_not_hidden_by_session_capture_evidence(string $kind): void
    {
        $f = $this->fixture();
        $this->fakeProof($this->proof($f));
        $this->settle($f);
        $facts = $kind === 'pending_refund'
            ? ['object' => 'refund', 'id' => 're_SYNTHETIC_LATER', 'charge' => 'ch_SYNTHETICFIRST', 'amount' => 2500, 'currency' => 'thb', 'status' => 'pending']
            : ['object' => 'charge', 'id' => $kind === 'unrelated_charge' ? 'ch_OTHER_UNRELATED' : 'ch_SYNTHETICFIRST',
                'amount' => 10000, 'amount_refunded' => 2500, 'refunded' => false, 'currency' => 'thb', 'status' => 'succeeded'];
        if ($kind === 'paid_intent') {
            $facts = ['object' => 'payment_intent', 'id' => 'pi_SYNTHETICFIRST', 'amount' => 10000, 'currency' => 'thb', 'status' => 'succeeded'];
        }
        DB::table('payment_webhooks')->insert(['webhook_key' => hash('sha256', 'SYNTHETIC-CHAIN-'.$kind),
            'provider' => 'stripe', 'event_id' => 'SYNTHETIC-CHAIN-'.$kind, 'event_type' => $kind === 'pending_refund' ? 'refund.created' : ($kind === 'paid_intent' ? 'payment_intent.succeeded' : 'charge.refunded'),
            'payload' => json_encode(['data' => ['object' => $facts]]), 'payload_fingerprint' => hash('sha256', 'SYNTHETIC-CHAIN-PAYLOAD-'.$kind),
            'status' => 'applied', 'received_at' => now(), 'verified_at' => now(), 'applied_at' => now()]);
        $report = app(PaymentReconciliationService::class)->reconcilePayment($f['payment']->id);
        if (in_array($kind, ['unrelated_charge', 'paid_intent'], true)) {
            $this->assertSame('matched', $report->status->value);
            $this->assertSame('100.00', (string) $report->observed_amount);
        } else {
            $this->assertSame('drift_detected', $report->status->value);
            $this->assertNull($report->observed_amount, 'Never misreport Stripe refund minor units as original paid major units.');
            $this->assertNull($report->observed_status, 'A refund follow-up is not an invented definitive negative outcome.');
            $this->assertStringContainsString('provider-followup', implode(' ', $report->drift_lines));
        }
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertSame((string) $f['deposit']->net_amount, $f['wallet']->fresh()->balance);
    }

    public function test_ambient_transaction_and_failed_get_never_turn_into_money_authority(): void
    {
        $f = $this->fixture();
        Http::fake(['api.stripe.com/*' => Http::response([], 503)]);
        DB::beginTransaction();
        try {
            $this->refuse(fn () => $this->settle($f), 'checkout_requires_top_level');
            Http::assertNothingSent();
        } finally {
            DB::rollBack();
        }
        $this->refuse(fn () => $this->settle($f), 'stripe_capture_inconclusive');
        Http::assertSentCount(1);
        $this->assertDatabaseCount(StripeDepositSettlementService::TABLE, 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }
}
