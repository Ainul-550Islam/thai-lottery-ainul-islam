<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Filament\Resources\StripeDepositFollowupResource\Pages\ListStripeDepositFollowups;
use App\Models\StripeDepositFollowup;
use App\Models\StripeFollowupSweepWork;
use App\Models\User;
use App\Services\Payment\DepositReviewAccess;
use App\Services\Payment\StripeDepositFollowupInbox;
use App\Services\Payment\StripeDepositReviewService;
use App\Services\Payment\StripeDepositSettlementService;
use App\Services\Payment\StripeFollowupRoutingService;
use App\Services\Payment\StripeFollowupSweepService as Sweep;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StripeDepositReviewFixture;

final class StripeFollowupSweepTest extends PaymentTestCase
{
    use StripeDepositReviewFixture;

    private function fixture(bool $matched = true): array
    {
        $f = $this->reviewFixture();
        config(['payment.deposit_followup_routing.stripe.enabled' => true, 'payment.deposit_followup_sweep.stripe.enabled' => true,
            'payment.deposit_followup_sweep.stripe.actor_id' => (string) $f['actor']->id]);
        $event = $this->followupEvent();
        if (! $matched) {
            $event['data']['object']['charge'] = 'ch_UNRELATEDWORK123';
            $event['data']['object']['payment_intent'] = 'pi_UNRELATEDWORK123';
        }
        $f['inbox_id'] = $this->receiveFollowup($event)['inbox_id'];

        return $f;
    }

    private function lease(array $f): array
    {
        app(Sweep::class)->enroll($f['actor']);

        return app(Sweep::class)->claimNext($f['actor']);
    }

    public function test_real_command_routes_signed_source_and_writes_protected_worker_events_without_money_change(): void
    {
        $f = $this->fixture();
        $paper = $this->moneyPaper();
        $this->artisan('stripe-followups:sweep')->assertSuccessful();
        $row = DB::table(Sweep::WORK)->sole();
        $this->assertSame('routed', $row->state);
        $this->assertSame(1, (int) $row->generation);
        $this->assertNull($row->lease_token);
        $this->assertSame(['enrolled', 'claimed', 'completed'], DB::table(Sweep::EVENTS)->orderBy('id')->pluck('kind')->all());
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 1);
        $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 1);
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 0);
        $this->assertSame(0, app(Sweep::class)->sweep($f['actor'])['processed']);
        $this->assertSame($paper, $this->moneyPaper());
        Http::assertNothingSent();
        $this->assertStringNotContainsString('lease_token', StripeFollowupSweepWork::query()->sole()->toJson());
    }

    public function test_unmatched_is_delayed_not_cleared_and_due_order_allows_new_work_before_its_retry(): void
    {
        $f = $this->fixture(false);
        $out = app(Sweep::class)->sweep($f['actor']);
        $this->assertSame('retry', $out['results'][0]['state']);
        $row = DB::table(Sweep::WORK)->sole();
        $this->assertSame(now()->timestamp + 60, (int) $row->available_epoch);
        $this->assertSame('stripe_sweep_unmatched', $row->last_code);
        $this->assertNull(app(Sweep::class)->claimNext($f['actor']));
        $new = $this->receiveFollowup($this->followupEvent('refund.updated', 'evt_SYNTHETICNEWWORK123'));
        app(Sweep::class)->enroll($f['actor']);
        $lease = app(Sweep::class)->claimNext($f['actor']);
        $this->assertSame($new['inbox_id'], $lease['inbox_id']);
        $this->assertSame('routed', app(Sweep::class)->runLease($lease, $f['actor'])['state']);
        $this->travel(61)->seconds();
        $this->assertSame($f['inbox_id'], app(Sweep::class)->claimNext($f['actor'])['inbox_id']);
    }

    public function test_expired_abandoned_lease_recovers_with_new_generation_and_rejects_old_executor(): void
    {
        $f = $this->fixture();
        $lease = $this->lease($f);
        $this->assertNull(app(Sweep::class)->claimNext($f['actor']));
        $this->travel(Sweep::LEASE_SECONDS + 1)->seconds();
        $next = app(Sweep::class)->claimNext($f['actor']);
        $this->assertSame(2, $next['generation']);
        $this->assertNotSame($lease['token'], $next['token']);
        $old = app(Sweep::class)->runLease($lease, $f['actor']);
        $this->assertSame('lease_lost', $old['state']);
        $this->assertFalse($old['routing_committed']);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 0);
        $this->assertSame('routed', app(Sweep::class)->runLease($next, $f['actor'])['state']);
    }

    public function test_routing_can_commit_after_lease_expiry_without_a_false_queue_ack_and_recovers_by_proved_replay(): void
    {
        $f = $this->fixture();
        $lease = $this->lease($f);
        $armed = true;
        DB::listen(function ($q) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($q->sql), 'insert') && str_contains($q->sql, StripeFollowupRoutingService::TABLE)) {
                $armed = false;
                $this->travel(Sweep::LEASE_SECONDS + 1)->seconds();
            }
        });
        $out = app(Sweep::class)->runLease($lease, $f['actor']);
        $this->assertSame('lease_lost', $out['state']);
        $this->assertTrue($out['routing_committed']);
        $this->assertSame('leased', DB::table(Sweep::WORK)->sole()->state);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 1);
        $next = app(Sweep::class)->claimNext($f['actor']);
        $this->assertSame('routed', app(Sweep::class)->runLease($next, $f['actor'])['state']);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 1);
    }

    public function test_full_retry_budget_holds_then_real_ui_requeue_preserves_generation_history(): void
    {
        $f = $this->fixture(false);
        $paper = $this->moneyPaper();
        for ($i = 0; $i < Sweep::ATTEMPT_BUDGET; $i++) {
            app(Sweep::class)->sweep($f['actor'], 1);
            $this->travel(3601)->seconds();
        }
        $row = DB::table(Sweep::WORK)->sole();
        $this->assertSame('held', $row->state);
        $this->assertSame(100, (int) $row->generation);
        $this->assertNull(app(Sweep::class)->claimNext($f['actor']));
        $this->actingAs($f['actor']);
        Livewire::test(ListStripeDepositFollowups::class)->callTableAction('retrySweep', StripeDepositFollowup::query()->sole(), ['reason' => 'evidence_investigated'])
            ->assertHasNoTableActionErrors();
        Notification::assertNotified('Worker retry queued — no financial resolution');
        $after = DB::table(Sweep::WORK)->sole();
        $this->assertSame('pending', $after->state);
        $this->assertSame(100, (int) $after->generation);
        $this->assertSame(0, (int) $after->budget_used);
        $this->assertSame(101, app(Sweep::class)->claimNext($f['actor'])['generation']);
        $this->assertSame(203, DB::table(Sweep::EVENTS)->count());
        $this->assertSame($paper, $this->moneyPaper());
    }

    public function test_lower_id_published_after_later_work_is_not_skipped_by_enrollment(): void
    {
        $f = $this->fixture();
        $first = DB::table(StripeDepositFollowupInbox::TABLE)->sole();
        DB::table(StripeDepositFollowupInbox::TABLE)->delete();
        $this->receiveFollowup($this->followupEvent('refund.updated', 'evt_SYNTHETICLATERWORK123'));
        app(Sweep::class)->sweep($f['actor']);
        DB::table(StripeDepositFollowupInbox::TABLE)->insert((array) $first);
        $this->assertSame(1, app(Sweep::class)->enroll($f['actor']));
        $this->assertSame((int) $first->id, app(Sweep::class)->claimNext($f['actor'])['inbox_id']);
    }

    public static function damage(): array
    {
        return [['header'], ['event'], ['event_hash'], ['event_missing']];
    }

    #[DataProvider('damage')]
    public function test_unproved_worker_header_never_grants_a_routing_lease(string $kind): void
    {
        $f = $this->fixture();
        app(Sweep::class)->enroll($f['actor']);
        match ($kind) {
            'header' => DB::table(Sweep::WORK)->update(['budget_used' => 90]),
            'event' => DB::table(Sweep::EVENTS)->update(['response_cipher' => 'bad']),
            'event_hash' => DB::table(Sweep::EVENTS)->update(['response_hash' => str_repeat('0', 64)]),
            default => DB::table(Sweep::EVENTS)->delete(),
        };
        $this->reviewRefuses(fn () => app(Sweep::class)->claimNext($f['actor']), 'stripe_sweep_unproven');
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 0);
    }

    public function test_corrupt_signed_source_is_quarantined_without_blocking_other_due_work(): void
    {
        $f = $this->fixture();
        app(Sweep::class)->enroll($f['actor']);
        DB::table(StripeDepositFollowupInbox::TABLE)->where('id', $f['inbox_id'])->update(['response_cipher' => 'broken']);
        $out = app(Sweep::class)->claimNext($f['actor']);
        $this->assertSame('held', $out['state']);
        $this->assertSame('stripe_followup_unproven', $out['code']);
        $this->receiveFollowup($this->followupEvent('refund.updated', 'evt_SYNTHETICNEXTVALIDWORK'));
        app(Sweep::class)->enroll($f['actor']);
        $this->assertArrayHasKey('token', app(Sweep::class)->claimNext($f['actor']));
    }

    public function test_revoked_actor_cannot_execute_previously_committed_lease(): void
    {
        $f = $this->fixture();
        $lease = $this->lease($f);
        $f['actor']->revokePermissionTo(DepositReviewAccess::REVIEW);
        try {
            app(Sweep::class)->runLease($lease, $f['actor']);
            $this->fail('Revoked actor executed.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 0);
        }
    }

    public function test_default_off_bounds_caller_transactions_and_invalid_cli_are_refused(): void
    {
        $f = $this->fixture();
        $this->assertFalse((require base_path('config/payment.php'))['deposit_followup_sweep']['stripe']['enabled']);
        config(['payment.deposit_followup_sweep.stripe.enabled' => false]);
        $this->reviewRefuses(fn () => app(Sweep::class)->sweep($f['actor']), 'stripe_sweep_disabled');
        config(['payment.deposit_followup_sweep.stripe.enabled' => true]);
        foreach ([0, 26] as $limit) {
            $this->reviewRefuses(fn () => app(Sweep::class)->sweep($f['actor'], $limit), 'stripe_sweep_limit');
        }
        DB::beginTransaction();
        try {
            $this->reviewRefuses(fn () => app(Sweep::class)->sweep($f['actor']), 'checkout_requires_top_level');
        } finally {
            DB::rollBack();
        }
        $this->artisan('stripe-followups:sweep', ['--actor' => '01'])->assertExitCode(2);
        $this->artisan('stripe-followups:sweep', ['--limit' => '26'])->assertExitCode(2);
    }

    public function test_fault_between_work_and_event_is_atomic_and_enrollment_replays_without_duplicate_history(): void
    {
        $f = $this->fixture();
        $armed = true;
        DB::listen(function ($q) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($q->sql), 'insert') && str_contains($q->sql, Sweep::EVENTS)) {
                $armed = false;
                throw new \RuntimeException('SYNTHETIC sweep event fault');
            }
        });
        try {
            app(Sweep::class)->enroll($f['actor']);
            $this->fail('Fault missed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SYNTHETIC sweep event fault', $e->getMessage());
        }
        $this->assertDatabaseCount(Sweep::WORK, 0);
        $this->assertDatabaseCount(Sweep::EVENTS, 0);
        $this->assertSame(1, app(Sweep::class)->enroll($f['actor']));
        $this->assertSame(0, app(Sweep::class)->enroll($f['actor']));
        $this->assertDatabaseCount(Sweep::EVENTS, 1);
    }

    public function test_current_account_configuration_and_lease_actor_are_not_caller_overrides(): void
    {
        $f = $this->fixture();
        $lease = $this->lease($f);
        $other = User::factory()->create();
        $other->assignRole('admin');
        $other->givePermissionTo(DepositReviewAccess::REVIEW);
        $this->assertSame('lease_lost', app(Sweep::class)->runLease($lease, $other)['state']);
        config(['app.url' => 'https://other-config.invalid']);
        $this->assertSame(0, app(Sweep::class)->enroll($f['actor']));
        $this->reviewRefuses(fn () => app(Sweep::class)->runLease($lease, $f['actor']), 'stripe_sweep_unproven');
    }

    public function test_scheduler_has_named_opt_in_command_not_an_implicit_privileged_actor(): void
    {
        $this->fixture();
        $events = app(Schedule::class)->events();
        $events = array_values(array_filter($events, fn ($e) => $e->description === 'stripe-followup-routing-sweep'));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events[0]->expression);
        config(['payment.deposit_followup_sweep.stripe.enabled' => false]);
        $this->assertFalse($events[0]->filtersPass(app()));
    }

    public function test_completion_fault_keeps_committed_routing_and_recovers_without_duplicate_decision(): void
    {
        $f = $this->fixture();
        $lease = $this->lease($f);
        $armed = true;
        DB::listen(function ($q) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($q->sql), 'insert') && str_contains($q->sql, Sweep::EVENTS)) {
                $armed = false;
                throw new \RuntimeException('SYNTHETIC completion fault');
            }
        });
        try {
            app(Sweep::class)->runLease($lease, $f['actor']);
            $this->fail('Completion fault missed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SYNTHETIC completion fault', $e->getMessage());
        }
        $this->assertSame('leased', DB::table(Sweep::WORK)->sole()->state);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 1);
        $this->assertDatabaseCount(Sweep::EVENTS, 2);
        $this->travel(Sweep::LEASE_SECONDS + 1)->seconds();
        $next = app(Sweep::class)->claimNext($f['actor']);
        $this->assertSame('routed', app(Sweep::class)->runLease($next, $f['actor'])['state']);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 1);
    }

    public function test_enrollment_is_bounded_complete_rescan_not_silent_advance_past_lower_ids(): void
    {
        $f = $this->fixture();
        for ($i = 1; $i <= Sweep::ENROLL_LIMIT; $i++) {
            $this->receiveFollowup($this->followupEvent('refund.updated', 'evt_SYNTHETICBOUNDEDSWEEP'.$i));
        }
        $this->assertSame(100, app(Sweep::class)->enroll($f['actor']));
        $this->assertSame(1, app(Sweep::class)->enroll($f['actor']));
        $this->assertSame(0, app(Sweep::class)->enroll($f['actor']));
        $this->assertDatabaseCount(Sweep::WORK, 101);
        $this->assertDatabaseCount(Sweep::EVENTS, 101);
    }

    public function test_invalid_lease_shape_and_wrong_token_never_dispatch(): void
    {
        $f = $this->fixture();
        $lease = $this->lease($f);
        $bad = $lease;
        $bad['generation'] = '1';
        $this->reviewRefuses(fn () => app(Sweep::class)->runLease($bad, $f['actor']), 'stripe_sweep_lease');
        $lease['token'] = (string) Str::uuid();
        $this->assertSame('lease_lost', app(Sweep::class)->runLease($lease, $f['actor'])['state']);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 0);
        $this->reviewRefuses(fn () => app(Sweep::class)->retryHeld($f['inbox_id'], $f['actor'], 'invented'), 'stripe_sweep_reason');
        $this->reviewRefuses(fn () => app(Sweep::class)->retryHeld($f['inbox_id'], $f['actor'], 'operational_retry'), 'stripe_sweep_not_held');
    }

    public function test_expected_routing_proof_refusal_is_delayed_without_minting_a_success_receipt(): void
    {
        $f = $this->fixture();
        DB::table(StripeDepositSettlementService::TABLE)->update(['response_hash' => str_repeat('0', 64)]);
        $paper = $this->moneyPaper();
        $result = app(Sweep::class)->sweep($f['actor']);
        $this->assertSame('retry', $result['results'][0]['state']);
        $this->assertFalse($result['results'][0]['routing_committed']);
        $this->assertSame('stripe_settlement_unproven', $result['results'][0]['code']);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 0);
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 0);
        $this->assertSame($paper, $this->moneyPaper());
    }

    public function test_repeated_source_quarantine_after_explicit_requeue_does_not_reuse_a_generation_kind_slot(): void
    {
        $f = $this->fixture();
        app(Sweep::class)->enroll($f['actor']);
        $source = DB::table(StripeDepositFollowupInbox::TABLE)->sole();
        for ($i = 0; $i < 2; $i++) {
            DB::table(StripeDepositFollowupInbox::TABLE)->update(['response_cipher' => 'SYNTHETIC repeated damage']);
            $this->assertSame('held', app(Sweep::class)->claimNext($f['actor'])['state']);
            DB::table(StripeDepositFollowupInbox::TABLE)->update(['response_cipher' => $source->response_cipher]);
            app(Sweep::class)->retryHeld($f['inbox_id'], $f['actor'], 'operational_retry');
        }
        $this->assertSame(0, (int) DB::table(Sweep::WORK)->sole()->generation);
        $events = DB::table(Sweep::EVENTS)->orderBy('id')->get();
        $this->assertCount(5, $events);
        foreach ($events as $i => $event) {
            $this->assertSame($i === 0 ? null : (int) $events[$i - 1]->id, $event->previous_event_id === null ? null : (int) $event->previous_event_id);
        }
        $this->assertArrayHasKey('token', app(Sweep::class)->claimNext($f['actor']));
    }
}
