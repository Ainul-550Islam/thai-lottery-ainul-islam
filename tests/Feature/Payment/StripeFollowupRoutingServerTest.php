<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Services\Payment\StripeDepositFollowupInbox;
use App\Services\Payment\StripeDepositReviewService;
use App\Services\Payment\StripeFollowupRoutingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\RunsServerFinancialRaces;
use Tests\Support\StripeDepositReviewFixture;

final class StripeFollowupRoutingServerTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;
    use StripeDepositReviewFixture;

    private function command(array $f, int $inbox): array
    {
        return ['action' => 'stripe_followup_route', 'inbox_id' => $inbox, 'actor_id' => $f['actor']->id];
    }

    public function test_two_genuine_wallet_waiters_route_one_original_case_and_one_decision(): void
    {
        $this->requireServerRace();
        $f = $this->reviewFixture();
        $inbox = $this->receiveFollowup()['inbox_id'];
        $paper = $this->moneyPaper();
        $command = $this->command($f, $inbox);
        $out = $this->serverRace([$command, $command], 'wallets', $f['wallet']->id);
        $this->assertSame(['routed', 'routed'], array_column($out, 'outcome'), json_encode($out));
        $replay = array_column(array_column($out, 'routing'), 'replayed');
        sort($replay);
        $this->assertSame([false, true], $replay);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 1);
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 1);
        $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 1);
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 0);
        $this->assertSame($paper, $this->moneyPaper());
        Http::assertNothingSent();
    }

    public function test_two_genuine_inbox_waiters_record_one_unmatched_decision_without_claiming_resolution(): void
    {
        $this->requireServerRace();
        $f = $this->reviewFixture();
        $event = $this->followupEvent();
        $event['data']['object']['charge'] = 'ch_UNRELATEDORIGINAL123';
        $event['data']['object']['payment_intent'] = 'pi_UNRELATEDORIGINAL123';
        $inbox = $this->receiveFollowup($event)['inbox_id'];
        $paper = $this->moneyPaper();
        $command = $this->command($f, $inbox);
        $out = $this->serverRace([$command, $command], StripeDepositFollowupInbox::TABLE, $inbox);
        $this->assertSame(['routed', 'routed'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame(['unmatched', 'unmatched'], array_column(array_column($out, 'routing'), 'state'));
        $replay = array_column(array_column($out, 'routing'), 'replayed');
        sort($replay);
        $this->assertSame([false, true], $replay);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 1);
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 0);
        $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 0);
        $this->assertNull(DB::table(StripeFollowupRoutingService::TABLE)->sole()->deposit_id);
        $this->assertSame($paper, $this->moneyPaper());
    }

    public function test_routing_and_old_ack_genuine_wallet_waiters_preserve_one_new_open_version(): void
    {
        $this->requireServerRace();
        $f = $this->reviewFixture();
        $this->receiveFollowup();
        $case = app(StripeDepositReviewService::class)->inspect($f['deposit'], $f['actor']);
        $inbox = $this->receiveFollowup($this->followupEvent('charge.dispute.created', 'evt_SYNTHETICROUTEACKRACE'))['inbox_id'];
        $route = $this->command($f, $inbox);
        $ack = ['action' => 'stripe_review_ack', 'deposit_id' => $f['deposit']->id, 'actor_id' => $f['actor']->id,
            'note' => 'Synthetic old acknowledgement cannot close the newly observed event.'] + $case;
        $paper = $this->moneyPaper();
        $out = $this->serverRace([$route, $ack], 'wallets', $f['wallet']->id);
        $this->assertSame(['routed', 'refused'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame('stripe_review_stale', $out[1]['code']);
        $this->assertNull($out[1]['sqlstate']);
        $this->assertSame(2, $out[0]['routing']['case_version']);
        $this->assertDatabaseCount(StripeFollowupRoutingService::TABLE, 1);
        $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 2);
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 0);
        $this->assertSame('open', DB::table(StripeDepositReviewService::CASES)->sole()->state);
        $this->assertSame(2, (int) DB::table(StripeDepositReviewService::CASES)->sole()->version);
        $this->assertSame($paper, $this->moneyPaper());
    }
}
