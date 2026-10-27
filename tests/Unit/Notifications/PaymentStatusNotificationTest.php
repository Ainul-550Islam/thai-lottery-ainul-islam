<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Enums\Currency;
use App\Enums\PaymentChannel;
use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Models\PaymentTransaction;
use App\Notifications\PaymentStatusNotification;
use Tests\TestCase;

final class PaymentStatusNotificationTest extends TestCase
{
    public function test_captured_notice_reports_status_without_claiming_wallet_availability(): void
    {
        $transaction = new PaymentTransaction([
            'amount' => '25.00',
            'currency' => Currency::THB,
            'channel' => PaymentChannel::BANK_TRANSFER,
            'direction' => PaymentDirection::Deposit,
            'status' => PaymentStatus::Captured,
            'reference_id' => 'PAY-REF-25',
        ]);

        $message = (new PaymentStatusNotification($transaction, 'pending', 'captured'))
            ->toMail(new \stdClass());
        $copy = implode(' ', $message->introLines);

        self::assertStringContainsString('recorded as captured', $copy);
        self::assertStringContainsString('wallet status', $copy);
        self::assertStringNotContainsString('credited to your wallet', $copy);
        self::assertSame(url('/wallet'), $message->actionUrl);
    }

    public function test_failed_notice_does_not_claim_no_funds_were_debited(): void
    {
        $transaction = new PaymentTransaction([
            'amount' => '25.00',
            'currency' => Currency::THB,
            'channel' => PaymentChannel::BANK_TRANSFER,
            'direction' => PaymentDirection::Deposit,
            'status' => PaymentStatus::Failed,
            'reference_id' => 'PAY-REF-25',
        ]);

        $message = (new PaymentStatusNotification($transaction, 'pending', 'failed'))
            ->toMail(new \stdClass());
        $copy = implode(' ', $message->introLines);

        self::assertStringContainsString('recorded as failed', $copy);
        self::assertStringNotContainsString('No funds were debited', $copy);
    }
}
