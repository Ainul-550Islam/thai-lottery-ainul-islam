<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Services\Payment\StripeDepositReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\RunsServerFinancialRaces;
use Tests\Support\StripeDepositReviewFixture;

final class StripeDepositReviewServerTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;
    use StripeDepositReviewFixture;

    private function command(array $f, string $action = 'stripe_review_inspect'): array
    {
        return ['action' => $action, 'deposit_id' => $f['deposit']->id, 'actor_id' => $f['actor']->id];
    }

    public function test_two_genuine_wallet_waiters_create_one_case_and_one_immutable_observation(): void
    {
        $this->requireServerRace();
        $f = $this->reviewFixture();
        $this->receiveFollowup();
        $paper = $this->moneyPaper();
        $command = $this->command($f);
        $results = $this->serverRace([$command, $command], 'wallets', $f['wallet']->id);
        $this->assertSame(['reviewed', 'reviewed'], array_column($results, 'outcome'), json_encode($results));
        $this->assertSame($results[0]['case'], $results[1]['case']);
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 1);
        $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 1);
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 0);
        $this->assertSame(1, (int) DB::table(StripeDepositReviewService::CASES)->sole()->version);
        $this->assertSame($paper, $this->moneyPaper());
        Http::assertNothingSent();
    }

    public function test_two_genuine_ack_waiters_write_one_note_and_exact_same_actor_replay(): void
    {
        $this->requireServerRace();
        $f = $this->reviewFixture();
        $this->receiveFollowup();
        $case = app(StripeDepositReviewService::class)->inspect($f['deposit'], $f['actor']);
        $paper = $this->moneyPaper();
        $command = $this->command($f, 'stripe_review_ack') + $case + ['note' => 'Synthetic independently reviewed processor evidence; escalation remains open.'];
        $results = $this->serverRace([$command, $command], 'wallets', $f['wallet']->id);
        $this->assertSame(['reviewed', 'reviewed'], array_column($results, 'outcome'), json_encode($results));
        $replays = array_column(array_column($results, 'case'), 'replayed');
        sort($replays);
        $this->assertSame([false, true], $replays);
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 1);
        $this->assertSame('acknowledged', DB::table(StripeDepositReviewService::CASES)->sole()->state);
        $this->assertSame($paper, $this->moneyPaper());
        Http::assertNothingSent();
    }

    public function test_two_stale_ack_waiters_commit_one_new_open_version_and_no_ack(): void
    {
        $this->requireServerRace();
        $f = $this->reviewFixture();
        $this->receiveFollowup();
        $case = app(StripeDepositReviewService::class)->inspect($f['deposit'], $f['actor']);
        $this->receiveFollowup($this->followupEvent('charge.dispute.created', 'evt_SYNTHETICSECONDRISK'));
        $paper = $this->moneyPaper();
        $command = $this->command($f, 'stripe_review_ack') + $case + ['note' => 'Synthetic old screen must not acknowledge unseen new dispute evidence.'];
        $results = $this->serverRace([$command, $command], 'wallets', $f['wallet']->id);
        $this->assertSame(['refused', 'refused'], array_column($results, 'outcome'), json_encode($results));
        $this->assertSame(['stripe_review_stale', 'stripe_review_stale'], array_column($results, 'code'));
        $this->assertSame([null, null], array_column($results, 'sqlstate'));
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 1);
        $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 2);
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 0);
        $case = DB::table(StripeDepositReviewService::CASES)->sole();
        $this->assertSame(2, (int) $case->version);
        $this->assertSame('open', $case->state);
        $this->assertSame($paper, $this->moneyPaper());
        Http::assertNothingSent();
    }
}
