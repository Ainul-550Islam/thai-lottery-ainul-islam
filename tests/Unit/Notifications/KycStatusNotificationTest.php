<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Enums\KycStatus;
use App\Notifications\KycStatusNotification;
use Tests\TestCase;

final class KycStatusNotificationTest extends TestCase
{
    public function test_verified_notice_does_not_promise_unrestricted_features(): void
    {
        $message = (new KycStatusNotification(KycStatus::Verified->value))
            ->toMail(new \stdClass());
        $copy = implode(' ', $message->introLines);

        self::assertStringContainsString('marked approved', $copy);
        self::assertStringContainsString('does not guarantee', $copy);
        self::assertStringNotContainsString('fully approved for enhanced limits', $copy);
        self::assertStringNotContainsString('fast withdrawal', $copy);
        self::assertSame(url('/account/verification'), $message->actionUrl);
    }

    public function test_pending_notice_does_not_promise_a_review_deadline(): void
    {
        $message = (new KycStatusNotification(KycStatus::UnderReview->value))
            ->toMail(new \stdClass());
        $copy = implode(' ', $message->introLines);

        self::assertStringContainsString('no fixed completion time is promised', $copy);
        self::assertStringNotContainsString('within 24 hours', $copy);
    }
}
