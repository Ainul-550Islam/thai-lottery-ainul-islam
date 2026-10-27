<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Enums\PayoutStatus;
use App\Events\BetPlaced;
use App\Events\DepositStatusUpdated;
use App\Events\DrawResultPublished;
use App\Events\DrawSettlementCompletedEvent;
use App\Events\DrawStatusUpdated;
use App\Events\PrizePayoutCompleted;
use App\Events\WalletBalanceUpdated;
use App\Events\WalletBalanceUpdatedEvent;
use App\Events\WithdrawalStatusUpdated;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\Payout;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What each broadcast event says, and to whom. Money and identity ride private channels
 * named after their owner and nothing else; the public draw channels carry draw facts only.
 */
final class EventPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const PERSON_KEYS = ['user_id', 'wallet_id', 'bet_id', 'payout_id', 'correlation_id', 'balance', 'amount', 'locked_balance', 'available_balance', 'reference_number', 'email', 'phone', 'name'];

    /** @return list<Channel> */
    private function channels(ShouldBroadcast $event): array
    {
        $channels = $event->broadcastOn();

        return is_array($channels) ? $channels : [$channels];
    }

    private function assertOwnerOnly(ShouldBroadcast $event, int $ownerId): void
    {
        $channels = $this->channels($event);
        $this->assertNotSame([], $channels);

        foreach ($channels as $channel) {
            $this->assertTrue($channel instanceof PrivateChannel || $channel instanceof PresenceChannel,
                $event::class.' publishes on the public channel '.$channel->name);

            $name = preg_replace('/^(private|presence)-/', '', (string) $channel->name);
            $this->assertMatchesRegularExpression('/(^|\.)'.$ownerId.'(\.wallet)?$/', $name,
                $event::class.' publishes on '.$name.' which is not scoped to user '.$ownerId);
        }
    }

    #[Test]
    public function prize_payout_completed_is_private_to_the_winner_and_names_no_correlation_or_bet(): void
    {
        $winner = User::factory()->create();
        $payout = Payout::factory()->completed()->create(['user_id' => $winner->id, 'bet_id' => null]);

        $event = new PrizePayoutCompleted($payout, $winner, 'corr-abc');

        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event, 'a payout is written inside a transaction; the broadcast must wait for the commit');
        $this->assertOwnerOnly($event, (int) $winner->id);
        $this->assertSame('payout.completed', $event->broadcastAs());

        $payload = $event->broadcastWith();

        $this->assertArrayNotHasKey('correlation_id', $payload);
        $this->assertArrayNotHasKey('bet_id', $payload);
        $this->assertArrayNotHasKey('user_id', $payload);
        $this->assertSame($payout->reference_number, $payload['reference_number']);
        $this->assertSame((string) $payout->amount, $payload['amount']);
        $this->assertSame(PayoutStatus::Completed, $payout->status);
    }

    #[Test]
    public function wallet_balance_updated_is_private_after_commit_and_carries_balances_currency_and_version(): void
    {
        $wallet = Wallet::factory()->create();
        $event = new WalletBalanceUpdated($wallet, 'balance');

        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertTrue($event->afterCommit);
        $this->assertSame('notifications', $event->broadcastQueue());
        $this->assertSame('wallet.balance.updated', $event->broadcastAs());
        $this->assertOwnerOnly($event, (int) $wallet->user_id);

        $payload = $event->broadcastWith();

        foreach (['balance', 'locked_balance', 'available_balance', 'currency', 'version', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }

        $this->assertArrayNotHasKey('user_id', $payload);
    }

    #[Test]
    public function the_duplicate_wallet_event_no_longer_broadcasts_but_still_dispatches(): void
    {
        $this->assertFalse(is_subclass_of(WalletBalanceUpdatedEvent::class, ShouldBroadcast::class),
            'two broadcasters for one wallet fact means two frames per change; WalletBalanceUpdated is the canonical one');
        $this->assertTrue(class_exists(WalletBalanceUpdatedEvent::class), 'the finance services still dispatch it; it must stay constructible');
    }

    #[Test]
    public function deposit_and_withdrawal_events_carry_only_the_five_player_facing_fields(): void
    {
        $deposit = Deposit::factory()->confirmed()->create();
        $withdrawal = Withdrawal::factory()->completed()->create();

        foreach ([new DepositStatusUpdated($deposit), new WithdrawalStatusUpdated($withdrawal)] as $event) {
            $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
            $this->assertOwnerOnly($event, (int) ($event instanceof DepositStatusUpdated ? $deposit->user_id : $withdrawal->user_id));

            $this->assertSame(
                ['amount', 'currency', 'reference_number', 'status', 'updated_at'],
                collect(array_keys($event->broadcastWith()))->sort()->values()->all(),
                $event::class.' payload must be exactly reference_number, status, amount, currency, updated_at',
            );
        }
    }

    #[Test]
    public function bet_placed_is_private_and_carries_ticket_draw_status_and_stake(): void
    {
        $bet = Bet::factory()->create();
        $event = new BetPlaced($bet);

        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertOwnerOnly($event, (int) $bet->user_id);

        $payload = $event->broadcastWith();

        $this->assertSame($bet->bet_number, $payload['reference_number']);
        $this->assertSame((int) $bet->draw_id, $payload['draw_id']);
        $this->assertArrayHasKey('status', $payload);
        $this->assertSame((string) $bet->stake_amount, $payload['total_stake']);
        $this->assertArrayNotHasKey('user_id', $payload);
        $this->assertArrayNotHasKey('bet_id', $payload);
    }

    #[Test]
    public function draw_events_are_public_and_carry_no_person(): void
    {
        $draw = Draw::factory()->create();
        $result = DrawResult::factory()->create(['draw_id' => $draw->id, 'published_at' => now()]);

        $events = [
            new DrawStatusUpdated($draw),
            new DrawResultPublished($draw, $result),
            new DrawSettlementCompletedEvent((string) $draw->id, (string) $draw->draw_number, 'thai', '123456', '1200.00', 12),
        ];

        foreach ($events as $event) {
            $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);

            foreach ($this->channels($event) as $channel) {
                $this->assertSame(Channel::class, $channel::class, $event::class.' should be public');
                $this->assertMatchesRegularExpression('/^draws?(\.|$)/', (string) $channel->name);
            }

            $leaks = array_intersect(array_keys($event->broadcastWith()), self::PERSON_KEYS);

            $this->assertSame([], array_values($leaks), $event::class.' leaks '.implode(', ', $leaks).' on a public channel');
        }
    }

    #[Test]
    public function no_event_in_app_events_constructs_a_public_channel_outside_the_draw_names(): void
    {
        $offenders = [];

        foreach (glob(app_path('Events/*.php')) ?: [] as $file) {
            preg_match_all("/new Channel\\('([^']*)'/", (string) file_get_contents($file), $m);

            foreach ($m[1] as $name) {
                if (preg_match('/^draws?(\.|$)/', $name) !== 1) {
                    $offenders[] = basename($file).' -> '.$name;
                }
            }
        }

        $this->assertSame([], $offenders, 'public channels are for draws only: '.implode(', ', $offenders));
    }
}
