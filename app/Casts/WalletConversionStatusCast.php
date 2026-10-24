<?php

declare(strict_types=1);

namespace App\Casts;

use App\Enums\WalletConversionStatus;
use App\Exceptions\Wallet\ConversionException;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * The status column as a lifecycle enum, refusing unknown values in the
 * vocabulary of the lane rather than of the language.
 *
 * WHY THIS IS NOT A PLAIN enum CAST
 * A plain cast turns a damaged `status` value into a ValueError, which surfaces as a
 * 500 from wherever the row happened to be loaded — a listing page, a support lookup,
 * an integrity proof. The value can only have got there through a bad migration or a
 * hand-written UPDATE, and either way the correct answer is not "unexpected type", it
 * is "this conversion's accounting cannot be relied on". So the failure is raised as
 * the same conversion_original_unproven refusal that every other broken-book case
 * produces, and it is raised at hydration, before any caller can start reasoning
 * about the row.
 *
 * @implements CastsAttributes<WalletConversionStatus|null, WalletConversionStatus|string|null>
 */
final class WalletConversionStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?WalletConversionStatus
    {
        if ($value === null || $value === '') {
            return null;
        }

        $status = WalletConversionStatus::tryFrom((string) $value);

        if ($status === null) {
            throw ConversionException::failure(
                'conversion_original_unproven',
                'This conversion carries a state this system does not recognise, so its accounting cannot be relied on.',
            );
        }

        return $status;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof WalletConversionStatus ? $value->value : (string) $value;
    }
}
