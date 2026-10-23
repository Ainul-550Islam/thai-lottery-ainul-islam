<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Wallet\ConversionFeeProviderContract;
use App\Contracts\Wallet\ConversionPolicyContract;
use App\Contracts\Wallet\ConversionRepositoryContract;
use App\Contracts\Wallet\WalletConversionServiceContract;
use App\Repositories\Wallet\WalletConversionRepository;
use App\Services\Wallet\Conversion\ConversionFeeCalculator;
use App\Services\Wallet\Conversion\ConversionPolicyGuard;
use App\Services\Wallet\Conversion\WalletConversionService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class WalletConversionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            WalletConversionServiceContract::class,
            WalletConversionService::class,
        );

        $this->app->singleton(
            ConversionPolicyContract::class,
            ConversionPolicyGuard::class,
        );

        $this->app->singleton(
            ConversionFeeProviderContract::class,
            ConversionFeeCalculator::class,
        );

        $this->app->singleton(
            ConversionRepositoryContract::class,
            WalletConversionRepository::class,
        );
    }

    public function boot(): void
    {
        RateLimiter::for('conversion', static function (Request $request): Limit {
            $perMinute = max(1, (int) config('conversions.rate_limit.quote_per_minute', 30));

            return Limit::perMinute($perMinute)->by((string) ($request->user()?->getAuthIdentifier() ?: $request->ip()));
        });

        RateLimiter::for('wallet-conversion-quote', static function (Request $request): Limit {
            $perMinute = max(1, (int) config('conversions.rate_limit.quote_per_minute', 30));

            return Limit::perMinute($perMinute)->by((string) ($request->user()?->getAuthIdentifier() ?: $request->ip()));
        });

        RateLimiter::for('wallet-conversion-execute', static function (Request $request): Limit {
            $perMinute = max(1, (int) config('conversions.rate_limit.execute_per_minute', 10));

            return Limit::perMinute($perMinute)->by((string) ($request->user()?->getAuthIdentifier() ?: $request->ip()));
        });

        RateLimiter::for('wallet-conversion-admin', static function (Request $request): Limit {
            return Limit::perMinute(30)->by((string) ($request->user()?->getAuthIdentifier() ?: $request->ip()));
        });
    }
}
