<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\DTOs\Lottery\WinningEntryResult;
use App\Enums\LotteryPrizeTier;
use InvalidArgumentException;

class GloPrizeMatchingEngine
{
    /**
     * Default Payout Multipliers for 2D/3D Variations (Per 1.00 THB bet).
     */
    private const MULTIPLIER_3D_DIRECT = '900.00';
    private const MULTIPLIER_3D_TOAD = '150.00';
    private const MULTIPLIER_2D_TOP = '90.00';
    private const MULTIPLIER_2D_BOTTOM = '90.00';
    private const MULTIPLIER_RUNNING_TOP = '3.20';
    private const MULTIPLIER_RUNNING_BOTTOM = '4.20';

    /**
     * Standard GLO Official Ticket Fixed Payouts (Per standard 80.00 THB ticket).
     */
    private const GLO_PAYOUT_FIRST_PRIZE = '6000000.0000';
    private const GLO_PAYOUT_NEAR_FIRST = '100000.0000';
    private const GLO_PAYOUT_SECOND = '200000.0000';
    private const GLO_PAYOUT_THIRD = '80000.0000';
    private const GLO_PAYOUT_FOURTH = '40000.0000';
    private const GLO_PAYOUT_FIFTH = '20000.0000';
    private const GLO_PAYOUT_3D_FRONT = '4000.0000';
    private const GLO_PAYOUT_3D_BACK = '4000.0000';
    private const GLO_PAYOUT_2D_BOTTOM = '2000.0000';

    /**
     * Evaluate an entry against confirmed official GLO draw results.
     *
     * @param string $betType '6D_OFFICIAL', '3D_DIRECT', '3D_TOAD', '2D_TOP', '2D_BOTTOM', 'RUNNING_TOP', 'RUNNING_BOTTOM'
     * @param string $chosenNumber String representation of chosen digits with preserved leading zeros
     * @param string $betAmount Bet amount in THB formatted to 4 decimals
     * @param array{
     *     first_prize: string,
     *     three_digit_front: array<int, string>,
     *     three_digit_back: array<int, string>,
     *     two_digit_bottom: string,
     *     second_prizes?: array<int, string>,
     *     third_prizes?: array<int, string>,
     *     fourth_prizes?: array<int, string>,
     *     fifth_prizes?: array<int, string>
     * } $drawResult
     * @return WinningEntryResult
     */
    public function evaluateEntry(
        string $betType,
        string $chosenNumber,
        string $betAmount,
        array $drawResult
    ): WinningEntryResult {
        $firstPrize = str_pad((string) ($drawResult['first_prize'] ?? ''), 6, '0', STR_PAD_LEFT);
        $twoDigitBottom = str_pad((string) ($drawResult['two_digit_bottom'] ?? ''), 2, '0', STR_PAD_LEFT);
        $front3 = array_map(fn($n) => str_pad((string) $n, 3, '0', STR_PAD_LEFT), (array) ($drawResult['three_digit_front'] ?? []));
        $back3 = array_map(fn($n) => str_pad((string) $n, 3, '0', STR_PAD_LEFT), (array) ($drawResult['three_digit_back'] ?? []));

        return match ($betType) {
            '6D_OFFICIAL' => $this->evaluateOfficial6D($chosenNumber, $betAmount, $firstPrize, $front3, $back3, $twoDigitBottom, $drawResult),
            '3D_DIRECT' => $this->evaluate3dDirect($chosenNumber, $betAmount, $firstPrize),
            '3D_TOAD' => $this->evaluate3dToad($chosenNumber, $betAmount, $firstPrize),
            '2D_TOP' => $this->evaluate2dTop($chosenNumber, $betAmount, $firstPrize),
            '2D_BOTTOM' => $this->evaluate2dBottom($chosenNumber, $betAmount, $twoDigitBottom),
            'RUNNING_TOP' => $this->evaluateRunningTop($chosenNumber, $betAmount, $firstPrize),
            'RUNNING_BOTTOM' => $this->evaluateRunningBottom($chosenNumber, $betAmount, $twoDigitBottom),
            default => WinningEntryResult::nonWinner($betAmount),
        };
    }

    /**
     * Evaluates official 6-digit GLO ticket entries across all official tiers.
     */
    private function evaluateOfficial6D(
        string $chosenNumber,
        string $betAmount,
        string $firstPrize,
        array $front3,
        array $back3,
        string $twoDigitBottom,
        array $drawResult
    ): WinningEntryResult {
        $chosen = str_pad($chosenNumber, 6, '0', STR_PAD_LEFT);

        // 1. First Prize (รางวัลที่ 1)
        if ($chosen === $firstPrize) {
            return $this->buildWinningResult(LotteryPrizeTier::FIRST_PRIZE, $betAmount, '1.00', self::GLO_PAYOUT_FIRST_PRIZE, [$firstPrize], 'First Prize (รางวัลที่ 1)');
        }

        // 2. Near First Prize (±1)
        $firstPrizeInt = (int) $firstPrize;
        $near1 = str_pad((string) (($firstPrizeInt + 1) % 1000000), 6, '0', STR_PAD_LEFT);
        $near2 = str_pad((string) (($firstPrizeInt - 1 + 1000000) % 1000000), 6, '0', STR_PAD_LEFT);

        if ($chosen === $near1 || $chosen === $near2) {
            return $this->buildWinningResult(LotteryPrizeTier::NEAR_FIRST_PRIZE, $betAmount, '1.00', self::GLO_PAYOUT_NEAR_FIRST, [$chosen], 'Near First Prize (ข้างเคียงรางวัลที่ 1)');
        }

        // 3. Second to Fifth Prizes
        if (in_array($chosen, (array) ($drawResult['second_prizes'] ?? []), true)) {
            return $this->buildWinningResult(LotteryPrizeTier::SECOND_PRIZE, $betAmount, '1.00', self::GLO_PAYOUT_SECOND, [$chosen], 'Second Prize (รางวัลที่ 2)');
        }
        if (in_array($chosen, (array) ($drawResult['third_prizes'] ?? []), true)) {
            return $this->buildWinningResult(LotteryPrizeTier::THIRD_PRIZE, $betAmount, '1.00', self::GLO_PAYOUT_THIRD, [$chosen], 'Third Prize (รางวัลที่ 3)');
        }

        // 4. 3-Digit Front (3 ตัวหน้า)
        $chosenFront3 = substr($chosen, 0, 3);
        if (in_array($chosenFront3, $front3, true)) {
            return $this->buildWinningResult(LotteryPrizeTier::THREE_DIGIT_FRONT, $betAmount, '1.00', self::GLO_PAYOUT_3D_FRONT, [$chosenFront3], '3-Digit Front Prize (3 ตัวหน้า)');
        }

        // 5. 3-Digit Back (3 ตัวท้าย)
        $chosenBack3 = substr($chosen, 3, 3);
        if (in_array($chosenBack3, $back3, true)) {
            return $this->buildWinningResult(LotteryPrizeTier::THREE_DIGIT_BACK, $betAmount, '1.00', self::GLO_PAYOUT_3D_BACK, [$chosenBack3], '3-Digit Back Prize (3 ตัวท้าย)');
        }

        // 6. 2-Digit Bottom (2 ตัวท้าย)
        $chosenBottom2 = substr($chosen, 4, 2);
        if ($chosenBottom2 === $twoDigitBottom) {
            return $this->buildWinningResult(LotteryPrizeTier::TWO_DIGIT_BOTTOM, $betAmount, '1.00', self::GLO_PAYOUT_2D_BOTTOM, [$chosenBottom2], '2-Digit Bottom Prize (2 ตัวท้าย)');
        }

        return WinningEntryResult::nonWinner($betAmount);
    }

    private function evaluate3dDirect(string $chosenNumber, string $betAmount, string $firstPrize): WinningEntryResult
    {
        $chosen = str_pad($chosenNumber, 3, '0', STR_PAD_LEFT);
        $last3First = substr($firstPrize, 3, 3);

        if ($chosen === $last3First) {
            $gross = bcmul($betAmount, self::MULTIPLIER_3D_DIRECT, 4);
            return $this->buildWinningResult(LotteryPrizeTier::THREE_DIGIT_DIRECT, $betAmount, self::MULTIPLIER_3D_DIRECT, $gross, [$chosen], '3-Digit Direct (3 ตัวตรง)');
        }

        return WinningEntryResult::nonWinner($betAmount);
    }

    private function evaluate3dToad(string $chosenNumber, string $betAmount, string $firstPrize): WinningEntryResult
    {
        $chosen = str_pad($chosenNumber, 3, '0', STR_PAD_LEFT);
        $last3First = substr($firstPrize, 3, 3);

        // Sort characters to check anagram / permutation match
        $chosenSorted = str_split($chosen);
        sort($chosenSorted);

        $targetSorted = str_split($last3First);
        sort($targetSorted);

        if ($chosenSorted === $targetSorted) {
            $gross = bcmul($betAmount, self::MULTIPLIER_3D_TOAD, 4);
            return $this->buildWinningResult(LotteryPrizeTier::THREE_DIGIT_TOAD, $betAmount, self::MULTIPLIER_3D_TOAD, $gross, [$chosen], '3-Digit Toad (3 ตัวโต๊ด)');
        }

        return WinningEntryResult::nonWinner($betAmount);
    }

    private function evaluate2dTop(string $chosenNumber, string $betAmount, string $firstPrize): WinningEntryResult
    {
        $chosen = str_pad($chosenNumber, 2, '0', STR_PAD_LEFT);
        $last2First = substr($firstPrize, 4, 2);

        if ($chosen === $last2First) {
            $gross = bcmul($betAmount, self::MULTIPLIER_2D_TOP, 4);
            return $this->buildWinningResult(LotteryPrizeTier::TWO_DIGIT_TOP, $betAmount, self::MULTIPLIER_2D_TOP, $gross, [$chosen], '2-Digit Top (2 ตัวบน)');
        }

        return WinningEntryResult::nonWinner($betAmount);
    }

    private function evaluate2dBottom(string $chosenNumber, string $betAmount, string $twoDigitBottom): WinningEntryResult
    {
        $chosen = str_pad($chosenNumber, 2, '0', STR_PAD_LEFT);

        if ($chosen === $twoDigitBottom) {
            $gross = bcmul($betAmount, self::MULTIPLIER_2D_BOTTOM, 4);
            return $this->buildWinningResult(LotteryPrizeTier::TWO_DIGIT_BOTTOM_VAR, $betAmount, self::MULTIPLIER_2D_BOTTOM, $gross, [$chosen], '2-Digit Bottom (2 ตัวล่าง)');
        }

        return WinningEntryResult::nonWinner($betAmount);
    }

    private function evaluateRunningTop(string $chosenNumber, string $betAmount, string $firstPrize): WinningEntryResult
    {
        $digit = substr($chosenNumber, 0, 1);
        $last3First = substr($firstPrize, 3, 3);

        if (str_contains($last3First, $digit)) {
            $gross = bcmul($betAmount, self::MULTIPLIER_RUNNING_TOP, 4);
            return $this->buildWinningResult(LotteryPrizeTier::RUNNING_TOP, $betAmount, self::MULTIPLIER_RUNNING_TOP, $gross, [$digit], 'Running Top (วิ่งบน)');
        }

        return WinningEntryResult::nonWinner($betAmount);
    }

    private function evaluateRunningBottom(string $chosenNumber, string $betAmount, string $twoDigitBottom): WinningEntryResult
    {
        $digit = substr($chosenNumber, 0, 1);

        if (str_contains($twoDigitBottom, $digit)) {
            $gross = bcmul($betAmount, self::MULTIPLIER_RUNNING_BOTTOM, 4);
            return $this->buildWinningResult(LotteryPrizeTier::RUNNING_BOTTOM, $betAmount, self::MULTIPLIER_RUNNING_BOTTOM, $gross, [$digit], 'Running Bottom (วิ่งล่าง)');
        }

        return WinningEntryResult::nonWinner($betAmount);
    }

    private function buildWinningResult(
        LotteryPrizeTier $tier,
        string $betAmount,
        string $multiplier,
        string $grossPayout,
        array $matchedNumbers,
        string $desc
    ): WinningEntryResult {
        // Thailand Lottery Standard: 0.5% stamp duty or 1.0% WHT for high-value claims
        $whtTax = bcmul($grossPayout, '0.0050', 4);
        $netPayout = bcsub($grossPayout, $whtTax, 4);

        return WinningEntryResult::winner(
            prizeTier: $tier,
            betAmount: $betAmount,
            multiplier: $multiplier,
            grossPayout: $grossPayout,
            whtTax: $whtTax,
            netPayout: $netPayout,
            matchedNumbers: $matchedNumbers,
            description: $desc
        );
    }
}
