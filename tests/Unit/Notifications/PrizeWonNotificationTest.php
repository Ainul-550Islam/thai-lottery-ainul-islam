<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Enums\PrizeDisbursementStatus;
use App\Models\PrizeDisbursement;
use App\Notifications\PrizeWonNotification;
use Tests\TestCase;

final class PrizeWonNotificationTest extends TestCase
{
    public function test_notice_does_not_assert_wallet_credit_or_withdrawal_availability(): void
    {
        $disbursement = new PrizeDisbursement([
            'draw_id' => 42,
            'prize_amount' => '10.00',
            'currency' => 'THB',
            'status' => PrizeDisbursementStatus::Reserved,
        ]);

        $message = (new PrizeWonNotification($disbursement, 'First Prize', '000042'))
            ->toMail(new \stdClass());
        $copy = implode(' ', $message->introLines);

        self::assertSame('Prize status update', $message->subject);
        self::assertStringContainsString('reserved', $copy);
        self::assertStringContainsString('does not by itself confirm', $copy);
        self::assertStringNotContainsString('credited directly', $copy);
        self::assertStringNotContainsString('ready for withdrawal', $copy);
        self::assertSame(url('/bets'), $message->actionUrl);
    }
}
