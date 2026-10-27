<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Enums\DepositStatus;
use App\Enums\KycStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Enums\NotificationStatus;
use App\Enums\PayoutStatus;
use App\Enums\UserStatus;
use App\Enums\WithdrawalStatus;
use App\Events\DepositStatusUpdated;
use App\Events\KycStatusChanged;
use App\Events\PrizePayoutCompleted;
use App\Events\WithdrawalStatusUpdated;
use App\Listeners\Notifications\SendDepositStatusNotification;
use App\Listeners\Notifications\SendKycStatusNotification;
use App\Listeners\Notifications\SendPrizePayoutNotification;
use App\Listeners\Notifications\SendWithdrawalStatusNotification;
use App\Models\Deposit;
use App\Models\Notification as NotificationRow;
use App\Models\NotificationDeliveryAttempt;
use App\Models\NotificationPreference;
use App\Models\Payout;
use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\KycStatusNotification;
use App\Notifications\PaymentStatusNotification;
use App\Notifications\PrizeWonNotification;
use App\Notifications\WithdrawalStatusNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The four player notifications, from the row fact to the delivered record.
 *
 * Before this batch every one of these Notification classes existed and nothing constructed
 * them: a deposit confirmed, a withdrawal paid, a prize landed, a KYC verdict written, and the
 * player heard nothing. These tests pin the listeners that now sit between the money events and
 * the notification lane: which transitions speak, which stay quiet, that the same fact never
 * sends twice, that preferences are honoured, and that every send leaves a delivery attempt.
 *
 * Notification::fake() stands in for the mail transport only. The in-app rows and the delivery
 * attempts are real database rows written through the dispatch and delivery services.
 */
final class NotificationListenersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Notification::fake();
    }

    private function rowsFor(User $user, NotificationEventType $type, NotificationChannel $channel): Collection
    {
        return NotificationRow::query()
            ->where('user_id', $user->getKey())
            ->where('event_type', $type->value)
            ->where('channel', $channel->value)
            ->get();
    }

    #[Test]
    public function listeners_are_registered_queued_and_deferred_until_commit(): void
    {
        $this->assertTrue(Event::hasListeners(DepositStatusUpdated::class));
        $this->assertTrue(Event::hasListeners(WithdrawalStatusUpdated::class));
        $this->assertTrue(Event::hasListeners(PrizePayoutCompleted::class));
        $this->assertTrue(Event::hasListeners(KycStatusChanged::class));

        foreach ([
            SendDepositStatusNotification::class,
            SendWithdrawalStatusNotification::class,
            SendPrizePayoutNotification::class,
            SendKycStatusNotification::class,
        ] as $listener) {
            $instance = app($listener);

            $this->assertInstanceOf(ShouldQueue::class, $instance, $listener.' must run off the request');
            $this->assertInstanceOf(ShouldHandleEventsAfterCommit::class, $instance, $listener.' must wait for the commit');
            $this->assertSame('notifications', $instance->viaQueue(), $listener.' belongs on the notifications lane');
            $this->assertGreaterThan(1, $instance->tries);
        }
    }

    #[Test]
    public function a_confirmed_deposit_tells_the_depositor_in_app_and_by_mail_once(): void
    {
        $user = User::factory()->create();
        $deposit = Deposit::factory()->create(['user_id' => $user->id, 'status' => DepositStatus::Pending]);

        $deposit->forceFill(['status' => DepositStatus::Confirmed])->save();

        $inApp = $this->rowsFor($user, NotificationEventType::DepositConfirmed, NotificationChannel::InApp);
        $mail = $this->rowsFor($user, NotificationEventType::DepositConfirmed, NotificationChannel::Email);

        $this->assertCount(1, $inApp, 'one in-app row in the notification centre');
        $this->assertCount(1, $mail, 'one email row carrying the delivery attempt');
        $this->assertSame(NotificationStatus::Delivered, $inApp->first()->status);
        $this->assertSame(NotificationStatus::Delivered, $mail->first()->status);
        $this->assertStringContainsString($deposit->reference_number, (string) $inApp->first()->body);
        $this->assertSame(1, NotificationDeliveryAttempt::query()->where('notification_id', $mail->first()->id)->count());

        Notification::assertSentToTimes($user, PaymentStatusNotification::class, 1);
        Notification::assertSentTo($user, PaymentStatusNotification::class, static function (PaymentStatusNotification $n, array $channels): bool {
            return $channels === ['mail'] && $n->locale === 'en';
        });

        // The same fact again (a replayed webhook, a queue retry): nothing new is written or sent.
        event(new DepositStatusUpdated($deposit->fresh()));

        $this->assertCount(1, $this->rowsFor($user, NotificationEventType::DepositConfirmed, NotificationChannel::Email));
        Notification::assertSentToTimes($user, PaymentStatusNotification::class, 1);
    }

    #[Test]
    public function internal_deposit_hops_stay_silent_and_a_review_is_in_app_only(): void
    {
        $user = User::factory()->create();
        $deposit = Deposit::factory()->create(['user_id' => $user->id, 'status' => DepositStatus::Pending]);

        $deposit->forceFill(['status' => DepositStatus::Approved])->save();

        $this->assertSame(0, NotificationRow::query()->where('user_id', $user->id)->count(), 'approved is an internal hop');
        Notification::assertNothingSent();

        $deposit->forceFill(['status' => DepositStatus::Processing])->save();

        $this->assertCount(1, $this->rowsFor($user, NotificationEventType::Manual, NotificationChannel::InApp));
        $this->assertSame(0, NotificationRow::query()->where('user_id', $user->id)->where('channel', NotificationChannel::Email->value)->count());
        Notification::assertNothingSent();

        $deposit->forceFill(['status' => DepositStatus::Failed, 'failure_reason' => 'Gateway declined'])->save();

        $this->assertCount(1, $this->rowsFor($user, NotificationEventType::DepositFailed, NotificationChannel::Email));
        Notification::assertSentToTimes($user, PaymentStatusNotification::class, 1);
    }

    #[Test]
    public function a_mail_preference_switched_off_is_honoured_and_recorded_as_no_attempt(): void
    {
        $user = User::factory()->create();
        NotificationPreference::query()->create([
            'user_id' => $user->id,
            'event_type' => NotificationEventType::DepositConfirmed,
            'channel' => NotificationChannel::Email,
            'enabled' => false,
        ]);
        $deposit = Deposit::factory()->create(['user_id' => $user->id, 'status' => DepositStatus::Pending]);

        $deposit->forceFill(['status' => DepositStatus::Confirmed])->save();

        $this->assertCount(1, $this->rowsFor($user, NotificationEventType::DepositConfirmed, NotificationChannel::InApp), 'the in-app lane is still on');
        $this->assertCount(0, $this->rowsFor($user, NotificationEventType::DepositConfirmed, NotificationChannel::Email));
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_banned_owner_is_never_mailed_but_a_suspended_one_still_learns_about_their_money(): void
    {
        $banned = User::factory()->create(['status' => UserStatus::Banned]);
        $withdrawal = Withdrawal::factory()->create(['user_id' => $banned->id, 'status' => WithdrawalStatus::Processing]);

        $withdrawal->forceFill(['status' => WithdrawalStatus::Completed])->save();

        $this->assertSame(0, NotificationRow::query()->where('user_id', $banned->id)->count());
        Notification::assertNothingSent();

        $suspended = User::factory()->suspended()->create();
        $paid = Withdrawal::factory()->create(['user_id' => $suspended->id, 'status' => WithdrawalStatus::Processing]);

        $paid->forceFill(['status' => WithdrawalStatus::Completed])->save();

        $this->assertCount(1, $this->rowsFor($suspended, NotificationEventType::WithdrawalPaid, NotificationChannel::Email));
        Notification::assertSentToTimes($suspended, WithdrawalStatusNotification::class, 1);
    }

    #[Test]
    public function withdrawal_transitions_speak_or_stay_quiet_by_meaning_and_the_mail_shows_last_four_only(): void
    {
        $user = User::factory()->create();
        $withdrawal = Withdrawal::factory()->create([
            'user_id' => $user->id,
            'status' => WithdrawalStatus::Pending,
            'payout_details' => ['bank' => 'Bangkok Bank', 'account_number' => '1234567890'],
        ]);

        $withdrawal->forceFill(['status' => WithdrawalStatus::UnderReview])->save();
        $withdrawal->forceFill(['status' => WithdrawalStatus::KycRequired])->save();
        $this->assertSame(0, NotificationRow::query()->where('user_id', $user->id)->count(), 'queue positions are not news');

        $withdrawal->forceFill(['status' => WithdrawalStatus::Approved])->save();
        $this->assertCount(1, $this->rowsFor($user, NotificationEventType::WithdrawalApproved, NotificationChannel::Email));

        $withdrawal->forceFill(['status' => WithdrawalStatus::Completed, 'completed_at' => now()])->save();
        $this->assertCount(1, $this->rowsFor($user, NotificationEventType::WithdrawalPaid, NotificationChannel::Email));

        Notification::assertSentToTimes($user, WithdrawalStatusNotification::class, 2);
        Notification::assertSentTo($user, WithdrawalStatusNotification::class, static function (WithdrawalStatusNotification $n): bool {
            if ($n->currentStatus !== WithdrawalStatus::Completed->value) {
                return true;
            }

            $mail = $n->toMail(new \stdClass);
            $copy = implode(' ', $mail->introLines);

            return str_contains($copy, '7890') && ! str_contains($copy, '1234567890') && ! str_contains($copy, '123456');
        });

        // SMS was never attempted: the player did not opt in.
        $this->assertSame(0, NotificationRow::query()->where('user_id', $user->id)->where('channel', NotificationChannel::Sms->value)->count());
    }

    #[Test]
    public function a_rejected_withdrawal_carries_the_operator_note_but_a_failed_one_hides_provider_text(): void
    {
        $user = User::factory()->create();
        $rejected = Withdrawal::factory()->create(['user_id' => $user->id, 'status' => WithdrawalStatus::UnderReview]);
        $rejected->forceFill(['status' => WithdrawalStatus::Rejected, 'rejection_reason' => 'Name on account does not match', 'rejected_at' => now()])->save();

        $failed = Withdrawal::factory()->create(['user_id' => $user->id, 'status' => WithdrawalStatus::Processing]);
        $failed->forceFill(['status' => WithdrawalStatus::Failed, 'rejection_reason' => 'HTTP 502 from provider x-trace 9f1'])->save();

        $this->assertCount(2, $this->rowsFor($user, NotificationEventType::WithdrawalRejected, NotificationChannel::Email));

        Notification::assertSentTo($user, WithdrawalStatusNotification::class, static function (WithdrawalStatusNotification $n): bool {
            $copy = implode(' ', $n->toMail(new \stdClass)->introLines);

            return match ($n->currentStatus) {
                WithdrawalStatus::Rejected->value => str_contains($copy, 'Name on account does not match'),
                WithdrawalStatus::Failed->value => ! str_contains($copy, 'HTTP 502') && str_contains($copy, 'recorded as failed'),
                default => false,
            };
        });
    }

    #[Test]
    public function a_prize_payout_is_announced_to_its_owner_only(): void
    {
        $winner = User::factory()->create();
        $stranger = User::factory()->create();
        $payout = Payout::factory()->completed()->create(['user_id' => $winner->id]);

        // A payout event whose winner is not the payout's owner is refused outright.
        event(new PrizePayoutCompleted($payout, $stranger, 'corr-1'));

        $this->assertSame(0, NotificationRow::query()->count());
        Notification::assertNothingSent();

        event(new PrizePayoutCompleted($payout, $winner, 'corr-1'));

        $this->assertCount(1, $this->rowsFor($winner, NotificationEventType::BetWon, NotificationChannel::InApp));
        $this->assertCount(1, $this->rowsFor($winner, NotificationEventType::BetWon, NotificationChannel::Email));
        Notification::assertSentToTimes($winner, PrizeWonNotification::class, 1);
        Notification::assertNotSentTo($stranger, PrizeWonNotification::class);

        // The payout job retrying the same completed row does not announce it twice.
        event(new PrizePayoutCompleted($payout, $winner, 'corr-2'));

        Notification::assertSentToTimes($winner, PrizeWonNotification::class, 1);

        // A payout that is not completed is not a prize landed.
        $pending = Payout::factory()->processing()->create(['user_id' => $winner->id]);
        event(new PrizePayoutCompleted($pending, $winner, 'corr-3'));

        $this->assertCount(1, $this->rowsFor($winner, NotificationEventType::BetWon, NotificationChannel::Email));
        $this->assertSame(PayoutStatus::Processing, $pending->fresh()->status);
    }

    #[Test]
    public function a_kyc_verdict_on_the_user_row_reaches_the_player_once_per_status(): void
    {
        $user = User::factory()->create(['kyc_status' => KycStatus::UnderReview]);

        $user->forceFill(['kyc_status' => KycStatus::Verified])->save();

        $this->assertCount(1, $this->rowsFor($user, NotificationEventType::KycVerified, NotificationChannel::InApp));
        $this->assertCount(1, $this->rowsFor($user, NotificationEventType::KycVerified, NotificationChannel::Email));
        Notification::assertSentToTimes($user, KycStatusNotification::class, 1);
        Notification::assertSentTo($user, KycStatusNotification::class, static fn (KycStatusNotification $n): bool => $n->status === 'verified');

        // A second envelope for the same verdict (another KYC table catching up) is one message, not two.
        event(new KycStatusChanged($user, 'under_review', 'verified', null, 'kyc_documents', 1));

        Notification::assertSentToTimes($user, KycStatusNotification::class, 1);

        // Unchanged status, or the player's own submission, says nothing.
        $user->forceFill(['phone' => '+66899999999'])->save();
        event(new KycStatusChanged($user, 'verified', 'verified', null, 'users', (int) $user->id));
        event(new KycStatusChanged($user, 'unverified', 'pending', null, 'users', (int) $user->id));

        Notification::assertSentToTimes($user, KycStatusNotification::class, 1);
    }

    #[Test]
    public function a_kyc_rejection_mails_the_reason_and_a_review_is_in_app_only(): void
    {
        $user = User::factory()->create(['kyc_status' => KycStatus::Pending]);

        $user->forceFill(['kyc_status' => KycStatus::UnderReview])->save();

        $this->assertCount(1, $this->rowsFor($user, NotificationEventType::Manual, NotificationChannel::InApp));
        Notification::assertNothingSent();

        event(new KycStatusChanged($user, 'under_review', 'rejected', 'Document image unreadable', 'kyc_verifications', 7));

        $this->assertCount(1, $this->rowsFor($user, NotificationEventType::KycRejected, NotificationChannel::Email));
        Notification::assertSentTo($user, KycStatusNotification::class, static function (KycStatusNotification $n): bool {
            $copy = implode(' ', $n->toMail(new \stdClass)->introLines);

            return $n->status === 'rejected' && str_contains($copy, 'Document image unreadable');
        });
    }
}
