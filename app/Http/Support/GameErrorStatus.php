<?php

declare(strict_types=1);

namespace App\Http\Support;

/**
 * The one table that maps a batch-2 error code to an HTTP status.
 *
 * WHY IT IS A CLASS AND NOT A TRAIT CONSTANT
 * The mapping started life as a private constant on the RendersGameResponses trait, which
 * made it untestable from outside: PHP refuses `SomeTrait::CONST`, and calling a trait's
 * static method resolves `self` to the trait rather than to a class. A mapping that four
 * controllers and the API documentation all depend on has to be assertable in isolation,
 * so it lives here and the trait delegates to it.
 *
 * THE STATUS CHOICES
 *   404  the caller named something that does not exist (game, draw, published result).
 *   409  the request is well formed but the CURRENT STATE refuses it: the window closed,
 *        the liability cap is full, the draw is already settled. A retry may succeed.
 *   422  the body itself is unusable: bad selection, bad stake, unfunded voucher. A retry
 *        with the same body fails again.
 *   503  the DEPLOYMENT cannot serve this: a REQUIRED_INPUT is still in configuration, the
 *        rules are unverified, the lane's master switch is off, a fixture was refused.
 *   400  an unmapped code, which is a defect: a visible 400 with the real code is better
 *        than a plausible lie.
 */
final class GameErrorStatus
{
    /**
     * @var array<string, int>
     */
    public const MAP = [
        'game_unknown' => 404,
        'game_draw_unavailable' => 404,
        'game_result_unavailable' => 404,

        'game_not_sellable' => 409,
        'game_draw_closed' => 409,
        'game_release_refused' => 409,
        'game_settlement_refused' => 409,
        'game_liability_exceeded' => 409,

        'game_number_invalid' => 422,
        'game_funding_unavailable' => 422,
        'game_publication_bridge_invalid' => 422,
        // A result payload the platform refused to accept - wrong width, non-digits, or (for
        // the calendar) a month that is not one - is a CLIENT error: the body or query is
        // unusable and resending it unchanged will fail identically. It spent a while
        // falling through to the 400 fallback, which by this table's own rule marks an
        // unmapped code as a defect; the calendar suite found it.
        'game_result_payload_invalid' => 422,
        // A REPLAYED key returns the original bet (200); a key reused for a DIFFERENT
        // bet is a client error, not a conflict with the server's state.
        'game_idempotency_key_required' => 422,
        'game_idempotency_key_reused' => 422,
        'stake_not_positive' => 422,
        'stake_below_minimum' => 422,
        'stake_above_maximum' => 422,
        'voucher_value_exceeded' => 422,
        'voucher_required' => 422,
        'voucher_value_unknown' => 422,

        'game_not_configured' => 503,
        'game_result_source_not_configured' => 503,
        'game_lane_disabled' => 503,
        'game_rules_unverified' => 503,
        'game_rules_checksum_stale' => 503,
        'game_fixture_refused' => 503,
    ];

    public const FALLBACK = 400;

    /**
     * The HTTP status for a batch-2 error code.
     */
    public static function for(string $code): int
    {
        return self::MAP[$code] ?? self::FALLBACK;
    }

    /**
     * Every code this lane can return, for the API contract document.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::MAP);
    }
}
