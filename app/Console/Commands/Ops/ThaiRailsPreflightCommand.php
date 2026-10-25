<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Enums\Currency;
use App\Services\Finance\Money;
use Illuminate\Console\Command;
use Throwable;

final class ThaiRailsPreflightCommand extends Command
{
    protected $signature = 'thai-rails:preflight';

    protected $description = 'Check enabled Thai payment rails without printing credentials.';

    /** @var list<string> */
    private array $failures = [];

    public function handle(): int
    {
        $this->truemoney();
        $this->slips();
        $this->payouts();

        return $this->failures === [] ? self::SUCCESS : self::FAILURE;
    }

    private function truemoney(): void
    {
        if (! (bool) config('truemoney.enabled', false)) {
            $this->line('PASS [truemoney] disabled (skipped)');

            return;
        }
        $base = (string) config('truemoney.base_url', '');
        $host = strtolower((string) config('truemoney.allowed_host', ''));
        $mobile = trim((string) config('truemoney.merchant_mobile', ''));
        $path = (string) config('truemoney.redeem_path', '');
        $parts = parse_url($base);
        $limitsValid = false;
        try {
            $minimum = Money::of((string) config('truemoney.min_amount', ''), Currency::THB);
            $maximum = Money::of((string) config('truemoney.max_amount', ''), Currency::THB);
            $daily = Money::of((string) config('truemoney.per_user_daily_amount', ''), Currency::THB);
            $limitsValid = $minimum->isPositive() && $maximum->isPositive() && $daily->isPositive()
                && bccomp($maximum->toString(), $minimum->toString(), 2) >= 0
                && bccomp($daily->toString(), $minimum->toString(), 2) >= 0;
        } catch (Throwable) {
            $limitsValid = false;
        }
        $timeout = (int) config('truemoney.timeout_seconds', 0);
        $valid = (bool) config('truemoney.acknowledged_unofficial_api', false)
            && is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === $host
            && $this->secureUrl($base)
            && $this->safeHost($host)
            && preg_match('/\A(?:0[0-9]{9}|\+66[0-9]{9})\z/D', $mobile) === 1
            && substr_count($path, '{voucher_code}') === 1
            && preg_match('#\A/[A-Za-z0-9_./{}-]+\z#D', $path) === 1
            && preg_match('#(?:\A|/)\.\.(?:/|\z)#', $path) !== 1
            && $timeout >= 1 && $timeout <= 60
            && (int) config('truemoney.per_user_daily_count', 0) >= 1
            && $limitsValid;
        $this->report('truemoney', $valid, 'acknowledgement, HTTPS endpoint and merchant configuration required');
    }

    private function slips(): void
    {
        if (! (bool) config('slip_verification.enabled', false)) {
            $this->line('PASS [slip_verification] disabled (skipped)');

            return;
        }
        $provider = strtolower((string) config('slip_verification.provider', ''));
        $accounts = config('slip_verification.receiving_accounts', []);
        $accountsValid = is_array($accounts) && $accounts !== [];
        foreach ($accountsValid ? $accounts : [] as $account) {
            $accountsValid = $accountsValid && is_array($account)
                && \App\Enums\ThaiBankCode::resolve((string) ($account['bank'] ?? '')) !== null
                && preg_match('/\A[0-9]{4}\z/D', (string) ($account['account_last_digits'] ?? '')) === 1
                && trim((string) ($account['name'] ?? '')) !== '';
        }
        $providerValid = false;
        if ($provider === 'easyslip') {
            $timeout = (int) config('slip_verification.providers.easyslip.timeout', 0);
            $providerValid = $this->secureUrl((string) config('slip_verification.providers.easyslip.base_url', ''), 'api.easyslip.com')
                && trim((string) config('slip_verification.providers.easyslip.verify_path', '')) !== ''
                && trim((string) config('slip_verification.providers.easyslip.token', '')) !== ''
                && $timeout >= 1 && $timeout <= 60;
        } elseif ($provider === 'slipok') {
            $timeout = (int) config('slip_verification.providers.slipok.timeout', 0);
            $providerValid = $this->secureUrl((string) config('slip_verification.providers.slipok.base_url', ''), 'api.slipok.com')
                && trim((string) config('slip_verification.providers.slipok.verify_path', '')) !== ''
                && trim((string) config('slip_verification.providers.slipok.branch_id', '')) !== ''
                && trim((string) config('slip_verification.providers.slipok.api_key', '')) !== ''
                && $timeout >= 1 && $timeout <= 60;
        }
        $this->report('slip_verification', $providerValid && $accountsValid
            && (int) config('slip_verification.max_age_minutes', 0) >= 1,
            'selected provider credentials and receiving accounts required');
    }

    private function payouts(): void
    {
        if (! (bool) config('thai_payout.enabled', false)) {
            $this->line('PASS [thai_payout] disabled (skipped)');

            return;
        }
        $defaultBank = strtolower((string) config('thai_payout.default_bank', ''));
        $limitsValid = false;
        try {
            $minimum = Money::of((string) config('thai_payout.min_amount', ''), Currency::THB);
            $maximum = Money::of((string) config('thai_payout.max_amount', ''), Currency::THB);
            $daily = Money::of((string) config('thai_payout.daily_limit', ''), Currency::THB);
            $limitsValid = $minimum->isPositive() && $maximum->isPositive() && $daily->isPositive()
                && bccomp($maximum->toString(), $minimum->toString(), 2) >= 0
                && bccomp($daily->toString(), $minimum->toString(), 2) >= 0;
        } catch (Throwable) {
            $limitsValid = false;
        }
        $pollingValid = (int) config('thai_payout.poll_after_seconds', 0) >= 1
            && (int) config('thai_payout.poll_max_age_hours', 0) >= 1
            && (int) config('thai_payout.callback_tolerance_seconds', 0) >= 1;
        $defaultBankEnabled = in_array($defaultBank, ['scb', 'kbank'], true)
            && (bool) config('thai_payout.banks.'.$defaultBank.'.enabled', false);
        $enabledBanksValid = true;
        $hasEnabledBank = false;
        foreach (['scb', 'kbank'] as $bank) {
            if (! (bool) config('thai_payout.banks.'.$bank.'.enabled', false)) {
                continue;
            }
            $hasEnabledBank = true;
            $bankValid = $this->payoutBankConfigured($bank);
            $enabledBanksValid = $enabledBanksValid && $bankValid;
        }
        $valid = $defaultBankEnabled && $hasEnabledBank && $enabledBanksValid && $limitsValid && $pollingValid;
        $this->report('thai_payout', $valid, 'bank credentials, confirmed request/response contract, and secure endpoints required');
    }

    private function payoutBankConfigured(string $bank): bool
    {
        $timeout = (int) config('thai_payout.banks.'.$bank.'.timeout_seconds', 0);
        $valid = $this->secureUrl((string) config('thai_payout.banks.'.$bank.'.base_url', ''))
            && trim((string) config('thai_payout.banks.'.$bank.'.client_id', '')) !== ''
            && trim((string) config('thai_payout.banks.'.$bank.'.client_secret', '')) !== ''
            && (bool) config('thai_payout.banks.'.$bank.'.contract_confirmed', false)
            && trim((string) config('thai_payout.banks.'.$bank.'.webhook_secret', '')) !== ''
            && trim((string) config('thai_payout.banks.'.$bank.'.source_account', '')) !== ''
            && $this->safePath((string) config('thai_payout.banks.'.$bank.'.token_path', ''))
            && $this->safePath((string) config('thai_payout.banks.'.$bank.'.transfer_path', ''))
            && $this->safePath((string) config('thai_payout.banks.'.$bank.'.status_path', ''))
            && trim((string) config('thai_payout.banks.'.$bank.'.callback_signature_header', '')) !== ''
            && trim((string) config('thai_payout.banks.'.$bank.'.callback_timestamp_header', '')) !== ''
            && $timeout >= 1 && $timeout <= 60;
        if ($bank === 'scb') {
            $valid = $valid
                && trim((string) config('thai_payout.banks.scb.resource_owner_id', '')) !== ''
                && in_array(strtoupper((string) config('thai_payout.banks.scb.status_method', '')), ['GET', 'POST'], true);
        }

        return $valid;
    }

    private function report(string $lane, bool $valid, string $safeReason): void
    {
        if ($valid) {
            $this->line('PASS ['.$lane.'] configuration complete');

            return;
        }
        $this->failures[] = $lane;
        $this->line('FAIL ['.$lane.'] '.$safeReason);
    }

    private function safePath(string $path): bool
    {
        return preg_match('#\\A/[A-Za-z0-9_./{}-]+\\z#D', $path) === 1
            && preg_match('#(?:\\A|/)\\.\\.(?:/|\\z)#', $path) !== 1;
    }

    private function secureUrl(string $url, ?string $expectedHost = null): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) (is_array($parts) ? ($parts['host'] ?? '') : ''));

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && $this->safeHost($host)
            && ($expectedHost === null || $host === strtolower($expectedHost))
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && (! isset($parts['port']) || $parts['port'] === 443);
    }

    private function safeHost(string $host): bool
    {
        return $host !== ''
            && ! in_array($host, ['localhost', 'localhost.localdomain'], true)
            && ! str_ends_with($host, '.example')
            && (filter_var($host, FILTER_VALIDATE_IP) === false
                || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false);
    }
}
