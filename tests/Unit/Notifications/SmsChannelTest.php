<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Enums\Currency;
use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\WithdrawalStatusNotification;
use App\Services\Notification\SmsGatewayService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * SmsChannel: the only road from a Notification to SmsGatewayService::sendSms(), and the
 * place the three refusals live - no verified number, no text, gateway failure. It never
 * throws and never logs a full phone number.
 */
final class SmsChannelTest extends TestCase
{
    private function withdrawalNotification(): WithdrawalStatusNotification
    {
        $withdrawal = new Withdrawal([
            'amount' => '250.00',
            'currency' => Currency::THB,
            'status' => WithdrawalStatus::Completed,
            'reference_number' => 'WD-SMS-250',
            'payout_details' => ['bank' => 'Krungthai', 'account_last4' => '9876'],
        ]);

        return new WithdrawalStatusNotification($withdrawal, 'processing', 'completed', null, true);
    }

    private function user(?string $phone, bool $verified): User
    {
        $user = new User;
        $user->forceFill([
            'phone' => $phone,
            'phone_verified_at' => $verified ? now() : null,
        ]);

        return $user;
    }

    #[Test]
    public function a_verified_number_is_handed_to_the_gateway_once_and_the_outcome_is_sent(): void
    {
        $gateway = $this->createMock(SmsGatewayService::class);
        $gateway->expects($this->once())
            ->method('sendSms')
            ->with('+66812345678', $this->callback(static fn (string $text): bool => str_contains($text, 'WD-SMS-250') && ! str_contains($text, '9876')))
            ->willReturn(true);

        $channel = new SmsChannel($gateway);

        $this->assertSame(SmsChannel::SENT, $channel->deliver($this->user('+66812345678', true), $this->withdrawalNotification()));
    }

    #[Test]
    public function an_unverified_or_missing_number_is_skipped_without_touching_the_gateway(): void
    {
        $gateway = $this->createMock(SmsGatewayService::class);
        $gateway->expects($this->never())->method('sendSms');

        $channel = new SmsChannel($gateway);
        $notification = $this->withdrawalNotification();

        $this->assertSame(SmsChannel::SKIPPED, $channel->deliver($this->user('+66812345678', false), $notification), 'an unverified number is not a destination');
        $this->assertSame(SmsChannel::SKIPPED, $channel->deliver($this->user(null, true), $notification), 'no number, no destination');
        $this->assertSame(SmsChannel::SKIPPED, $channel->deliver($this->user('123', true), $notification), 'a number too short to be real is refused');
        $this->assertNull($channel->destinationFor($this->user('+66812345678', false)));
        $this->assertSame('+66812345678', $channel->destinationFor($this->user('+66812345678', true)));
    }

    #[Test]
    public function a_notification_without_sms_text_is_skipped(): void
    {
        $gateway = $this->createMock(SmsGatewayService::class);
        $gateway->expects($this->never())->method('sendSms');

        $channel = new SmsChannel($gateway);
        $silent = new class extends Notification
        {
            public function toSms(object $notifiable): string
            {
                return '   ';
            }
        };

        $this->assertSame(SmsChannel::SKIPPED, $channel->deliver($this->user('+66812345678', true), $silent));

        $noSms = new class extends Notification {};

        $this->assertSame(SmsChannel::SKIPPED, $channel->deliver($this->user('+66812345678', true), $noSms));
    }

    #[Test]
    public function a_gateway_refusal_or_exception_becomes_failed_and_never_escapes(): void
    {
        $refusing = $this->createMock(SmsGatewayService::class);
        $refusing->method('sendSms')->willReturn(false);

        $this->assertSame(SmsChannel::FAILED, (new SmsChannel($refusing))->deliver($this->user('+66812345678', true), $this->withdrawalNotification()));

        $throwing = $this->createMock(SmsGatewayService::class);
        $throwing->method('sendSms')->willThrowException(new RuntimeException('gateway down'));

        $this->assertSame(SmsChannel::FAILED, (new SmsChannel($throwing))->deliver($this->user('+66812345678', true), $this->withdrawalNotification()));
        $this->assertFalse((new SmsChannel($throwing))->send($this->user('+66812345678', true), $this->withdrawalNotification()));
    }

    #[Test]
    public function logs_carry_only_the_last_four_digits(): void
    {
        $this->assertSame('*******5678', SmsChannel::mask('+66812345678'));
        $this->assertSame('****', SmsChannel::mask('1234'));
        $this->assertSame('', SmsChannel::mask(''));

        Log::shouldReceive('info')->once()->withArgs(static function (string $message, array $context): bool {
            return $message === 'notifications.sms.sent'
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), '66812345678')
                && str_ends_with((string) ($context['destination'] ?? ''), '5678');
        });
        Log::shouldReceive('warning')->never();

        $gateway = $this->createMock(SmsGatewayService::class);
        $gateway->method('sendSms')->willReturn(true);

        (new SmsChannel($gateway))->deliver($this->user('+66812345678', true), $this->withdrawalNotification());
    }
}
