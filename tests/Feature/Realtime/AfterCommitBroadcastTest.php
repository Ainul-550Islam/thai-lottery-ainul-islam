<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Enums\DepositStatus;
use App\Enums\PayoutStatus;
use App\Enums\QueueName;
use App\Events\DepositStatusUpdated;
use App\Events\PrizePayoutCompleted;
use App\Events\WalletBalanceUpdated;
use App\Events\WalletBalanceUpdatedEvent;
use App\Listeners\Notifications\SendDepositStatusNotification;
use App\Listeners\Notifications\SendPrizePayoutNotification;
use App\Models\Deposit;
use App\Models\Payout;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Nothing about money leaves the process before the database has agreed to it.
 *
 * Both halves of the lane are measured on the real queue table: the broadcast frame and the
 * notification listener. Inside an open transaction neither is queued; after a rollback neither
 * is queued; after a commit each is queued exactly once, on the notifications lane.
 *
 * DatabaseTruncation on purpose: RefreshDatabase would hold the test in a transaction and the
 * after-commit callbacks would fire for the wrong reason.
 */
final class AfterCommitBroadcastTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('queue.default', 'database');
        Config::set('queue.connections.database', [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        Config::set('broadcasting.default', 'log');

        DB::table('jobs')->delete();
    }

    /**
     * Queued broadcast frames and notification listeners, displayName@queue => count. The
     * marketing conversion listeners (app/Listeners/Analytics) ride the same events on their
     * own lane; they are another batch's contract and are left out of these counts.
     *
     * @return array<string, int>
     */
    private function queued(): array
    {
        $counts = [];

        foreach (DB::table('jobs')->get() as $row) {
            $payload = json_decode((string) $row->payload, true);
            $name = (string) ($payload['displayName'] ?? '?');

            if (! str_starts_with($name, 'App\\Events\\') && ! str_starts_with($name, 'App\\Listeners\\Notifications\\')) {
                continue;
            }

            $counts[$name.'@'.$row->queue] = ($counts[$name.'@'.$row->queue] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    #[Test]
    public function a_deposit_confirmed_in_a_rolled_back_transaction_neither_broadcasts_nor_notifies(): void
    {
        $deposit = Deposit::factory()->create(['status' => DepositStatus::Pending]);
        $marker = new class extends RuntimeException {};

        try {
            DB::transaction(function () use ($deposit, $marker): void {
                $deposit->forceFill(['status' => DepositStatus::Confirmed])->save();

                $this->assertSame([], $this->queued(), 'queued while the transaction was still open');

                throw $marker;
            });
        } catch (RuntimeException $e) {
            if ($e !== $marker) {
                throw $e;
            }
        }

        $this->assertSame([], $this->queued(), 'a rolled-back confirmation reached the queue');
        $this->assertSame(DepositStatus::Pending, $deposit->fresh()->status);
    }

    #[Test]
    public function a_committed_deposit_confirmation_queues_one_broadcast_and_one_listener_on_the_notifications_lane(): void
    {
        $deposit = Deposit::factory()->create(['status' => DepositStatus::Pending]);

        DB::transaction(function () use ($deposit): void {
            $deposit->forceFill(['status' => DepositStatus::Confirmed])->save();
        });

        $lane = QueueName::Notifications->value;

        $this->assertSame([
            DepositStatusUpdated::class.'@'.$lane => 1,
            SendDepositStatusNotification::class.'@'.$lane => 1,
        ], $this->queued());
    }

    #[Test]
    public function a_prize_payout_event_raised_inside_the_paying_transaction_waits_for_the_commit(): void
    {
        $winner = User::factory()->create();
        $payout = Payout::factory()->processing()->create(['user_id' => $winner->id]);
        $marker = new class extends RuntimeException {};

        try {
            DB::transaction(function () use ($payout, $winner, $marker): void {
                $payout->forceFill(['status' => PayoutStatus::Completed, 'processed_at' => now()])->save();
                event(new PrizePayoutCompleted($payout, $winner, 'corr-rollback'));

                $this->assertSame([], $this->queued(), 'the payout announced itself before the money was committed');

                throw $marker;
            });
        } catch (RuntimeException $e) {
            if ($e !== $marker) {
                throw $e;
            }
        }

        $this->assertSame([], $this->queued());
        $this->assertSame(PayoutStatus::Processing, $payout->fresh()->status);

        DB::transaction(function () use ($payout, $winner): void {
            $payout->forceFill(['status' => PayoutStatus::Completed, 'processed_at' => now()])->save();
            event(new PrizePayoutCompleted($payout, $winner, 'corr-commit'));
        });

        $lane = QueueName::Notifications->value;

        $this->assertSame([
            PrizePayoutCompleted::class.'@'.$lane => 1,
            SendPrizePayoutNotification::class.'@'.$lane => 1,
        ], $this->queued());
    }

    #[Test]
    public function one_wallet_write_publishes_one_frame_even_when_the_finance_sync_event_fires_too(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);
        $marker = new class extends RuntimeException {};

        // The finance services write the wallet AND raise WalletBalanceUpdatedEvent through
        // RealtimeSyncService for the same change. Two broadcasters with one broadcastAs meant
        // two identical frames per write; the sync event no longer broadcasts.
        $sync = static fn (Wallet $w): WalletBalanceUpdatedEvent => new WalletBalanceUpdatedEvent(
            userId: (string) $w->user_id,
            walletType: (string) ($w->type?->value ?? 'main'),
            currency: (string) ($w->currency?->value ?? 'THB'),
            balance: (string) $w->balance,
            availableBalance: (string) $w->getAvailableBalance(),
            version: (int) $w->version,
            reason: 'balance',
        );

        try {
            DB::transaction(function () use ($wallet, $sync, $marker): void {
                $wallet->forceFill(['balance' => '321.00'])->save();
                event($sync($wallet));

                $this->assertSame([], $this->queued(), 'a wallet frame was queued before the money was committed');

                throw $marker;
            });
        } catch (RuntimeException $e) {
            if ($e !== $marker) {
                throw $e;
            }
        }

        $this->assertSame([], $this->queued(), 'a rolled-back wallet write published anyway');

        DB::transaction(function () use ($wallet, $sync): void {
            $wallet->forceFill(['balance' => '654.00'])->save();
            event($sync($wallet));
        });

        $this->assertSame([
            WalletBalanceUpdated::class.'@'.QueueName::Notifications->value => 1,
        ], $this->queued(), 'exactly one frame per wallet write: the duplicate event must not broadcast a second one');
    }

    #[Test]
    public function a_silent_transition_queues_the_broadcast_but_no_listener(): void
    {
        $deposit = Deposit::factory()->create(['status' => DepositStatus::Pending]);

        DB::transaction(function () use ($deposit): void {
            $deposit->forceFill(['status' => DepositStatus::Approved])->save();
        });

        $this->assertSame([
            DepositStatusUpdated::class.'@'.QueueName::Notifications->value => 1,
        ], $this->queued(), 'approved is an internal hop: the page may update, the player is not mailed');
    }
}
