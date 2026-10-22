<?php

declare(strict_types=1);

namespace App\Support\Wallet;

use App\Enums\Currency;
use App\Exceptions\Wallet\ConversionException;
use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Strict denomination resolution. No FX, primary-currency guesses or writes. */
final class ConversionCurrency
{
    public static function parse(mixed $value): Currency
    {
        if ($value instanceof Currency) {
            return $value;
        }
        $currency = is_string($value) ? Currency::tryFrom($value) : null;
        if ($currency === null) {
            throw ConversionException::failure('currency_mismatch', 'An explicit supported currency code is required.');
        }

        return $currency;
    }

    public static function configured(?Repository $config = null): Currency
    {
        $value = ($config ?? app('config'))->get('conversions.currency');
        $currency = is_string($value) ? Currency::tryFrom($value) : null;
        if ($currency === null) {
            throw ConversionException::failure('currency_not_configured', 'No valid conversion currency is configured.');
        }

        return $currency;
    }

    /** HTTP adapter: config errors are availability errors, not user mistakes. */
    public static function httpConfigured(Request $request): Currency
    {
        try {
            return self::configured();
        } catch (ConversionException) {
            $message = __('wallet_conversion.rejection_currency_not_configured');
            if ($request->expectsJson() || $request->is('api/*')) {
                throw new HttpResponseException(ApiResponse::error('currency_not_configured', $message, 503));
            }
            abort(503, $message);
        }
    }

    /** An omitted field uses configuration; an explicit invalid field never does. */
    public static function forRequest(Request $request): Currency
    {
        $configured = self::httpConfigured($request);
        if ($request->exists('currency')) {
            $value = $request->input('currency');
            if (! is_string($value) || Currency::tryFrom($value) === null) {
                throw ValidationException::withMessages([
                    'currency' => __('wallet_conversion.validation_currency_mismatch', ['currency' => $configured->value]),
                ]);
            }
            if ($value !== $configured->value) {
                $message = __('wallet_conversion.rejection_currency_mismatch');
                if ($request->expectsJson() || $request->is('api/*')) {
                    // Preserve the existing engine's mismatch code at the earlier gate.
                    throw new HttpResponseException(ApiResponse::error('currency_mismatch', $message, 422));
                }
                throw ValidationException::withMessages(['currency' => $message]);
            }
        }

        return $configured;
    }
}
