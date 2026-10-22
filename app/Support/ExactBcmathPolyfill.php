<?php

declare(strict_types=1);

namespace App\Support {

    /**
     * Pure-PHP arbitrary-precision base-10 polyfill for `ext-bcmath`.
     *
     * Activated ONLY when the native C `bcmath` extension is absent from the PHP
     * runtime so `App\Services\Finance\Money` and all ledger / promotional services
     * execute with exact base-10 arithmetic and zero IEEE-754 floating-point math.
     */
    final class ExactBcmathPolyfill
    {
        private static int $defaultScale = 0;

        public static function register(): void
        {
            require_once __FILE__;
        }

        public static function scale(?int $scale = null): int
        {
            $previous = self::$defaultScale;
            if ($scale !== null) {
                self::$defaultScale = max(0, $scale);
            }

            return $previous;
        }

        public static function add(string $a, string $b, ?int $scale = null): string
        {
            $s = $scale ?? self::$defaultScale;
            [$signA, $digitsA, $workScale] = self::toScaledDigits($a, $b, $s);
            [$signB, $digitsB] = self::toSingleScaled($b, $workScale);

            if ($signA === $signB) {
                $resSign = $signA;
                $resDigits = self::uintAdd($digitsA, $digitsB);
            } else {
                $cmp = self::uintCmp($digitsA, $digitsB);
                if ($cmp === 0) {
                    $resSign = 1;
                    $resDigits = '0';
                } elseif ($cmp > 0) {
                    $resSign = $signA;
                    $resDigits = self::uintSub($digitsA, $digitsB);
                } else {
                    $resSign = $signB;
                    $resDigits = self::uintSub($digitsB, $digitsA);
                }
            }

            return self::formatScaled($resSign, $resDigits, $workScale, $s);
        }

        public static function sub(string $a, string $b, ?int $scale = null): string
        {
            $negated = str_starts_with(trim($b), '-')
                ? substr(trim($b), 1)
                : '-'.ltrim(trim($b), '+');

            return self::add($a, $negated, $scale);
        }

        public static function comp(string $a, string $b, ?int $scale = null): int
        {
            $s = $scale ?? self::$defaultScale;
            $aTrunc = self::add($a, '0', $s);
            $bTrunc = self::add($b, '0', $s);

            [$signA, $digitsA] = self::toSingleScaled($aTrunc, $s);
            [$signB, $digitsB] = self::toSingleScaled($bTrunc, $s);

            if ($digitsA === '0' && $digitsB === '0') {
                return 0;
            }
            if ($signA !== $signB) {
                return $signA > $signB ? 1 : -1;
            }

            return $signA * self::uintCmp($digitsA, $digitsB);
        }

        public static function mul(string $a, string $b, ?int $scale = null): string
        {
            $s = $scale ?? self::$defaultScale;
            [$signA, $intA, $fracA] = self::splitDecimal($a);
            [$signB, $intB, $fracB] = self::splitDecimal($b);

            $digitsA = ltrim($intA.$fracA, '0') ?: '0';
            $digitsB = ltrim($intB.$fracB, '0') ?: '0';
            $prodScale = strlen($fracA) + strlen($fracB);

            $prodDigits = self::uintMul($digitsA, $digitsB);
            $resSign = ($digitsA === '0' || $digitsB === '0') ? 1 : ($signA * $signB);

            return self::formatScaled($resSign, $prodDigits, $prodScale, $s);
        }

        public static function div(string $a, string $b, ?int $scale = null): string
        {
            $s = $scale ?? self::$defaultScale;
            [$signA, $intA, $fracA] = self::splitDecimal($a);
            [$signB, $intB, $fracB] = self::splitDecimal($b);

            $commonFrac = max(strlen($fracA), strlen($fracB));
            $num = ltrim($intA.str_pad($fracA, $commonFrac, '0'), '0') ?: '0';
            $den = ltrim($intB.str_pad($fracB, $commonFrac, '0'), '0') ?: '0';

            if ($den === '0') {
                throw new \DivisionByZeroError('Division by zero');
            }

            if ($num === '0') {
                return self::formatScaled(1, '0', 0, $s);
            }

            $scaledNum = $num.str_repeat('0', $s);
            $quotient = self::uintDiv($scaledNum, $den);
            $resSign = ($quotient === '0') ? 1 : ($signA * $signB);

            return self::formatScaled($resSign, $quotient, $s, $s);
        }

        /**
         * @return array{0: int, 1: string, 2: string}
         */
        private static function splitDecimal(string $raw): array
        {
            $trimmed = trim($raw);
            $sign = 1;
            if (str_starts_with($trimmed, '-')) {
                $sign = -1;
                $trimmed = substr($trimmed, 1);
            } elseif (str_starts_with($trimmed, '+')) {
                $trimmed = substr($trimmed, 1);
            }

            $parts = explode('.', $trimmed, 2);
            $intPart = preg_replace('/[^0-9]/', '', $parts[0] ?? '0') ?: '0';
            $fracPart = isset($parts[1]) ? (preg_replace('/[^0-9]/', '', $parts[1]) ?? '') : '';

            return [$sign, ltrim($intPart, '0') ?: '0', $fracPart];
        }

        /**
         * @return array{0: int, 1: string, 2: int}
         */
        private static function toScaledDigits(string $a, string $b, int $targetScale): array
        {
            [$signA, $intA, $fracA] = self::splitDecimal($a);
            [, , $fracB] = self::splitDecimal($b);
            $workScale = max($targetScale, strlen($fracA), strlen($fracB));
            $digitsA = ltrim($intA.str_pad($fracA, $workScale, '0'), '0') ?: '0';

            return [$signA, $digitsA, $workScale];
        }

        /**
         * @return array{0: int, 1: string}
         */
        private static function toSingleScaled(string $val, int $workScale): array
        {
            [$sign, $intPart, $fracPart] = self::splitDecimal($val);
            $padded = str_pad(substr($fracPart, 0, $workScale), $workScale, '0');
            $digits = ltrim($intPart.$padded, '0') ?: '0';

            return [$digits === '0' ? 1 : $sign, $digits];
        }

        private static function formatScaled(int $sign, string $digits, int $currentScale, int $outScale): string
        {
            $clean = ltrim($digits, '0') ?: '0';
            if ($clean !== '0') {
                if ($currentScale > $outScale) {
                    $drop = $currentScale - $outScale;
                    $clean = strlen($clean) > $drop ? substr($clean, 0, -$drop) : '0';
                } elseif ($currentScale < $outScale) {
                    $clean .= str_repeat('0', $outScale - $currentScale);
                }
            }

            $clean = ltrim($clean, '0') ?: '0';
            $prefix = ($sign < 0 && $clean !== '0') ? '-' : '';

            if ($outScale <= 0) {
                return $prefix.$clean;
            }

            $padded = str_pad($clean, $outScale + 1, '0', STR_PAD_LEFT);
            $intOut = substr($padded, 0, -$outScale);
            $fracOut = substr($padded, -$outScale);

            return $prefix.$intOut.'.'.$fracOut;
        }

        private static function uintCmp(string $a, string $b): int
        {
            $a = ltrim($a, '0') ?: '0';
            $b = ltrim($b, '0') ?: '0';
            if (strlen($a) !== strlen($b)) {
                return strlen($a) <=> strlen($b);
            }

            return $a <=> $b;
        }

        private static function uintAdd(string $a, string $b): string
        {
            $i = strlen($a) - 1;
            $j = strlen($b) - 1;
            $carry = 0;
            $out = '';

            while ($i >= 0 || $j >= 0 || $carry > 0) {
                $sum = $carry
                    + ($i >= 0 ? (ord($a[$i]) - 48) : 0)
                    + ($j >= 0 ? (ord($b[$j]) - 48) : 0);
                $out .= chr(($sum % 10) + 48);
                $carry = intdiv($sum, 10);
                $i--;
                $j--;
            }

            return strrev($out);
        }

        /** Assumes $a >= $b. */
        private static function uintSub(string $a, string $b): string
        {
            $i = strlen($a) - 1;
            $j = strlen($b) - 1;
            $borrow = 0;
            $out = '';

            while ($i >= 0) {
                $diff = (ord($a[$i]) - 48) - $borrow - ($j >= 0 ? (ord($b[$j]) - 48) : 0);
                if ($diff < 0) {
                    $diff += 10;
                    $borrow = 1;
                } else {
                    $borrow = 0;
                }
                $out .= chr($diff + 48);
                $i--;
                $j--;
            }

            return ltrim(strrev($out), '0') ?: '0';
        }

        private static function uintMul(string $a, string $b): string
        {
            if ($a === '0' || $b === '0') {
                return '0';
            }

            $lenA = strlen($a);
            $lenB = strlen($b);
            $slots = array_fill(0, $lenA + $lenB, 0);

            for ($i = $lenA - 1; $i >= 0; $i--) {
                $da = ord($a[$i]) - 48;
                for ($j = $lenB - 1; $j >= 0; $j--) {
                    $prod = $da * (ord($b[$j]) - 48) + $slots[$i + $j + 1];
                    $slots[$i + $j + 1] = $prod % 10;
                    $slots[$i + $j] += intdiv($prod, 10);
                }
            }

            return ltrim(implode('', array_map('strval', $slots)), '0') ?: '0';
        }

        private static function uintDiv(string $num, string $den): string
        {
            if (self::uintCmp($num, $den) < 0) {
                return '0';
            }

            $quotient = '';
            $remainder = '0';
            $len = strlen($num);

            for ($i = 0; $i < $len; $i++) {
                $remainder = ltrim($remainder.$num[$i], '0') ?: '0';
                $digit = 0;
                while (self::uintCmp($remainder, $den) >= 0) {
                    $remainder = self::uintSub($remainder, $den);
                    $digit++;
                }
                $quotient .= (string) $digit;
            }

            return ltrim($quotient, '0') ?: '0';
        }
    }

}

namespace {
    use App\Support\ExactBcmathPolyfill;

    if (! function_exists('bcscale')) {
        function bcscale(?int $scale = null): int
        {
            return ExactBcmathPolyfill::scale($scale);
        }
    }
    if (! function_exists('bcadd')) {
        function bcadd(string $num1, string $num2, ?int $scale = null): string
        {
            return ExactBcmathPolyfill::add($num1, $num2, $scale);
        }
    }
    if (! function_exists('bcsub')) {
        function bcsub(string $num1, string $num2, ?int $scale = null): string
        {
            return ExactBcmathPolyfill::sub($num1, $num2, $scale);
        }
    }
    if (! function_exists('bcmul')) {
        function bcmul(string $num1, string $num2, ?int $scale = null): string
        {
            return ExactBcmathPolyfill::mul($num1, $num2, $scale);
        }
    }
    if (! function_exists('bcdiv')) {
        function bcdiv(string $num1, string $num2, ?int $scale = null): string
        {
            return ExactBcmathPolyfill::div($num1, $num2, $scale);
        }
    }
    if (! function_exists('bccomp')) {
        function bccomp(string $num1, string $num2, ?int $scale = null): int
        {
            return ExactBcmathPolyfill::comp($num1, $num2, $scale);
        }
    }
}
