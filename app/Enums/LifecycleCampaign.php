<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle email campaigns (D4).
 *
 * A 2026 audit found five transactional notifications and no lifecycle
 * messaging at all: no welcome, no first-deposit nudge, no draw reminder, no
 * win-back, no grade promotion. Every one of those is a moment where a player
 * has told you something about themselves and the product says nothing back.
 *
 * WHAT A CAMPAIGN IS
 * A campaign is a *standing query*: "everyone who registered 24 hours ago and
 * has not deposited". It is not an event. That distinction drives the whole
 * design — campaigns are evaluated on a schedule against the current state of
 * the database, which is why idempotency has to be enforced in the database
 * rather than in memory (see the migration).
 *
 * WHAT A CAMPAIGN IS NOT
 * It is not a broadcast. Every send is addressed to one player because of
 * something true about that player. There is deliberately no way to define an
 * arbitrary segment here; adding one should be a deliberate change to this
 * file, not a config value.
 */
enum LifecycleCampaign: string
{
    /** Registered, has not placed a first bet. */
    case Welcome = 'welcome';

    /** Registered and funded nothing yet: the biggest single drop in the funnel. */
    case DepositNudge = 'deposit_nudge';

    /** A draw they can enter closes soon. Reuses the existing countdown data. */
    case DrawReminder = 'draw_reminder';

    /** Active once, silent for a long time. */
    case Winback = 'winback';

    /** Reached a new account grade. */
    case GradePromotion = 'grade_promotion';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Welcome => 'Welcome',
            self::DepositNudge => 'First-deposit nudge',
            self::DrawReminder => 'Draw reminder',
            self::Winback => 'Win-back',
            self::GradePromotion => 'Grade promotion',
        };
    }

    /**
     * The notification event type this campaign sends as.
     *
     * Each campaign gets its own event type rather than reusing `Manual`, so a
     * player can opt out of "draw reminders" without opting out of everything,
     * and so the delivery logs say which campaign a message belonged to.
     */
    public function eventType(): NotificationEventType
    {
        return match ($this) {
            self::Welcome => NotificationEventType::LifecycleWelcome,
            self::DepositNudge => NotificationEventType::LifecycleDepositNudge,
            self::DrawReminder => NotificationEventType::LifecycleDrawReminder,
            self::Winback => NotificationEventType::LifecycleWinback,
            self::GradePromotion => NotificationEventType::LifecycleGradePromotion,
        };
    }

    /**
     * Whether this campaign is marketing rather than service messaging.
     *
     * This matters legally, not cosmetically. Service mail ("your withdrawal
     * was paid") may generally be sent to anyone with an account. Marketing
     * mail may not be sent to a self-excluded player — doing so is a
     * responsible-gaming failure, not just a spam complaint, and regulators
     * treat it as such.
     *
     * Every campaign here is promotional, so every campaign is gated on the
     * self-exclusion check. The method exists so that a genuinely
     * non-promotional campaign added later has to answer the question.
     */
    public function isPromotional(): bool
    {
        return true;
    }
}
