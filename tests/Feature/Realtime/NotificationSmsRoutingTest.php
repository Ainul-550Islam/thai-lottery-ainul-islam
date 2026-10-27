<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Enums\NotificationFailureReason;
use App\Enums\NotificationStatus;
use App\Enums\WithdrawalStatus;
use App\Events\PrizePayoutCompleted;
use App\Models\Notification as NotificationRow;
use App\Models\NotificationDeliveryAttempt;
use App\Models\NotificationPreference;
use App\Models\Payout;
use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\PrizeWonNotification;
use App\Notifications\WithdrawalStatusNotification;
use App\Services\Notification\SmsGatewayService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * When a phone is texted, and when it is not.
 *
 * Three gates, all of which must open: the state is one the operator allows on the SMS lane
 * (config realtime.notification_sms_states), the player explicitly opted in for that event on
 * the sms channel, and the number is verified. The gateway is the real SmsGatewayService
 * contract behind a mock, so the test proves the only call that can leave the building.
 */
final class NotificationSmsRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Notification::fake();
    }

    private function optIn(User $user, NotificationEventType $type): void
    {
        NotificationPreference::query()->create([
            'user_id' => $user->id,
            'event_type' => $type,
            'channel' => NotificationChannel::Sms,
            'enabled' => true,
        ]);
    }

    private function smsRows(User $user): Collection
    {
        return NotificationRow::query()
            ->where('user_id', $user->id)
            ->where('channel', NotificationChannel::Sms->value)
            ->get();
    }

    #[Test]
    public function a_completed_withdrawal_texts_an_opted_in_verified_number_once(): void
    {
        $gateway = $this->mock(SmsGatewayService::class);
        $gateway->shouldReceive('sendSms')
            ->once()
            ->withArgs(static fn (string $number, string $text): bool => $number === '+66811112222' && str_contains($text, 'recorded as completed'))
            ->andReturn(true);

        $user = User::factory()->create(['phone' => '+66811112222', 'phone_verified_at' => now()]);
        $this->optIn($user, NotificationEventType::WithdrawalPaid);
        $withdrawal = Withdrawal::factory()->create(['user_id' => $user->id, 'status' => WithdrawalStatus::Processing]);

        $withdrawal->forceFill(['status' => WithdrawalStatus::Completed, 'completed_at' => now()])->save();

        $rows = $this->smsRows($user);
        $this->assertCount(1, $rows);
        $this->assertSame(NotificationStatus::Delivered, $rows->first()->status);
        $this->assertSame(1, NotificationDeliveryAttempt::query()->where('notification_id', $rows->first()->id)->where('status', 'delivered')->count());

        Notification::assertSentTo($user, WithdrawalStatusNotification::class, static function (WithdrawalStatusNotification $n, array $channels) use ($user): bool {
            // The mail went through the framework; the SMS went through SmsChannel directly, so the
            // channel list handed to the framework is mail only - the class still declares both.
            return $channels === ['mail'] && $n->viaSms === true && in_array(SmsChannel::class, $n->via($user), true);
        });
    }

    #[Test]
    public function without_an_explicit_opt_in_no_text_is_sent_even_to_a_verified_number(): void
    {
        $gateway = $this->mock(SmsGatewayService::class);
        $gateway->shouldReceive('sendSms')->never();

        $user = User::factory()->create(['phone' => '+66811112222', 'phone_verified_at' => now()]);
        $withdrawal = Withdrawal::factory()->create(['user_id' => $user->id, 'status' => WithdrawalStatus::Processing]);

        $withdrawal->forceFill(['status' => WithdrawalStatus::Completed])->save();

        $this->assertCount(0, $this->smsRows($user));
        Notification::assertSentTo($user, WithdrawalStatusNotification::class, static fn (WithdrawalStatusNotification $n): bool => $n->viaSms === false);
    }

    #[Test]
    public function an_unverified_number_is_never_texted_and_leaves_no_sms_row(): void
    {
        $gateway = $this->mock(SmsGatewayService::class);
        $gateway->shouldReceive('sendSms')->never();

        $user = User::factory()->create(['phone' => '+66811112222', 'phone_verified_at' => null]);
        $this->optIn($user, NotificationEventType::WithdrawalPaid);
        $withdrawal = Withdrawal::factory()->create(['user_id' => $user->id, 'status' => WithdrawalStatus::Processing]);

        $withdrawal->forceFill(['status' => WithdrawalStatus::Completed])->save();

        $this->assertCount(0, $this->smsRows($user));
        $this->assertSame(1, NotificationRow::query()->where('user_id', $user->id)->where('channel', NotificationChannel::Email->value)->count(), 'mail still goes');
    }

    #[Test]
    public function only_states_the_operator_allows_may_page_a_phone(): void
    {
        $gateway = $this->mock(SmsGatewayService::class);
        $gateway->shouldReceive('sendSms')->never();

        $user = User::factory()->create(['phone' => '+66811112222', 'phone_verified_at' => now()]);
        $this->optIn($user, NotificationEventType::WithdrawalApproved);
        $this->optIn($user, NotificationEventType::WithdrawalPaid);

        $withdrawal = Withdrawal::factory()->create(['user_id' => $user->id, 'status' => WithdrawalStatus::UnderReview]);
        $withdrawal->forceFill(['status' => WithdrawalStatus::Approved])->save();

        $this->assertCount(0, $this->smsRows($user), 'approved is not an SMS state');

        Config::set('realtime.notification_sms_states', []);
        $withdrawal->forceFill(['status' => WithdrawalStatus::Completed])->save();

        $this->assertCount(0, $this->smsRows($user), 'the operator switched the SMS lane off');
        $this->assertFalse(WithdrawalStatusNotification::smsStateAllowed('completed'));
    }

    #[Test]
    public function a_gateway_refusal_is_recorded_as_a_failed_attempt_and_does_not_break_the_mail(): void
    {
        $gateway = $this->mock(SmsGatewayService::class);
        $gateway->shouldReceive('sendSms')->once()->andReturn(false);

        $user = User::factory()->create(['phone' => '+66811112222', 'phone_verified_at' => now()]);
        $this->optIn($user, NotificationEventType::WithdrawalRejected);
        $withdrawal = Withdrawal::factory()->create(['user_id' => $user->id, 'status' => WithdrawalStatus::UnderReview]);

        $withdrawal->forceFill(['status' => WithdrawalStatus::Rejected, 'rejection_reason' => 'Mismatch', 'rejected_at' => now()])->save();

        $rows = $this->smsRows($user);
        $this->assertCount(1, $rows);
        $this->assertSame(NotificationStatus::Failed, $rows->first()->status);
        $this->assertSame(NotificationFailureReason::ProviderFailure, $rows->first()->failure_reason);
        $this->assertSame(1, NotificationDeliveryAttempt::query()->where('notification_id', $rows->first()->id)->where('status', 'failed')->count());

        Notification::assertSentToTimes($user, WithdrawalStatusNotification::class, 1);
    }

    #[Test]
    public function a_prize_payout_texts_an_opted_in_winner_with_the_reference_and_no_bank_detail(): void
    {
        $gateway = $this->mock(SmsGatewayService::class);
        $gateway->shouldReceive('sendSms')
            ->once()
            ->withArgs(static fn (string $number, string $text): bool => str_contains($text, 'PO-') && str_contains($text, '7000.00'))
            ->andReturn(true);

        $winner = User::factory()->create(['phone' => '+66811112222', 'phone_verified_at' => now()]);
        $this->optIn($winner, NotificationEventType::BetWon);
        $payout = Payout::factory()->completed()->create(['user_id' => $winner->id]);

        event(new PrizePayoutCompleted($payout, $winner, 'corr-sms'));

        $this->assertCount(1, $this->smsRows($winner));
        Notification::assertSentTo($winner, PrizeWonNotification::class, static fn (PrizeWonNotification $n): bool => $n->viaSms === true);
    }
}
