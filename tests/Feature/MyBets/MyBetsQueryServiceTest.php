<?php

declare(strict_types=1);

namespace Tests\Feature\MyBets;

use App\Enums\BetStatus;
use App\Models\Bet;
use App\Models\User;
use App\Services\MyBets\MyBetsQueryService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * /me/bets is a READ MODEL over bets that already exist.
 *
 * The tests that matter are the ownership ones: a filtered list that accidentally
 * includes somebody else's bet is not a pagination bug, it is a data breach. Every
 * assertion below goes through the service, because a controller-level test would
 * only prove the controller passes the id along.
 */
final class MyBetsQueryServiceTest extends TestCase
{
    use DatabaseTruncation;

    private MyBetsQueryService $bets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bets = app(MyBetsQueryService::class);
    }

    public function test_the_list_is_scoped_to_the_caller(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        Bet::factory()->count(3)->create(['user_id' => $mine->id]);
        Bet::factory()->count(5)->create(['user_id' => $theirs->id]);

        $page = $this->bets->list((int) $mine->id);

        $this->assertSame(3, $page->total());

        foreach ($page->items() as $bet) {
            $this->assertSame((int) $mine->id, (int) $bet->user_id);
        }
    }

    public function test_a_filter_narrows_the_list_and_never_widens_the_scope(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        Bet::factory()->count(2)->create(['user_id' => $mine->id, 'status' => BetStatus::Pending]);
        Bet::factory()->count(3)->create(['user_id' => $mine->id, 'status' => BetStatus::Won]);
        Bet::factory()->count(4)->create(['user_id' => $theirs->id, 'status' => BetStatus::Pending]);

        $pending = $this->bets->list((int) $mine->id, MyBetsQueryService::FILTER_PENDING);

        $this->assertSame(2, $pending->total());

        foreach ($pending->items() as $bet) {
            $this->assertSame((int) $mine->id, (int) $bet->user_id);
            $this->assertSame(BetStatus::Pending, $bet->status);
        }
    }

    public function test_counts_are_consistent_with_the_filtered_lists(): void
    {
        $user = User::factory()->create();

        Bet::factory()->count(2)->create(['user_id' => $user->id, 'status' => BetStatus::Pending]);
        Bet::factory()->count(3)->create(['user_id' => $user->id, 'status' => BetStatus::Won]);
        Bet::factory()->count(1)->create(['user_id' => $user->id, 'status' => BetStatus::Lost]);

        $counts = $this->bets->counts((int) $user->id);

        $this->assertSame(6, $counts['all']);
        $this->assertSame(2, $counts['pending']);
        $this->assertSame(
            $this->bets->list((int) $user->id, MyBetsQueryService::FILTER_PENDING)->total(),
            $counts['pending'],
        );
        $this->assertSame(
            $this->bets->list((int) $user->id, MyBetsQueryService::FILTER_WINNING)->total(),
            $counts['winning'],
        );
    }

    public function test_detail_returns_null_for_another_members_bet(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $theirBet = Bet::factory()->create(['user_id' => $theirs->id]);

        $this->assertNull($this->bets->detail((int) $mine->id, (int) $theirBet->id));
    }

    public function test_detail_returns_the_callers_own_bet(): void
    {
        $mine = User::factory()->create();
        $bet = Bet::factory()->create(['user_id' => $mine->id]);

        $found = $this->bets->detail((int) $mine->id, (int) $bet->id);

        $this->assertNotNull($found);
        $this->assertSame((int) $bet->id, (int) $found->id);
    }

    public function test_the_filter_vocabulary_is_the_one_the_service_switches_on(): void
    {
        $filters = MyBetsQueryService::filters();

        $this->assertContains(MyBetsQueryService::FILTER_ALL, $filters);
        $this->assertContains(MyBetsQueryService::FILTER_PENDING, $filters);
        $this->assertContains(MyBetsQueryService::FILTER_WINNING, $filters);
    }

    public function test_the_page_size_is_capped(): void
    {
        $user = User::factory()->create();
        Bet::factory()->count(3)->create(['user_id' => $user->id]);

        $page = $this->bets->list((int) $user->id, MyBetsQueryService::FILTER_ALL, null, null, 5000);

        // The cap is applied inside the service too, not only in the form request.
        $this->assertLessThanOrEqual(100, $page->perPage());
        $this->assertSame(3, $page->total());
    }

    public function test_an_unknown_filter_falls_back_to_all_rather_than_erroring(): void
    {
        $user = User::factory()->create();
        Bet::factory()->create(['user_id' => $user->id]);

        $page = $this->bets->list((int) $user->id, 'not-a-filter');

        $this->assertSame(1, $page->total());
    }

    public function test_a_date_window_excludes_bets_outside_it(): void
    {
        $user = User::factory()->create();

        $old = Bet::factory()->create(['user_id' => $user->id]);
        $old->created_at = now()->subDays(40);
        $old->save();

        Bet::factory()->create(['user_id' => $user->id]);

        $page = $this->bets->list((int) $user->id, MyBetsQueryService::FILTER_ALL, now()->subDays(7), null);

        foreach ($page->items() as $bet) {
            $this->assertTrue($bet->created_at->gte(now()->subDays(7)->startOfDay()));
        }
    }
}
