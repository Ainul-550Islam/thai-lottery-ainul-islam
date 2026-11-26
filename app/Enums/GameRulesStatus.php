<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether an operator has VERIFIED the official rules of a digit game.
 *
 * THREE STATES, AND WHY EACH EXISTS
 *   Unverified  the game exists in the registry and in config/lottery_games.php,
 *               but an operator has not confirmed that the configured digit
 *               count, bet types, multipliers, draw times and result source
 *               match the official rules document. Everything about the game is
 *               known structurally and nothing about it may be sold. This is the
 *               state every shipped game starts in, because Rule §0.3 forbids
 *               inventing official rules: the honest default is "we have not
 *               been told yet".
 *   Verified    an operator has confirmed the rules, and the confirmation is
 *               stamped together with a checksum of the exact configuration
 *               block it was made against. Verification is therefore a fact
 *               about a SPECIFIC rule set, not about a game in the abstract.
 *   Suspended   the rules were verified once and are no longer trusted: a
 *               regulator changed the payout table, a result feed proved
 *               unreliable, or an operator pulled the game. Suspension keeps the
 *               audit trail (who verified, when, against which checksum) while
 *               withdrawing the right to sell.
 *
 * WHY SUSPENDED IS NOT "UNVERIFIED"
 * Unverified means "no decision has been recorded". Suspended means "a decision
 * was recorded and then withdrawn". Collapsing the two would erase the reason a
 * game stopped selling, which is exactly what an audit asks for. The stored
 * value differs, and a suspension can carry a reason in lottery_games.metadata
 * without touching this enum.
 *
 * WHERE THE STATUS LIVES AT RUNTIME
 * The lottery_games row is authoritative for a game that exists in the database
 * (it is written by an audited, permission-gated Filament action). The
 * rules_status value in config/lottery_games.php is only the DECLARATION an
 * operator writes when shipping a game, and it is compared against the row so
 * that a configuration change cannot silently re-verify a game that was
 * suspended. See GameRegistry for the comparison; this enum holds no database
 * knowledge of its own.
 *
 * NO RULES LIVE HERE
 * No multiplier, no digit count, no draw time, no stake bound. Those are read
 * from configuration and validated by GameRulesValidator. This enum only knows
 * which states permit selling.
 */
enum GameRulesStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Suspended = 'suspended';

    /**
     * Human readable name for admin screens, audit records and CLI reports.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Unverified',
            self::Verified => 'Verified',
            self::Suspended => 'Suspended',
        };
    }

    /**
     * Whether an operator has signed off on the rules of this game.
     *
     * True only for Verified. Suspended was verified once and is not verified
     * now, which is the whole point of the third state.
     */
    public function isVerified(): bool
    {
        return $this === self::Verified;
    }

    /**
     * Whether a game in this state may be sold at all.
     *
     * This is ONE gate out of nine (see GameAvailabilityService::GATE_ORDER).
     * Returning true here does not make a game purchasable: the deployment kill
     * switch, real-money admission, an open seated draw, configured liability
     * limits and published legal documents must all agree as well.
     */
    public function canSell(): bool
    {
        return $this === self::Verified;
    }

    /**
     * Whether a draw of this game may accept an official result.
     *
     * Unverified games have no trusted rule set, so a published result could not
     * be settled against anything. Suspended games may still ingest and stage a
     * result — an operator needs the numbers to reconcile what was already sold
     * before the suspension — but staging is not publishing and this method only
     * governs the sell/refuse decision the API makes.
     */
    public function acceptsResultPublication(): bool
    {
        return $this === self::Verified;
    }

    /**
     * Why a game in this state cannot be sold, in operator language.
     *
     * Null when it can. The string is stable and machine-checkable: the API
     * surfaces it as the capability's `reason`, and tests assert on it.
     */
    public function refusalReason(): ?string
    {
        return match ($this) {
            self::Verified => null,
            self::Unverified => 'game_rules_unverified',
            self::Suspended => 'game_rules_suspended',
        };
    }

    /**
     * Resolve a stored or configured value without throwing.
     *
     * Used where a value arrives from the database, from configuration or from
     * an admin form and must not crash a read path. An unknown value resolves to
     * Unverified, which fails closed: an unrecognised status can never sell.
     */
    public static function of(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            return self::tryFrom(trim($value)) ?? self::Unverified;
        }

        return self::Unverified;
    }

    /**
     * The states an operator may move to from this one, for the admin action
     * guards. Unverified -> Suspended is absent on purpose: there has to have
     * been a verification to withdraw.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Unverified => [self::Verified],
            self::Verified => [self::Suspended],
            self::Suspended => [self::Verified],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Every value, for validation rules that must not restate the enum.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
