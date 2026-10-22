<?php

declare(strict_types=1);

namespace App\Enums\Chat;

/*
|--------------------------------------------------------------------------
| Operator presence
|--------------------------------------------------------------------------
|
| Presence here is OBSERVED, not declared. An agent does not type "I am
| online"; the console heartbeats and ChatPresenceService derives the state
| from how long ago that heartbeat was:
|
|   Online   heartbeat within presence.away_after_seconds
|   Away     heartbeat older than that, still within offline_after_seconds
|   Offline  no heartbeat inside offline_after_seconds
|
| WHY IT IS DERIVED RATHER THAN SET
| A stored 'online' flag is a claim that survives a closed laptop, a dead
| browser tab and a crashed process. The visitor is shown a green dot and
| waits. Deriving the value from a heartbeat timestamp means the worst case
| of an agent disappearing is a dot that fades a minute later, instead of a
| visitor waiting forever behind a lie.
|
| The `Away` band exists so a brief gap — a page navigation, a laptop
| sleeping for 20 seconds — does not flicker the operator's own console or
| drop them out of the routing pool.
*/
enum ChatAgentStatus: string
{
    case Online = 'online';
    case Away = 'away';
    case Offline = 'offline';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Away => 'Away',
            self::Offline => 'Offline',
        };
    }

    /**
     * Whether an agent in this state may be handed a new conversation.
     * Away agents are excluded: they are probably not at the keyboard, and
     * routing a waiting visitor to them costs the visitor another wait.
     */
    public function isAssignable(): bool
    {
        return $this === self::Online;
    }

    /**
     * Whether an agent in this state should be counted as available when
     * the widget decides whether anyone can answer right now.
     */
    public function isAvailableToVisitors(): bool
    {
        return $this === self::Online;
    }

    /**
     * Derive the state from a heartbeat age in seconds.
     *
     * Pure function of (age, thresholds) so the rule is testable without a
     * database, and so the widget counter and the router can never disagree
     * about what "online" means.
     */
    public static function fromHeartbeatAge(?int $ageSeconds, int $awayAfter, int $offlineAfter): self
    {
        if ($ageSeconds === null) {
            return self::Offline;
        }

        if ($ageSeconds < 0) {
            // A heartbeat timestamp in the future is a clock problem, not an
            // availability signal. Treating it as Online would let a skewed
            // worker appear permanently present; Offline is the safe read.
            return self::Offline;
        }

        if ($ageSeconds <= $awayAfter) {
            return self::Online;
        }

        if ($ageSeconds <= $offlineAfter) {
            return self::Away;
        }

        return self::Offline;
    }
}
