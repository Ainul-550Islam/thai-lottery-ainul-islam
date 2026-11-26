<?php

declare(strict_types=1);

namespace App\DTOs\LotteryGames;

use App\Enums\GameMatchMode;
use App\Enums\GameRulesStatus;

/**
 * One game's rules, read from config/lottery_games.php and validated into an
 * immutable value object.
 *
 * DESIGN CONTRACT
 *  - This object NEVER trusts its input. Every accessor that returns a number
 *    returns null when the configured value is the REQUIRED_INPUT placeholder,
 *    so a caller cannot accidentally do arithmetic on a placeholder that looks
 *    like a string.
 *  - It holds no behaviour beyond projection and inspection: matching, payout
 *    and RTP live in their own services.
 *  - `raw()` is kept so the verification checksum can be computed over exactly
 *    what the operator wrote, including the placeholders.
 *
 * WHY REQUIRED_INPUT IS A STRING AND NOT null
 * A missing key and an operator-declared-but-unknown value are different facts.
 * Writing the placeholder makes the gap explicit in the file, greppable in CI,
 * and printable to the operator instead of presenting as an empty field that
 * looks like a formatting bug.
 */
final readonly class GameDefinition
{
    /**
     * The literal placeholder an operator replaces with a real decision.
     */
    public const REQUIRED_INPUT = 'REQUIRED_INPUT';

    /**
     * The keys a game block must carry before it can be a rule set at all.
     *
     * WHY THIS LIST LIVES ON THE DTO
     * Two very different readers need the same answer to "is this block complete": the
     * rules importer (which refuses a half-filled operator document) and the profile
     * resolver (which refuses to apply a partial layer). If each kept its own copy, a key
     * added here would be enforced by one and silently ignored by the other — and the
     * resolver's "all-or-nothing per game" promise would quietly become "all-or-nothing
     * for the keys one caller happened to know about". So the list is stated once, on the
     * object that already owns the shape of a definition.
     *
     * Identity keys (`code`, `name`) and the declared status (`rules_status`) are not here:
     * they are set by the deployment or by the operator's row, never by an input document.
     */
    public const REQUIRED_CONFIG_KEYS = [
        'digits',
        'timezone',
        'draw_cron',
        'cutoff_seconds',
        'bet_types',
        'min_stake',
        'max_stake',
        'max_liability_per_draw',
        'max_liability_per_number',
        'settlement_market_key',
        'publication_bridge',
        'result_source',
    ];

    /**
     * Whether a raw configuration block could serve as a rule set: every required key is
     * present AND no value is still the literal REQUIRED_INPUT placeholder.
     *
     * Both halves matter. A block with no placeholders but a missing `cutoff_seconds` is a
     * partially typed document; a block with every key but a placeholder multiplier is a
     * question, not an answer. Neither may be applied as if a human had finished it.
     *
     * @param  array<string, mixed>  $block
     */
    public static function isCompleteBlock(array $block): bool
    {
        foreach (self::REQUIRED_CONFIG_KEYS as $key) {
            if (! array_key_exists($key, $block)) {
                return false;
            }
        }

        return ! self::containsRequiredInput($block);
    }

    /**
     * Whether the literal placeholder appears anywhere in a raw block, at any depth.
     *
     * @param  array<string, mixed>  $node
     */
    public static function containsRequiredInput(array $node): bool
    {
        foreach ($node as $value) {
            if (is_array($value)) {
                if (self::containsRequiredInput($value)) {
                    return true;
                }

                continue;
            }

            if (is_string($value) && trim($value) === self::REQUIRED_INPUT) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, array{selection_digits: int|null, match_mode: GameMatchMode|null, payout_multiplier: string|null}>  $betTypes
     * @param  list<string>  $drawCron
     * @param  array<string, mixed>  $resultSource
     * @param  array<string, mixed>  $publicationBridge
     */
    private function __construct(
        public string $code,
        public string $name,
        public ?int $digits,
        public string $timezone,
        public array $drawCron,
        public ?int $cutoffSeconds,
        public array $betTypes,
        public ?string $minStake,
        public ?string $maxStake,
        public ?string $maxLiabilityPerDraw,
        public ?string $maxLiabilityPerNumber,
        public GameRulesStatus $declaredRulesStatus,
        public ?string $settlementMarketKey,
        public array $resultSource,
        public array $publicationBridge,
        public array $raw,
    ) {}

    /**
     * Build from one entry of config('lottery_games.games').
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $code, array $config): self
    {
        $betTypes = [];

        foreach ((array) ($config['bet_types'] ?? []) as $betType => $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $betTypes[(string) $betType] = [
                'selection_digits' => self::intOrNull($rule['selection_digits'] ?? null),
                'match_mode' => self::matchModeOrNull($rule['match_mode'] ?? null),
                'payout_multiplier' => self::stringOrNull($rule['payout_multiplier'] ?? null),
            ];
        }

        $cron = [];

        foreach ((array) ($config['draw_cron'] ?? []) as $expression) {
            if (is_string($expression) && trim($expression) !== '') {
                $cron[] = trim($expression);
            }
        }

        return new self(
            code: $code,
            name: (string) ($config['name'] ?? $code),
            digits: self::intOrNull($config['digits'] ?? null),
            timezone: (string) ($config['timezone'] ?? 'UTC'),
            drawCron: $cron,
            cutoffSeconds: self::intOrNull($config['cutoff_seconds'] ?? null),
            betTypes: $betTypes,
            minStake: self::stringOrNull($config['min_stake'] ?? null),
            maxStake: self::stringOrNull($config['max_stake'] ?? null),
            maxLiabilityPerDraw: self::stringOrNull($config['max_liability_per_draw'] ?? null),
            maxLiabilityPerNumber: self::stringOrNull($config['max_liability_per_number'] ?? null),
            declaredRulesStatus: GameRulesStatus::tryFrom((string) ($config['rules_status'] ?? '')) ?? GameRulesStatus::Unverified,
            settlementMarketKey: self::stringOrNull($config['settlement_market_key'] ?? null),
            resultSource: is_array($config['result_source'] ?? null) ? $config['result_source'] : [],
            publicationBridge: is_array($config['publication_bridge'] ?? null) ? $config['publication_bridge'] : [],
            raw: $config,
        );
    }

    /**
     * Every placeholder still present in this game's configuration, as
     * dotted paths an operator can act on.
     *
     * @return list<string>
     */
    public function requiredInputs(): array
    {
        $missing = [];
        self::walk($this->raw, '', $missing);

        return $missing;
    }

    public function hasRequiredInput(): bool
    {
        return $this->requiredInputs() !== [];
    }

    /**
     * sha256 of the canonicalised config block.
     *
     * Used by the verification stamp: an operator verifying the rules signs
     * this hash, and any later edit to the block changes the hash, which
     * auto-revokes the verification without anybody having to remember.
     */
    public function checksum(): string
    {
        return hash('sha256', (string) json_encode(self::canonicalise($this->raw)));
    }

    /**
     * @return list<string>
     */
    public function betTypeKeys(): array
    {
        return array_keys($this->betTypes);
    }

    public function hasBetType(string $betType): bool
    {
        return array_key_exists($betType, $this->betTypes);
    }

    public function selectionDigitsFor(string $betType): ?int
    {
        return $this->betTypes[$betType]['selection_digits'] ?? null;
    }

    public function matchModeFor(string $betType): ?GameMatchMode
    {
        return $this->betTypes[$betType]['match_mode'] ?? null;
    }

    public function payoutMultiplierFor(string $betType): ?string
    {
        return $this->betTypes[$betType]['payout_multiplier'] ?? null;
    }

    /**
     * The EXISTING config('lottery.markets') key this bet type is bought and
     * settled through.
     *
     * WHY A GAME NEEDS ONE AT ALL
     * The batch-2 lane does not have its own purchase or settlement engine. A
     * game bet is bought by the existing purchase pipeline and settled by the
     * existing resolver, both of which speak market keys. Declaring the mapping
     * here — as data, per bet type, with a game-level default — is what lets
     * this batch reuse those lanes instead of copying them.
     *
     * A per-bet-type override is allowed because one game commonly sells a 4D
     * exact market and a 2D suffix market, which are different markets in the
     * existing configuration.
     */
    public function marketKeyFor(string $betType): ?string
    {
        $override = $this->raw['bet_types'][$betType]['market_key'] ?? null;

        if (is_string($override)) {
            $trimmed = trim($override);

            if ($trimmed !== '' && $trimmed !== self::REQUIRED_INPUT) {
                return $trimmed;
            }
        }

        return $this->settlementMarketKey;
    }

    /**
     * The digits every selection must contain: the widest bet type in the game.
     *
     * A game's draw result is as long as its longest market's selection; a
     * shorter market is matched inside it (prefix/suffix).
     */
    public function resultDigitCount(): ?int
    {
        $max = null;

        foreach ($this->betTypes as $rule) {
            $digits = $rule['selection_digits'];
            if ($digits !== null && ($max === null || $digits > $max)) {
                $max = $digits;
            }
        }

        return $max ?? $this->digits;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function canonicalise(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalise($item);
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $missing
     */
    private static function walk(array $node, string $prefix, array &$missing): void
    {
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                self::walk($value, $path, $missing);

                continue;
            }

            if (is_string($value) && trim($value) === self::REQUIRED_INPUT) {
                $missing[] = $path;
            }
        }
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '' || $trimmed === self::REQUIRED_INPUT) {
                return null;
            }

            return $trimmed;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private static function matchModeOrNull(mixed $value): ?GameMatchMode
    {
        if (! is_string($value)) {
            return null;
        }

        return GameMatchMode::tryFrom(trim($value));
    }
}
