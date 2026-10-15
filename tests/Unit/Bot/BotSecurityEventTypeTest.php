<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Enums\SecurityEventType;
use App\Enums\SecurityRiskLevel;
use PHPUnit\Framework\TestCase;

final class BotSecurityEventTypeTest extends TestCase
{
    public function test_bot_vocabulary_is_round_trip_safe_and_has_review_floors(): void
    {
        self::assertSame(SecurityEventType::BotSuspected, SecurityEventType::from('bot_suspected'));
        self::assertSame(SecurityEventType::BotChallenged, SecurityEventType::from('bot_challenged'));
        self::assertSame(SecurityEventType::BotBlocked, SecurityEventType::from('bot_blocked'));
        self::assertSame(SecurityRiskLevel::Medium, SecurityEventType::BotSuspected->defaultFloor());
        self::assertSame(SecurityRiskLevel::Medium, SecurityEventType::BotChallenged->defaultFloor());
        self::assertSame(SecurityRiskLevel::High, SecurityEventType::BotBlocked->defaultFloor());
    }

    public function test_existing_values_and_floors_are_unchanged(): void
    {
        $previous = ['login_succeeded', 'login_failed', 'logout', 'mfa_challenge_issued', 'mfa_verified', 'mfa_failed', 'mfa_locked', 'password_changed', 'device_registered', 'device_trusted', 'device_revoked', 'session_issued', 'session_revoked', 'session_rotated', 'session_expired', 'suspicious_authentication', 'security_change'];
        foreach ($previous as $value) {
            $case = SecurityEventType::from($value);
            $expected = match ($value) {
                'suspicious_authentication', 'mfa_locked' => SecurityRiskLevel::High,
                'login_failed', 'mfa_failed' => SecurityRiskLevel::Medium,
                default => SecurityRiskLevel::Low,
            };
            self::assertSame($value, $case->value);
            self::assertSame($expected, $case->defaultFloor());
        }
        $values = array_column(SecurityEventType::cases(), 'value');
        self::assertCount(count($values), array_unique($values));
    }
}
