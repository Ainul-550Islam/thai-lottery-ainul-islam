<?php

namespace App\Enums;

/**
 * The draw-scheduling vocabulary.
 *
 * WHY THESE HELPERS READ CONFIGURATION
 *
 * `digits()`, `maxNumber()`, `minNumber()` and `payoutMultiplier()` used to be hard-coded match
 * arms, and they disagreed with `config/lottery.php` for two of the four families: `Tod` claimed 2
 * digits up to 99 where configuration says 3 digits up to 999, and `Run` claimed 2 digits up to 99
 * where configuration says 1 digit up to 9. The admin catalogue page reported the disagreement as
 * "latent drift" because no caller read these helpers — which is true, and also the reason it went
 * unnoticed for so long.
 *
 * A second copy of a money rule is a defect even when nothing reads it yet: the next caller would
 * have inherited the wrong number and would have had no way to know. So there is now exactly ONE
 * source — the configuration the engine actually reads — and these helpers are a typed view of it.
 *
 * The fallbacks below are the historical literals, used only when configuration is unavailable
 * (a bare enum unit test with no container). They are deliberately the same values `lottery.types`
 * ships, so a missing config degrades to today's behaviour instead of to zero.
 *
 * `LotteryCatalogueService::driftFor()` still re-checks these helpers against configuration. That
 * is not redundant: it is the watchdog that fails loudly if somebody re-introduces a literal.
 */
enum DrawType: string
{
    case TwoD = '2d';
    case ThreeD = '3d';
    case Tod = 'tod';
    case Run = 'run';

    public function label(): string
    {
        return match ($this) {
            self::TwoD => '2D Draw',
            self::ThreeD => '3D Draw',
            self::Tod => 'Tod Draw',
            self::Run => 'Run Draw',
        };
    }

    public function digits(): int
    {
        return $this->configuredInt('digits', match ($this) {
            self::TwoD => 2,
            self::ThreeD => 3,
            self::Tod => 3,
            self::Run => 1,
        });
    }

    public function payoutMultiplier(): int
    {
        return $this->configuredInt('payout_multiplier', match ($this) {
            self::TwoD => 90,
            self::ThreeD => 900,
            self::Tod => 45,
            self::Run => 12,
        });
    }

    public function maxNumber(): int
    {
        return $this->configuredInt('max_number', match ($this) {
            self::TwoD => 99,
            self::ThreeD, self::Tod => 999,
            self::Run => 9,
        });
    }

    public function minNumber(): int
    {
        return $this->configuredInt('min_number', 0);
    }

    /**
     * The configured integer for this draw type, or the historical literal when there is no
     * configuration to read.
     *
     * The key is the enum's own backing value, which is also the `BetType` and `lottery.types`
     * key — one vocabulary, three readers (`BetType`, `DrawType`, configuration).
     */
    private function configuredInt(string $field, int $fallback): int
    {
        if (! function_exists('config')) {
            return $fallback;
        }

        $value = config(sprintf('lottery.types.%s.%s', $this->value, $field));

        return is_numeric($value) ? (int) $value : $fallback;
    }
}
