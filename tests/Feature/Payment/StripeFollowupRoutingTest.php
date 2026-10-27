<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Filament\Resources\StripeDepositFollowupResource;
use App\Models\StripeDepositFollowup;
use App\Models\StripeFollowupRouteDecision;
use App\Models\User;
use App\Services\Finance\Money;
use App\Services\Payment\DepositCheckoutService;
use App\Services\Payment\DepositReviewAccess;
use App\Services\Payment\PaymentInitiationService;
use App\Services\Payment\StripeDepositFollowupInbox;
use App\Services\Payment\StripeDepositReviewService;
use App\Services\Payment\StripeDepositSettlementService;
use App\Services\Payment\StripeFollowupRoutingService;
use App\Services\Payment\WithdrawalProviderAccountService;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StripeDepositReviewFixture;

final class StripeFollowupRoutingTest extends PaymentTestCase
{
    use StripeDepositReviewFixture;

    private function fixture(): array
    {
        $f = $this->reviewFixture();
        config(['payment.deposit_followup_routing.stripe.enabled' => true]);
        $f['inbox_id'] = $this->receiveFollowup()['inbox_id'];

        return $f;
    }

    private function route(array $f): array
    {
        return app(StripeFollowupRoutingService::class)->route($f['inbox_id'], $f['actor']);
    }

    public function test_real_resource_action_discovers_original_and_opens_case_without_financial_mutation(): void
    {
        $f = $this->fixture();
        $paper = $this->moneyPaper();
        $this->actingAs($f['actor']);
        $this->assertTrue(StripeDepositFollowupResource::canViewAny());
        $record = StripeDepositFollowupResource::getEloquentQuery()->sole();
        StripeDepositFollowupResource::routeRecord($record);
        Notification::assertNotified('Original deposit review refreshed');
        $decision = DB::table(StripeFollowupRoutingService::TABLE)->sole();
        $this->assertSame((int) $f['deposit']->id, (int) $decision->deposit_id);
        $this->assertSame('routed', $decision->state);
        $this->assertSame('open', DB::table(StripeDepositReviewService::CASES)->sole()->state);
        $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 1);
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 0);
        $this->assertDatabaseCount('payment_webhooks', 0);
        $this->assertTrue($this->route($f)['replayed']);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 1);
        $this->assertSame($paper, $this->moneyPaper());
        Http::assertNothingSent();
    }

    public function test_actual_global_backlog_page_is_reachable_without_a_known_deposit_or_private_payload(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['actor']);
        $this->get('/admin/stripe-deposit-followups')->assertOk()->assertSee('Stripe follow-up inbox')
            ->assertSee('evt_SYNTHETICREVIEW001')->assertDontSee('SYNTHETIC-PRIVATE-FOLLOWUP');
        $this->assertStringNotContainsString('response_cipher', StripeDepositFollowup::query()->sole()->toJson());
        $this->assertFalse(StripeDepositFollowupResource::canCreate());
        $this->assertFalse(StripeDepositFollowupResource::canDeleteAny());
        $this->assertFalse(StripeDepositFollowupResource::canEdit(StripeDepositFollowup::query()->sole()));
    }

    public function test_new_event_routes_same_original_and_opens_new_version_without_acknowledgement(): void
    {
        $f = $this->fixture();
        $first = $this->route($f);
        app(StripeDepositReviewService::class)->acknowledge($f['deposit'], $f['actor'], $first['case_uuid'], $first['case_version'], $first['evidence_hash'], 'Reviewed first signal; escalation remains open.');
        $cipher = DB::table(StripeFollowupRoutingService::TABLE)->sole()->response_cipher;
        $f['inbox_id'] = $this->receiveFollowup($this->followupEvent('charge.dispute.created', 'evt_SYNTHETICROUTINGSECOND'))['inbox_id'];
        $second = $this->route($f);
        $this->assertSame($first['case_uuid'], $second['case_uuid']);
        $this->assertSame(2, $second['case_version']);
        $this->assertSame('open', $second['case']['state']);
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 1);
        $this->assertSame($cipher, DB::table(StripeFollowupRoutingService::TABLE)->orderBy('id')->first()->response_cipher);
    }

    public function test_unmatched_receipt_is_retained_then_retry_can_route_after_capture_publication(): void
    {
        $f = $this->fixture();
        $receipt = DB::table(StripeDepositSettlementService::TABLE)->sole();
        DB::table(StripeDepositSettlementService::TABLE)->update(['applied_at' => null]);
        $first = $this->route($f);
        $this->assertSame('unmatched', $first['state']);
        $this->assertNull($first['deposit_id']);
        $this->assertNull($first['case']);
        $old = DB::table(StripeFollowupRoutingService::TABLE)->sole();
        DB::table(StripeDepositSettlementService::TABLE)->update(['applied_at' => $receipt->applied_at]);
        $next = $this->route($f);
        $this->assertSame('routed', $next['state']);
        $this->assertFalse($next['replayed']);
        $history = app(StripeFollowupRoutingService::class)->history($f['inbox_id'], $f['actor']);
        $this->assertSame(['unmatched', 'routed'], array_column($history, 'state'));
        $this->assertSame($old->response_cipher, DB::table(StripeFollowupRoutingService::TABLE)->where('id', $old->id)->value('response_cipher'));
    }

    public function test_signed_metadata_or_caller_chosen_deposit_is_not_a_routing_selector(): void
    {
        $f = $this->fixture();
        $event = $this->followupEvent('refund.created', 'evt_SYNTHETICNOREALREF');
        $event['data']['object'] = ['object' => 'refund', 'id' => 're_UNRELATED1234',
            'metadata' => ['deposit_id' => (string) $f['deposit']->id, 'charge' => 'ch_SYNTHETICREVIEW123']];
        $f['inbox_id'] = $this->receiveFollowup($event)['inbox_id'];
        $out = $this->route($f);
        $this->assertSame('unmatched', $out['state']);
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 0);
    }

    public static function badFacts(): array
    {
        return [['charge', 'ch_WRONG123456789'], ['currency', 'usd'], ['livemode', true], ['charge', ['id' => 'ch_SYNTHETICREVIEW123']]];
    }

    #[DataProvider('badFacts')]
    public function test_candidate_match_is_not_authority_when_original_linked_facts_conflict(string $field, mixed $value): void
    {
        $f = $this->fixture();
        $event = $this->followupEvent('refund.created', 'evt_SYNTHETICCONTRADICT');
        $event['data']['object'][$field] = $value;
        $f['inbox_id'] = $this->receiveFollowup($event)['inbox_id'];
        $this->reviewRefuses(fn () => $this->route($f));
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 0);
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 0);
    }

    public function test_wrong_current_account_or_config_cannot_be_viewed_or_routed_by_id(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['actor']);
        config(['payment.deposit_reference_accounts.stripe' => 'other-account']);
        $this->reviewRefuses(fn () => $this->route($f), 'stripe_routing_scope');
        $this->assertSame(0, StripeDepositFollowupResource::getEloquentQuery()->count());
        config(['payment.deposit_reference_accounts.stripe' => WithdrawalProviderAccountService::LEGACY,
            'app.url' => 'https://changed.invalid']);
        $this->reviewRefuses(fn () => $this->route($f), 'stripe_routing_scope');
    }

    public static function damage(): array
    {
        return [['inbox'], ['capture'], ['decision_hash'], ['decision_cipher'], ['decision_target']];
    }

    #[DataProvider('damage')]
    public function test_custody_damage_never_becomes_routing_or_replay_authority(string $kind): void
    {
        $f = $this->fixture();
        if ($kind === 'inbox') {
            DB::table(StripeDepositFollowupInbox::TABLE)->update(['response_hash' => str_repeat('0', 64)]);
        } elseif ($kind === 'capture') {
            DB::table(StripeDepositSettlementService::TABLE)->update(['response_hash' => str_repeat('0', 64)]);
        } else {
            $this->route($f);
            DB::table(StripeFollowupRoutingService::TABLE)->update(match ($kind) {
                'decision_hash' => ['response_hash' => str_repeat('0', 64)],
                'decision_cipher' => ['response_cipher' => 'broken'], default => ['deposit_id' => null],
            });
        }
        $code = match ($kind) {
            'inbox' => 'stripe_followup_unproven', 'capture' => 'stripe_settlement_unproven', default => 'stripe_routing_unproven'
        };
        $this->reviewRefuses(fn () => $this->route($f), $code);
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 0);
    }

    public static function staleStaff(): array
    {
        return [['none'], ['banned'], ['deleted'], ['role'], ['permission']];
    }

    #[DataProvider('staleStaff')]
    public function test_current_staff_not_old_model_permission_is_required(string $kind): void
    {
        $f = $this->fixture();
        if ($kind === 'none') {
            $f['actor'] = null;
        } elseif ($kind === 'role') {
            $f['actor']->removeRole('admin');
        } elseif ($kind === 'permission') {
            $f['actor']->revokePermissionTo(DepositReviewAccess::REVIEW);
        } else {
            DB::table('users')->where('id', $f['actor']->id)->update($kind === 'banned' ? ['status' => 'banned'] : ['deleted_at' => now()]);
        }
        try {
            $this->route($f);
            $this->fail('Stale actor accepted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 0);
        }
    }

    public function test_permission_is_rechecked_after_discovery_and_no_partial_case_is_published(): void
    {
        $f = $this->fixture();
        $armed = true;
        DB::listen(function ($query) use (&$armed, $f): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, StripeDepositFollowupInbox::TABLE)) {
                $armed = false;
                DB::table('users')->where('id', $f['actor']->id)->update(['status' => 'banned']);
            }
        });
        try {
            $this->route($f);
            $this->fail('Revoked staff accepted.');
        } catch (AuthorizationException) {
            $this->assertFalse($armed);
            $this->assertDatabaseCount(StripeDepositReviewService::CASES, 0);
        }
    }

    public function test_batch_reports_independent_success_and_held_scope_without_false_atomicity(): void
    {
        $f = $this->fixture();
        $out = app(StripeFollowupRoutingService::class)->routeBatch([$f['inbox_id'], 999999999], $f['actor']);
        $this->assertSame(['routed', 'held'], array_column($out, 'state'));
        $this->assertSame('stripe_routing_scope', $out[1]['code']);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 1);
    }

    public static function badBatches(): array
    {
        return [[[]], [[1, 1]], [['1']], [[0]], [range(1, 26)]];
    }

    #[DataProvider('badBatches')]
    public function test_batch_bounds_and_strict_identifiers_are_not_silently_truncated(array $ids): void
    {
        $f = $this->fixture();
        $this->reviewRefuses(fn () => app(StripeFollowupRoutingService::class)->routeBatch($ids, $f['actor']), 'stripe_routing_batch_input');
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 0);
    }

    public function test_default_off_top_level_and_read_only_model_boundaries(): void
    {
        $f = $this->fixture();
        $this->assertFalse((require base_path('config/payment.php'))['deposit_followup_routing']['stripe']['enabled']);
        config(['payment.deposit_followup_routing.stripe.enabled' => false]);
        $this->reviewRefuses(fn () => $this->route($f), 'stripe_routing_disabled');
        config(['payment.deposit_followup_routing.stripe.enabled' => true]);
        DB::beginTransaction();
        try {
            $this->reviewRefuses(fn () => $this->route($f), 'checkout_requires_top_level');
        } finally {
            DB::rollBack();
        }
        foreach (['save', 'delete'] as $method) {
            try {
                StripeDepositFollowup::query()->sole()->{$method}();
                $this->fail('Read-only inbox model mutated.');
            } catch (\LogicException) {
                $this->assertDatabaseCount(StripeDepositFollowupInbox::TABLE, 1);
            }
        }
        $this->route($f);
        try {
            StripeFollowupRouteDecision::query()->sole()->delete();
            $this->fail('Append-only route model deleted.');
        } catch (\LogicException) {
            $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 1);
        }
    }

    public function test_decision_write_failure_rolls_back_case_but_not_independent_signed_inbox(): void
    {
        $f = $this->fixture();
        $paper = $this->moneyPaper();
        $armed = true;
        DB::listen(function ($q) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($q->sql), 'insert') && str_contains($q->sql, StripeFollowupRoutingService::TABLE)) {
                $armed = false;
                throw new \RuntimeException('SYNTHETIC route decision fault');
            }
        });
        try {
            $this->route($f);
            $this->fail('Fault did not execute.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SYNTHETIC route decision fault', $e->getMessage());
        }
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 0);
        $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 0);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 0);
        $this->assertDatabaseCount(StripeDepositFollowupInbox::TABLE, 1);
        $this->assertSame($paper, $this->moneyPaper());
    }

    public function test_replay_by_another_current_staff_preserves_original_decision_actor_and_ciphertext(): void
    {
        $f = $this->fixture();
        $first = $this->route($f);
        $cipher = DB::table(StripeFollowupRoutingService::TABLE)->sole()->response_cipher;
        $actor = User::factory()->create();
        $actor->assignRole('admin');
        $actor->givePermissionTo(DepositReviewAccess::REVIEW);
        $f['actor'] = $actor;
        $next = $this->route($f);
        $this->assertTrue($next['replayed']);
        $this->assertSame($first['actor_id'], $next['actor_id']);
        $this->assertSame($cipher, DB::table(StripeFollowupRoutingService::TABLE)->sole()->response_cipher);
        $this->assertStringNotContainsString('response_cipher', StripeFollowupRouteDecision::query()->sole()->toJson());
    }

    public function test_two_real_applied_originals_with_crossed_charge_intent_are_held_as_ambiguous(): void
    {
        $f = $this->fixture();
        $proof = json_decode(Crypt::decryptString(DB::table(StripeDepositSettlementService::TABLE)->sole()->response_cipher), true)['proof'];
        $second = $this->createPlayer('0.00');
        config(['payment.deposit_settlement.stripe.enabled' => true]);
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_SYNTHETICSECOND123', 'url' => 'https://checkout.stripe.com/c/pay/SYNTHETIC'])]);
        $out = app(PaymentInitiationService::class)->initiateDeposit($second['wallet'],
            Money::of('100.00', Currency::THB), PaymentMethod::Stripe, 'synthetic-routing-second-original');
        $a = DB::table(DepositCheckoutService::TABLE)->where('deposit_id', $out['deposit']->id)->sole();
        $proof['session']['id'] = $out['payment']->gateway_reference;
        $proof['session']['payment_intent'] = $proof['intent']['id'] = $proof['charge']['payment_intent'] = 'pi_SYNTHETICSECOND123';
        $proof['intent']['latest_charge'] = $proof['charge']['id'] = 'ch_SYNTHETICSECOND123';
        $proof['session']['client_reference_id'] = $out['deposit']->reference_number;
        $proof['session']['metadata'] = ['checkout_attempt' => $a->uuid, 'deposit_id' => (string) $out['deposit']->id,
            'deposit_reference' => $out['deposit']->reference_number, 'user_id' => (string) $second['user']->id, 'wallet_id' => (string) $second['wallet']->id];
        Http::swap(new Factory);
        Http::fake(['api.stripe.com/*' => function ($request) use ($proof) {
            return Http::response(str_contains($request->url(), '/refunds?') ? $proof['refunds'] : (str_contains($request->url(), '/payment_intents/') ? $proof['intent'] : (str_contains($request->url(), '/charges/') ? $proof['charge'] : $proof['session'])));
        }]);
        $this->assertTrue(app(StripeDepositSettlementService::class)->reconcile($out['deposit'], $f['actor'])['credited']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $event = $this->followupEvent('refund.created', 'evt_SYNTHETICCROSSEDORIGINAL');
        $event['data']['object']['payment_intent'] = 'pi_SYNTHETICSECOND123';
        $f['inbox_id'] = $this->receiveFollowup($event)['inbox_id'];
        $paper = $this->moneyPaper();
        $this->reviewRefuses(fn () => $this->route($f), 'stripe_routing_ambiguous');
        $this->assertDatabaseCount(StripeDepositSettlementService::TABLE, 2);
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 0);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 0);
        $this->assertSame($paper, $this->moneyPaper());
        Http::assertNothingSent();
    }

    public function test_candidate_discovery_is_revalidated_and_never_switches_wallet_under_an_inbox_lock(): void
    {
        $f = $this->fixture();
        $armed = true;
        DB::listen(function ($q) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($q->sql), 'select') && str_contains(str_replace(['"', '`'], '', $q->sql), ' as r') && str_contains(str_replace(['"', '`'], '', $q->sql), 'a.deposit_id')) {
                $armed = false;
                DB::table(StripeDepositSettlementService::TABLE)->update(['applied_at' => null]);
            }
        });
        $this->reviewRefuses(fn () => $this->route($f), 'stripe_routing_stale');
        $this->assertFalse($armed);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 0);
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 0);
    }

    public function test_older_corrupt_decision_cannot_hide_behind_an_existing_success_replay(): void
    {
        $f = $this->fixture();
        $receipt = DB::table(StripeDepositSettlementService::TABLE)->sole();
        DB::table(StripeDepositSettlementService::TABLE)->update(['applied_at' => null]);
        $first = $this->route($f);
        DB::table(StripeDepositSettlementService::TABLE)->update(['applied_at' => $receipt->applied_at]);
        $this->route($f);
        DB::table(StripeFollowupRoutingService::TABLE)->where('id', $first['decision_id'])->update(['response_hash' => str_repeat('0', 64)]);
        $this->reviewRefuses(fn () => $this->route($f), 'stripe_routing_unproven');
        $this->reviewRefuses(fn () => app(StripeFollowupRoutingService::class)->history($f['inbox_id'], $f['actor']), 'stripe_routing_unproven');
    }

    public function test_lower_id_publication_is_visible_without_a_persisted_high_water_cursor(): void
    {
        $f = $this->fixture();
        $first = DB::table(StripeDepositFollowupInbox::TABLE)->sole();
        DB::table(StripeDepositFollowupInbox::TABLE)->where('id', $first->id)->delete();
        $new = $this->receiveFollowup($this->followupEvent('refund.updated', 'evt_SYNTHETICUPPERFIRST'));
        $f['inbox_id'] = $new['inbox_id'];
        $this->route($f);
        DB::table(StripeDepositFollowupInbox::TABLE)->insert((array) $first);
        $this->actingAs($f['actor']);
        $this->assertTrue(StripeDepositFollowupResource::getEloquentQuery()->doesntHave('latestRouting')->pluck('id')->contains($first->id));
        $f['inbox_id'] = (int) $first->id;
        $this->assertSame('routed', $this->route($f)['state']);
        $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 2);
    }

    public function test_coordinated_review_requires_a_root_and_its_savepoint_rolls_back_when_caller_catches(): void
    {
        $f = $this->fixture();
        $this->reviewRefuses(fn () => app(StripeDepositReviewService::class)->inspectInOriginalTransaction($f['deposit'], $f['actor']), 'stripe_review_transaction_required');
        $armed = true;
        DB::listen(function ($q) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($q->sql), 'insert') && str_contains($q->sql, StripeDepositReviewService::OBSERVATIONS)) {
                $armed = false;
                throw new \RuntimeException('SYNTHETIC nested review fault');
            }
        });
        DB::transaction(function () use ($f): void {
            try {
                app(StripeDepositReviewService::class)->inspectInOriginalTransaction($f['deposit'], $f['actor']);
                $this->fail('Fault not executed.');
            } catch (\RuntimeException $e) {
                $this->assertSame('SYNTHETIC nested review fault', $e->getMessage());
            }
        });
        $this->assertFalse($armed);
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 0);
        $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 0);
    }

    public function test_previously_proven_original_cannot_be_downgraded_to_unmatched(): void
    {
        $f = $this->fixture();
        $this->route($f);
        DB::table(StripeDepositSettlementService::TABLE)->update(['applied_at' => null]);
        $this->reviewRefuses(fn () => $this->route($f), 'stripe_routing_unproven');
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 1);
        $this->assertSame('routed', DB::table(StripeFollowupRoutingService::TABLE)->sole()->state);
    }
}
