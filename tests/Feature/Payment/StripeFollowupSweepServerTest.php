<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Services\Payment\StripeDepositFollowupInbox;
use App\Services\Payment\StripeDepositReviewService;
use App\Services\Payment\StripeFollowupRoutingService;
use App\Services\Payment\StripeFollowupSweepService as Sweep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\RunsServerFinancialRaces;
use Tests\Support\StripeDepositReviewFixture;

final class StripeFollowupSweepServerTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;
    use StripeDepositReviewFixture;

    private function fixture(): array
    {
        $this->requireServerRace();
        $f = $this->reviewFixture();
        config(['payment.deposit_followup_routing.stripe.enabled' => true, 'payment.deposit_followup_sweep.stripe.enabled' => true]);
        $f['inbox_id'] = $this->receiveFollowup()['inbox_id'];
        app(Sweep::class)->enroll($f['actor']);

        return $f;
    }

    public function test_two_genuine_work_row_waiters_grant_one_live_lease(): void
    {
        $f = $this->fixture();
        $paper = $this->moneyPaper();
        $command = ['action' => 'stripe_sweep_claim', 'actor_id' => $f['actor']->id];
        $out = $this->serverRace([$command, $command], Sweep::WORK, DB::table(Sweep::WORK)->sole()->id);
        $states = array_column($out, 'outcome');
        sort($states);
        $this->assertSame(['claimed', 'empty'], $states, json_encode($out));
        $row = DB::table(Sweep::WORK)->sole();
        $this->assertSame('leased', $row->state);
        $this->assertSame(1, (int) $row->generation);
        $this->assertDatabaseCount(Sweep::EVENTS, 2);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 0);
        $this->assertSame($paper, $this->moneyPaper());
    }

    public function test_two_genuine_held_retry_waiters_publish_one_requeue_without_resetting_generation(): void
    {
        $f = $this->fixture();
        $source = DB::table(StripeDepositFollowupInbox::TABLE)->sole();
        DB::table(StripeDepositFollowupInbox::TABLE)->update(['response_cipher' => 'SYNTHETIC corrupt source']);
        $this->assertSame('held', app(Sweep::class)->claimNext($f['actor'])['state']);
        DB::table(StripeDepositFollowupInbox::TABLE)->update(['response_cipher' => $source->response_cipher]);
        $command = ['action' => 'stripe_sweep_retry', 'actor_id' => $f['actor']->id, 'inbox_id' => $f['inbox_id']];
        $out = $this->serverRace([$command, $command], Sweep::WORK, DB::table(Sweep::WORK)->sole()->id);
        $states = array_column($out, 'outcome');
        sort($states);
        $this->assertSame(['refused', 'requeued'], $states, json_encode($out));
        $refused = array_values(array_filter($out, fn ($r) => $r['outcome'] === 'refused'));
        $this->assertSame('stripe_sweep_not_held', $refused[0]['code']);
        $this->assertNull($refused[0]['sqlstate']);
        $this->assertSame('pending', DB::table(Sweep::WORK)->sole()->state);
        $this->assertSame(0, (int) DB::table(Sweep::WORK)->sole()->generation);
        $this->assertDatabaseCount(Sweep::EVENTS, 3);
    }

    public function test_distinct_committed_leases_genuinely_wait_on_one_original_wallet_without_holding_work_locks(): void
    {
        $f = $this->fixture();
        $this->receiveFollowup($this->followupEvent('charge.dispute.created', 'evt_SYNTHETICSECONDSWEEP'));
        app(Sweep::class)->enroll($f['actor']);
        $a = app(Sweep::class)->claimNext($f['actor']);
        $b = app(Sweep::class)->claimNext($f['actor']);
        $this->assertNotSame($a['work_id'], $b['work_id']);
        $paper = $this->moneyPaper();
        $commands = array_map(fn ($lease) => ['action' => 'stripe_sweep_run', 'actor_id' => $f['actor']->id, 'lease' => $lease], [$a, $b]);
        $out = $this->serverRace($commands, 'wallets', $f['wallet']->id);
        $this->assertSame(['processed', 'processed'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame(['routed', 'routed'], array_column(array_column($out, 'work'), 'state'));
        $this->assertSame(['routed', 'routed'], DB::table(Sweep::WORK)->orderBy('id')->pluck('state')->all());
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 2);
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 1);
        $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 2);
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 0);
        $this->assertSame($paper, $this->moneyPaper());
        Http::assertNothingSent();
    }
}
