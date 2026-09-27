<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of one attempt to state a draw's result.
 *
 * SHARED ACROSS PRODUCT LANES (promoted in PROMPT 6)
 * ---------------------------------------------------------------------------
 * PROMPT 5 introduced this as NationalLotteryVersionState because no existing
 * enum in the repository carried a Conflict case - DrawResultStatus,
 * DrawCertificationStatus and DrawConfirmationStatus all lack one. PROMPT 6
 * needs the identical lifecycle for the Weekly lane, so the enum was renamed
 * rather than copied: two enums would be two definitions of what "conflict"
 * means, and the first drift between them would let one lane publish
 * something the other would have blocked.
 *
 * The cases and their values are unchanged from PROMPT 5; only the class name
 * moved, and every National reference was updated in the same commit.
 *
 * PENDING    stored, not yet trusted for publication.
 * VERIFIED   validated and eligible to answer the public surface.
 * CONFLICT   disagrees with an already-verified version about the numbers.
 *            Publication is blocked until a human resolves it. This is the
 *            case that does not exist anywhere else in the repository.
 * SUPERSEDED replaced by a later version. Retained forever, never deleted,
 *            so a correction can be shown as a correction.
 * REJECTED   failed validation and will never be published.
 */
enum ResultVersionState: string
{
    /** Imported and stored, not yet accepted as the draw's answer. */
    case Pending = 'pending';

    /** Accepted as the draw's current answer. Publishable. */
    case Verified = 'verified';

    /**
     * A second payload disagreed with an existing one for the same draw.
     * Publication is blocked until an authorised operator records a decision.
     */
    case Conflict = 'conflict';

    /** Replaced by a later version. Retained forever as history. */
    case Superseded = 'superseded';

    /** Failed field validation. Never publishable, retained as evidence. */
    case Rejected = 'rejected';

    /**
     * May a version in this state ever back a public result?
     */
    public function isPublishable(): bool
    {
        return $this === self::Verified;
    }

    /**
     * Does this state block the draw from being published at all?
     */
    public function blocksPublication(): bool
    {
        return $this === self::Conflict || $this === self::Rejected;
    }

    public function isTerminal(): bool
    {
        return $this === self::Superseded || $this === self::Rejected;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Verified, self::Conflict, self::Rejected],
            self::Verified => [self::Superseded, self::Conflict],
            self::Conflict => [self::Verified, self::Rejected],
            self::Superseded => [],
            self::Rejected => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
