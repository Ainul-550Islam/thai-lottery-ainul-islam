<?php

declare(strict_types=1);

namespace App\Services\Payment\Slip;

/** Never turn four visible middle digits into a claimed account suffix. */
final class ProviderAccountMask
{
    public static function lastFourIfExposed(string $account): string
    {
        // Separators are formatting only. Any mask character inside or after the
        // final four digits leaves the true suffix unknown, so matching must fail.
        if (preg_match('/([0-9](?:[ .-]?[0-9]){3})[ \t]*\z/D', $account, $matches) !== 1) {
            return '';
        }
        $lastFour = preg_replace('/[^0-9]/', '', $matches[1]);

        return is_string($lastFour) && strlen($lastFour) === 4 ? '••••'.$lastFour : '';
    }
}
