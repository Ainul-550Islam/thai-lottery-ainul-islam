<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Enums\Currency;
use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use App\Notifications\WithdrawalStatusNotification;
use Tests\TestCase;

final class WithdrawalStatusNotificationTest extends TestCase
{
    public function test_completed_notice_does_not_promise_provider_arrival_time(): void
    {
        $withdrawal = new Withdrawal([
            'amount' => '50.00',
            'currency' => Currency::THB,
            'status' => WithdrawalStatus::Completed,
            'reference_number' => 'WD-TEST-50',
        ]);

        $message = (new WithdrawalStatusNotification($withdrawal, 'processing', 'completed'))
            ->toMail(new \stdClass());
        $copy = implode(' ', $message->introLines);

        self::assertStringContainsString('recorded as completed', $copy);
        self::assertStringContainsString('destination account or provider statement', $copy);
        self::assertStringNotContainsString('within minutes', $copy);
        self::assertSame(url('/withdraw'), $message->actionUrl);
    }

    public function test_failed_notice_does_not_make_unverified_wallet_restoration_claim(): void
    {
        $withdrawal = new Withdrawal([
            'amount' => '50.00',
            'currency' => Currency::THB,
            'status' => WithdrawalStatus::Failed,
            'reference_number' => 'WD-TEST-50',
        ]);

        $message = (new WithdrawalStatusNotification($withdrawal, 'processing', 'failed'))
            ->toMail(new \stdClass());
        $copy = implode(' ', $message->introLines);

        self::assertStringContainsString('recorded as failed', $copy);
        self::assertStringNotContainsString('restored to your wallet', $copy);
    }
}
