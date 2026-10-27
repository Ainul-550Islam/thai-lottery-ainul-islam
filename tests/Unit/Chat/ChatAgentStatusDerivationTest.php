<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Enums\Chat\ChatAgentStatus;
use PHPUnit\Framework\TestCase;

/**
 * Presence derivation is the rule that keeps the queue honest: an agent is
 * online only while their console's heartbeat is fresh. These tests pin the
 * thresholds' EDGE behaviour, because the failure mode they guard is the
 * visitor-visible one — a green dot with nobody behind it.
 *
 * The function under test is deliberately pure (age in, status out) so the
 * rule is testable without a database and the widget counter, the router and
 * the console header can never disagree about what "online" means.
 */
final class ChatAgentStatusDerivationTest extends TestCase
{
    private const AWAY_AFTER = 90;
    private const OFFLINE_AFTER = 240;

    public function test_fresh_heartbeat_is_online(): void
    {
        $this->assertSame(
            ChatAgentStatus::Online,
            ChatAgentStatus::fromHeartbeatAge(0, self::AWAY_AFTER, self::OFFLINE_AFTER),
        );

        $this->assertSame(
            ChatAgentStatus::Online,
            ChatAgentStatus::fromHeartbeatAge(self::AWAY_AFTER, self::AWAY_AFTER, self::OFFLINE_AFTER),
            'exactly at the away threshold is still Online (boundary inclusive)',
        );
    }

    public function test_stale_heartbeat_is_away(): void
    {
        $this->assertSame(
            ChatAgentStatus::Away,
            ChatAgentStatus::fromHeartbeatAge(self::AWAY_AFTER + 1, self::AWAY_AFTER, self::OFFLINE_AFTER),
        );

        $this->assertSame(
            ChatAgentStatus::Away,
            ChatAgentStatus::fromHeartbeatAge(self::OFFLINE_AFTER, self::AWAY_AFTER, self::OFFLINE_AFTER),
            'exactly at the offline threshold is still Away (boundary inclusive)',
        );
    }

    public function test_old_heartbeat_is_offline(): void
    {
        $this->assertSame(
            ChatAgentStatus::Offline,
            ChatAgentStatus::fromHeartbeatAge(self::OFFLINE_AFTER + 1, self::AWAY_AFTER, self::OFFLINE_AFTER),
        );
    }

    public function test_missing_heartbeat_is_offline(): void
    {
        $this->assertSame(
            ChatAgentStatus::Offline,
            ChatAgentStatus::fromHeartbeatAge(null, self::AWAY_AFTER, self::OFFLINE_AFTER),
            'an agent who never checked in must not appear present',
        );
    }

    public function test_future_heartbeat_is_treated_as_offline(): void
    {
        // A clock-skewed console must not be able to appear permanently
        // present. Offline is the safe read for a timestamp in the future.
        $this->assertSame(
            ChatAgentStatus::Offline,
            ChatAgentStatus::fromHeartbeatAge(-5, self::AWAY_AFTER, self::OFFLINE_AFTER),
        );
    }

    public function test_only_online_agents_are_assignable(): void
    {
        $this->assertTrue(ChatAgentStatus::Online->isAssignable());
        $this->assertFalse(ChatAgentStatus::Away->isAssignable());
        $this->assertFalse(ChatAgentStatus::Offline->isAssignable());
    }

    public function test_visitor_availability_matches_assignability(): void
    {
        foreach (ChatAgentStatus::cases() as $status) {
            $this->assertSame(
                $status === ChatAgentStatus::Online,
                $status->isAvailableToVisitors(),
                'isAvailableToVisitors disagrees for '.$status->value,
            );
        }
    }
}
