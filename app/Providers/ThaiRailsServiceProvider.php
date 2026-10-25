<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\FinancialException;
use App\Filament\Resources\DepositSlipVerificationResource;
use App\Models\DepositSlipVerification;
use App\Policies\DepositSlipVerificationPolicy;
use App\Services\Payout\ThaiBank\KbankPayoutAdapter;
use App\Services\Payout\ThaiBank\ScbPayoutAdapter;
use App\Services\Payout\ThaiBank\ThaiBankPayoutAdapterInterface;
use App\Services\Payment\Slip\EasySlipProvider;
use App\Services\Payment\Slip\SlipOkProvider;
use App\Services\Payment\Slip\SlipVerificationProviderInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ThaiRailsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('truemoney.php'), 'truemoney');
        $this->mergeConfigFrom(config_path('slip_verification.php'), 'slip_verification');
        $this->mergeConfigFrom(config_path('thai_payout.php'), 'thai_payout');

        $this->app->bind(SlipVerificationProviderInterface::class, function ($app): SlipVerificationProviderInterface {
            $provider = strtolower(trim((string) config('slip_verification.provider', 'easyslip')));
            if (! in_array($provider, ['easyslip', 'slipok'], true)) {
                throw FinancialException::withCode('slip_provider_not_configured', 'The configured slip-verification provider is unsupported.');
            }
            $configured = match ($provider) {
                'easyslip' => $this->isSecureEndpoint((string) config('slip_verification.providers.easyslip.base_url', ''), 'api.easyslip.com')
                    && trim((string) config('slip_verification.providers.easyslip.verify_path', '')) !== ''
                    && trim((string) config('slip_verification.providers.easyslip.token', '')) !== ''
                    && (int) config('slip_verification.providers.easyslip.timeout', 0) > 0
                    && (int) config('slip_verification.providers.easyslip.timeout', 0) <= 60,
                'slipok' => $this->isSecureEndpoint((string) config('slip_verification.providers.slipok.base_url', ''), 'api.slipok.com')
                    && trim((string) config('slip_verification.providers.slipok.verify_path', '')) !== ''
                    && trim((string) config('slip_verification.providers.slipok.branch_id', '')) !== ''
                    && trim((string) config('slip_verification.providers.slipok.api_key', '')) !== ''
                    && (int) config('slip_verification.providers.slipok.timeout', 0) > 0
                    && (int) config('slip_verification.providers.slipok.timeout', 0) <= 60,
            };
            $accounts = config('slip_verification.receiving_accounts', []);
            $accountsConfigured = is_array($accounts) && $accounts !== [];
            foreach ($accountsConfigured ? $accounts : [] as $account) {
                $accountsConfigured = $accountsConfigured && is_array($account)
                    && \App\Enums\ThaiBankCode::resolve((string) ($account['bank'] ?? '')) !== null
                    && preg_match('/\\A[0-9]{4}\\z/D', (string) ($account['account_last_digits'] ?? '')) === 1
                    && trim((string) ($account['name'] ?? '')) !== '';
            }
            if ((bool) config('slip_verification.enabled', false)
                && (! $configured || ! $accountsConfigured || (int) config('slip_verification.max_age_minutes', 0) < 1)) {
                throw FinancialException::withCode('slip_provider_not_configured', 'The enabled slip-verification provider is not completely configured.');
            }

            return $provider === 'easyslip' ? $app->make(EasySlipProvider::class) : $app->make(SlipOkProvider::class);
        });

        $this->app->bind(ThaiBankPayoutAdapterInterface::class, function ($app): ThaiBankPayoutAdapterInterface {
            $bank = strtolower(trim((string) config('thai_payout.default_bank', 'scb')));
            $adapter = match ($bank) {
                'scb' => $app->make(ScbPayoutAdapter::class),
                'kbank' => $app->make(KbankPayoutAdapter::class),
                default => throw FinancialException::withCode('thai_payout_bank_unsupported', 'The configured default Thai payout bank is unsupported.'),
            };
            if ((bool) config('thai_payout.enabled', false)
                && (! (bool) config('thai_payout.banks.'.$bank.'.enabled', false) || ! $adapter->configured())) {
                throw FinancialException::withCode('thai_payout_adapter_not_configured', 'The enabled Thai bank payout adapter is not completely configured.');
            }

            return $adapter;
        });
    }

    public function boot(): void
    {
        foreach (['scb', 'kbank'] as $bank) {
            $callbackSignatureHeader = trim((string) config('thai_payout.banks.'.$bank.'.callback_signature_header', 'X-Signature'));
            if ($callbackSignatureHeader !== '') {
                // The shared replay guard resolves gateway signature headers from payment.gateways.*.
                config(['payment.gateways.'.$bank.'.signature_header' => $callbackSignatureHeader]);
            }
        }

        Gate::policy(DepositSlipVerification::class, DepositSlipVerificationPolicy::class);
        Route::middleware('api')->prefix('api')->group(base_path('routes/thai_rails.php'));

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\Payment\ReconcileTruemoneyRedemptionsCommand::class,
                \App\Console\Commands\Ops\ThaiRailsPreflightCommand::class,
            ]);
        }
    }

    private function isSecureEndpoint(string $url, string $expectedHost): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) (is_array($parts) ? ($parts['host'] ?? '') : ''));

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host !== strtolower($expectedHost)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.example')) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) === false
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
