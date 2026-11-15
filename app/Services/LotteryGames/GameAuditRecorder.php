<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\Models\GameAuditEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * THE ONLY WRITER OF game_audit_events.
 *
 * WHAT IT IS FOR
 * The lane's decisions — a bet placed, a placement that failed after it reserved
 * liability, a rule set verified, a game suspended / resumed / switched on or off, a
 * result staged or published, a reservation released, a rules document installed — are
 * each recorded here once, with who, when, and the facts they rested on. It is the
 * lane's answer to "who decided that, and on what basis?".
 *
 * WHY record() NEVER THROWS
 * Most callers record AFTER the money or the state has already moved: the bet exists,
 * the wallet is debited, the checksum is stamped. If the audit insert threw there, a
 * player would see a failure for a purchase that succeeded, and a retry would be
 * indistinguishable from a new attempt. So a failed audit write is logged at critical
 * level with the full event payload (so an operator can replay it by hand) and the
 * caller carries on. record() returns whether the row was stored, so the one caller that
 * must care (none today) can.
 *
 * WHY THE EVENT NAMES ARE CONSTANTS
 * A string typed in three places is three spellings. The names below are the closed
 * vocabulary of the trail; an auditor's query and a test both reference them from here.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * It does not decide whether an event is worth recording, deduplicate, or batch. It
 * stores exactly one row per call.
 */
final class GameAuditRecorder
{
    public const BET_PLACED = 'bet.placed';

    public const BET_PLACEMENT_FAILED = 'bet.placement_failed';

    public const BET_ATTRIBUTION_RECOVERED = 'bet.attribution_recovered';

    public const BET_ATTRIBUTION_FAILED = 'bet.attribution_failed';

    public const BET_EXPOSURE_RELEASED = 'bet.exposure_released';

    public const RULES_VERIFIED = 'rules.verified';

    public const RULES_VERIFICATION_REFUSED = 'rules.verification_refused';

    public const RULES_OVERRIDE_INSTALLED = 'rules.override_installed';

    public const GAME_SUSPENDED = 'game.suspended';

    public const GAME_RESUMED = 'game.resumed';

    public const GAME_ENABLED = 'game.enabled';

    public const GAME_DISABLED = 'game.disabled';

    public const RESULT_STAGED = 'result.staged';

    public const RESULT_PUBLISHED = 'result.published';

    public const CASHBACK_REVERSAL_UNAVAILABLE = 'cashback.reversal_unavailable';

    public const CASHBACK_REVERSAL_FAILED = 'cashback.reversal_failed';

    /**
     * @return list<string> every event name the trail may contain
     */
    public static function vocabulary(): array
    {
        return [
            self::BET_PLACED,
            self::BET_PLACEMENT_FAILED,
            self::BET_ATTRIBUTION_RECOVERED,
            self::BET_ATTRIBUTION_FAILED,
            self::BET_EXPOSURE_RELEASED,
            self::RULES_VERIFIED,
            self::RULES_VERIFICATION_REFUSED,
            self::RULES_OVERRIDE_INSTALLED,
            self::GAME_SUSPENDED,
            self::GAME_RESUMED,
            self::GAME_ENABLED,
            self::GAME_DISABLED,
            self::RESULT_STAGED,
            self::RESULT_PUBLISHED,
            self::CASHBACK_REVERSAL_UNAVAILABLE,
            self::CASHBACK_REVERSAL_FAILED,
        ];
    }

    /**
     * Store one audit row.
     *
     * @param  array<string, mixed>  $context  the facts the decision rested on; amounts as decimal strings
     * @return bool true when the row was stored
     */
    public function record(
        string $event,
        ?string $gameCode = null,
        ?int $actorId = null,
        ?string $subjectType = null,
        string|int|null $subjectId = null,
        array $context = [],
    ): bool {
        try {
            GameAuditEvent::query()->create([
                'event' => $event,
                'game_code' => $gameCode,
                'actor_id' => $actorId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId === null ? null : (string) $subjectId,
                'context' => $context,
                'occurred_at' => now(),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::critical('lottery_games.audit_write_failed', [
                'event' => $event,
                'game_code' => $gameCode,
                'actor_id' => $actorId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
