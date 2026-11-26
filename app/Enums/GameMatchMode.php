<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * HOW A PLAYER'S DIGITS ARE COMPARED WITH THE DRAWN DIGITS.
 *
 *   exact      the selection must equal the drawn digits character for character,
 *              including length and leading zeros: "0042" wins on "0042" and
 *              loses on "42", "0420" and "4200".
 *   any_order  the selection must be a permutation of the drawn digits,
 *              multiplicity included: "0042" wins on "4200" (the two zeros are
 *              interchangeable, the 4 and the 2 move), "0042" and "420" do not
 *              win on "0004" because the multiset differs.
 *   prefix     the selection must match the leading digits of the drawn value:
 *              "04" wins on "0421". A shorter drawn value never wins.
 *   suffix     the selection must match the trailing digits of the drawn value:
 *              "21" wins on "0421". This is the single most common digit-game
 *              market shape ("last two") and is the one a naive endsWith on an
 *              int would get wrong for "0042".
 *
 * WHY THIS ENUM IS ONLY STRUCTURE
 * The comparison itself lives in App\Services\LotteryGames\DigitPrizeMatchingEngine,
 * which is where leading-zero safety, length agreement and the returned
 * diagnostic payload live. This enum declares the four modes the framework
 * understands and the structural facts about them (does order matter, must the
 * lengths agree). It does not compare anything, so there is exactly one place to
 * audit when a payout is disputed.
 *
 * WHY NO 'range', 'odd_even' OR 'sum' MODE
 * Those are real markets in other jurisdictions, but they are not in this
 * batch's tree and inventing them would mean inventing their payout rules.
 * A game that needs one is a configuration change plus a new mode and a new
 * matching branch, deliberately, under review — not a guess here.
 *
 * DIGITS ARE STRINGS
 * Nothing here casts a selection to int. "0042" and "42" are different
 * selections in exact mode and this enum exists partly to make that explicit.
 */
enum GameMatchMode: string
{
    case Exact = 'exact';
    case AnyOrder = 'any_order';
    case Prefix = 'prefix';
    case Suffix = 'suffix';

    /**
     * Human readable name for admin screens and the rules report.
     */
    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Exact order',
            self::AnyOrder => 'Any order',
            self::Prefix => 'Leading digits (prefix)',
            self::Suffix => 'Trailing digits (suffix)',
        };
    }

    /**
     * Whether the position of each digit matters.
     *
     * True for exact, prefix and suffix; false for any_order. DigitPrizeMatchingEngine
     * consults this rather than re-deciding per mode.
     */
    public function positionMatters(): bool
    {
        return $this !== self::AnyOrder;
    }

    /**
     * Whether the selection and the drawn value must have the SAME length.
     *
     * True for exact and any_order, where the selection is the whole ticket.
     * False for prefix and suffix, where the selection is deliberately a slice
     * of the drawn value and its own length is the slice length.
     */
    public function requiresEqualLength(): bool
    {
        return $this === self::Exact || $this === self::AnyOrder;
    }

    /**
     * Whether the selection may be shorter than the drawn value.
     *
     * The complement of requiresEqualLength(), named separately because the
     * validator reads this question, not the negation of another question, and
     * a future mode may allow longer selections without being equal-length.
     */
    public function allowsShorterSelection(): bool
    {
        return $this === self::Prefix || $this === self::Suffix;
    }

    /**
     * Resolve a configured value without throwing.
     *
     * Unknown modes return null, and every caller turns null into a
     * REQUIRED_INPUT/PROBLEM report rather than a default mode. Guessing a match
     * mode would change what wins.
     */
    public static function of(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? self::tryFrom(trim($value)) : null;
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
