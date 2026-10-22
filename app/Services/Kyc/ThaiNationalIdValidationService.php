<?php

declare(strict_types=1);

namespace App\Services\Kyc;

class ThaiNationalIdValidationService
{
    /**
     * Validates a 13-digit Thai National ID card number using the official MOD 11 checksum algorithm.
     *
     * Algorithm:
     * 1. Multiply each of the first 12 digits by weights from 13 down to 2.
     * 2. Sum the products.
     * 3. Calculate (11 - (Sum % 11)) % 10.
     * 4. The result must match the 13th digit.
     */
    public function isValidNationalId(string $id): bool
    {
        $sanitized = preg_replace('/[^0-9]/', '', $id) ?? '';

        if (strlen($sanitized) !== 13) {
            return false;
        }

        // Digits cannot be all identical (e.g. 0000000000000 or 1111111111111)
        if (preg_match('/^(\d)\1{12}$/', $sanitized)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $sanitized[$i];
            $weight = 13 - $i;
            $sum += $digit * $weight;
        }

        $checkDigit = (11 - ($sum % 11)) % 10;

        return $checkDigit === (int) $sanitized[12];
    }
}
