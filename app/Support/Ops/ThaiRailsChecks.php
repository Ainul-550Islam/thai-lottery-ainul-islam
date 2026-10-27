<?php

declare(strict_types=1);

namespace App\Support\Ops;

use App\Enums\Currency;
use App\Services\Finance\Money;
use App\Services\Payment\PaymentGatewayManager;
use Throwable;

/**
 * The Thai payment-rail configuration checks, extracted from
 * ThaiRailsPreflightCommand so the command, ProductionPreflight and the tests
 * all ask the same questions and get the same answers.
 *
 * WHAT A CHECK MAY PRINT
 * Every message describes the SHAPE of what was found ("HTTPS endpoint",
 * "merchant mobile configured", "bank credentials present"). No credential, no
 * account number, no secret and no full configuration value is ever
 * interpolated: these lines end up in CI transcripts, deploy logs and incident
 * tickets.
 *
 * A LANE THAT IS OFF IS NOT A FAILING LANE
 * Each rail is checked only when it is enabled. A deployment that has not
 * turned TrueMoney, slip verification or the Thai bank payout rail on gets a
 * PASS line whose message says "disabled (skipped)": the verdict is "nothing
 * here is broken", the message is "and nothing here was checked either".
 *
 * THE STATUS IS PART OF A PUBLISHED CONTRACT
 * -----------------------------------------
 * `thai-rails:preflight` has always printed `PASS [truemoney] disabled
 * (skipped)` for a dark lane, and a deploy gate parses that line. Reporting a
 * dark lane as FAIL would fail every release that has not bought the rail, and
 * silently changing the word PASS to SKIP would break the gate for no safety
 * gain. So the status stays PASS and the message carries the skip — exactly as
 * it always has. ProductionPreflight records these rows as advisory, so a
 * skipped lane can never block a release.
 */
final class ThaiRailsChecks
{
    /**
     * Gateways whose driver builds requests against a configured base URL. An
     * empty value here is a live-but-unreachable gateway, so it is checked for
     * itself rather than inferred from the credential list.
     *
     * @var list<string>
     */
    private const BASE_URL_GATEWAYS = ['bkash', 'nagad'];

    /**
     * The three SCB Open API endpoints a payout cannot complete without.
     *
     * @var list<string>
     */
    private const SCB_PATHS = ['token_path', 'transfer_path', 'status_path'];

    /**
     * @return list<CheckResult>
     */
    public function run(): array
    {
        return [
            $this->truemoney(),
            $this->slips(),
            $this->payouts(),
            $this->market(),
            $this->crypto(),
            $this->gatewayBaseUrls(),
            $this->scbPaths(),
        ];
    }

    /**
     * THE BDT-MARKET GUARD.
     *
     * This is a Thai-baht deployment. A BDT market reaching production here does
     * not fail loudly — bKash and Nagad quietly become the only usable rails,
     * THB settlements stop matching, and the site is suddenly trading in a
     * currency no part of the Thai payout lane was reconciled against. Two
     * distinct ways that happens, and both are refused:
     *
     *   1. the site itself is configured as a BDT/BD market (currency or country);
     *   2. a BDT-only rail is switched on while the site settles in another
     *      currency, which is a market mismatch rather than an unfinished lane.
     *
     * A correctly Thai deployment passes without any operator action, and a
     * bKash/Nagad lane that is merely turned OFF is not a finding at all.
     */
    public function market(): CheckResult
    {
        $currency = strtoupper((string) config('payment.currency.default', 'THB'));
        $country = strtoupper((string) config('payment.country', 'TH'));

        $issues = [];

        if ($currency === 'BDT') {
            $issues[] = 'site currency is BDT';
        }
        if ($country === 'BD') {
            $issues[] = 'site country is BD';
        }

        $bdtRails = [];
        foreach (['bkash', 'nagad'] as $rail) {
            if ($this->liveOnSiteMarket($rail)) {
                $bdtRails[] = $rail;
            }
        }
        if ($bdtRails !== [] && $currency !== 'BDT') {
            $issues[] = 'BDT-only rail live on a '.$currency.' site: '.implode(', ', $bdtRails);
        }

        return $issues === []
            ? CheckResult::pass('market', 'site market is '.$currency.'/'.$country.' with no BDT rail enabled')
            : CheckResult::fail('market', 'Thai rails refuse a BDT market ('.implode('; ', $issues).')');
    }

    /**
     * CRYPTO INVISIBILITY.
     *
     * The crypto driver is deliberately Unsupported: it has no settlement
     * proof, and surfacing it as a deposit method would advertise a rail that
     * cannot pay anyone. The finding is therefore not "the flag is on" — a
     * deployment may leave the switch flipped while working on the lane — but
     * "a player can reach it", which is what invisibility actually forbids.
     */
    public function crypto(): CheckResult
    {
        if (! (bool) config('payment.gateways.crypto.enabled', false)) {
            return CheckResult::pass('crypto', 'crypto gateway disabled and not offered to players');
        }

        try {
            $manager = app(PaymentGatewayManager::class);
            $exposed = $manager->isDepositCapable('crypto') || $manager->isWithdrawalCapable('crypto');
        } catch (Throwable) {
            // A resolver that cannot be asked cannot expose anything either.
            $exposed = false;
        }

        return $exposed
            ? CheckResult::fail('crypto', 'crypto is reachable by players; it must remain invisible')
            : CheckResult::pass('crypto', 'crypto is enabled but not reachable: no settlement proof, so it is never offered');
    }

    /**
     * EMPTY BASE URL.
     *
     * An enabled gateway whose base_url is empty resolves a driver that passes
     * isEnabled() and then fails on the first outbound call — the player sees a
     * generic "not ready" after money has been committed to an intent.
     *
     * Only lanes that are LIVE on this site's market are judged. A gateway that
     * is switched on in configuration but whose market (say BDT/BD) does not
     * match the site (THB/TH) is already unreachable through the market
     * allowlist, so reporting it would be noise on every release.
     */
    public function gatewayBaseUrls(): CheckResult
    {
        $blank = [];

        foreach (self::BASE_URL_GATEWAYS as $gateway) {
            if (! $this->liveOnSiteMarket($gateway)) {
                continue;
            }
            if (trim((string) config('payment.gateways.'.$gateway.'.base_url', '')) === '') {
                $blank[] = $gateway;
            }
        }

        return $blank === []
            ? CheckResult::pass('gateway_base_url', 'every live gateway has a base URL')
            : CheckResult::fail('gateway_base_url', 'live gateway with an empty base URL: '.implode(', ', $blank));
    }

    /**
     * BLANK SCB PATHS.
     *
     * `payouts()` already refuses an incomplete SCB configuration, but it
     * reports one sentence for a dozen possible causes. A blank endpoint path is
     * the failure that survives a URL check — the host is perfectly valid and
     * the request still goes nowhere — so it is named on its own line instead of
     * being folded into the general verdict.
     */
    public function scbPaths(): CheckResult
    {
        if (! (bool) config('thai_payout.banks.scb.enabled', false)) {
            return CheckResult::pass('thai_payout_scb_paths', 'SCB payouts disabled (skipped)');
        }

        $blank = [];
        foreach (self::SCB_PATHS as $path) {
            if (trim((string) config('thai_payout.banks.scb.'.$path, '')) === '') {
                $blank[] = $path;
            }
        }

        return $blank === []
            ? CheckResult::pass('thai_payout_scb_paths', 'SCB token, transfer and status paths configured')
            : CheckResult::fail('thai_payout_scb_paths', 'SCB endpoint path blank: '.implode(', ', $blank));
    }

    public function truemoney(): CheckResult
    {
        if (! (bool) config('truemoney.enabled', false)) {
            return CheckResult::pass('truemoney', 'disabled (skipped)');
        }

        $base = (string) config('truemoney.base_url', '');
        $host = strtolower((string) config('truemoney.allowed_host', ''));
        $mobile = trim((string) config('truemoney.merchant_mobile', ''));
        $path = (string) config('truemoney.redeem_path', '');
        $parts = parse_url($base);
        $limitsValid = $this->limitsValid(
            (string) config('truemoney.min_amount', ''),
            (string) config('truemoney.max_amount', ''),
            (string) config('truemoney.per_user_daily_amount', ''),
        );
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

        return $valid
            ? CheckResult::pass('truemoney', 'configuration complete')
            : CheckResult::fail('truemoney', 'acknowledgement, HTTPS endpoint and merchant configuration required');
    }

    public function slips(): CheckResult
    {
        if (! (bool) config('slip_verification.enabled', false)) {
            return CheckResult::pass('slip_verification', 'disabled (skipped)');
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
        $valid = $providerValid && $accountsValid
            && (int) config('slip_verification.max_age_minutes', 0) >= 1;

        return $valid
            ? CheckResult::pass('slip_verification', 'configuration complete')
            : CheckResult::fail('slip_verification', 'selected provider credentials and receiving accounts required');
    }

    public function payouts(): CheckResult
    {
        if (! (bool) config('thai_payout.enabled', false)) {
            return CheckResult::pass('thai_payout', 'disabled (skipped)');
        }

        $defaultBank = strtolower((string) config('thai_payout.default_bank', ''));
        $limitsValid = $this->limitsValid(
            (string) config('thai_payout.min_amount', ''),
            (string) config('thai_payout.max_amount', ''),
            (string) config('thai_payout.daily_limit', ''),
        );
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
            $enabledBanksValid = $enabledBanksValid && $this->payoutBankConfigured($bank);
        }
        $valid = $defaultBankEnabled && $hasEnabledBank && $enabledBanksValid && $limitsValid && $pollingValid;

        return $valid
            ? CheckResult::pass('thai_payout', 'configuration complete')
            : CheckResult::fail('thai_payout', 'bank credentials, confirmed request/response contract, and secure endpoints required');
    }

    /**
     * PromptPay is a deposit rail, but the merchant identity it carries is the
     * same one the Thai bank PromptPay destinations are paid to, so a
     * malformed identifier is worth reporting before a payout is attempted.
     */
    public function promptpay(): CheckResult
    {
        if (! (bool) config('payment.gateways.promptpay.enabled', false)) {
            return CheckResult::pass('promptpay', 'disabled (skipped)');
        }

        $merchant = config('payment.gateways.promptpay.merchant_id');
        $valid = is_string($merchant)
            && app(\App\Services\Payment\PromptPayQrGeneratorService::class)->isValidRecipientId($merchant);

        return $valid
            ? CheckResult::pass('promptpay', 'merchant identifier format valid')
            : CheckResult::fail('promptpay', 'PROMPTPAY_MERCHANT_ID is not a supported PromptPay identifier');
    }

    /**
     * Is this gateway switched on AND aimed at the market this site actually
     * serves?
     *
     * The same market allowlist PaymentGatewayManager enforces before a method
     * is displayed, asked here without resolving a driver: a lane whose market
     * does not include the site's currency and country can never be reached by a
     * player, so none of its half-finished configuration is a release finding.
     */
    private function liveOnSiteMarket(string $gateway): bool
    {
        if (! (bool) config('payment.gateways.'.$gateway.'.enabled', false)) {
            return false;
        }

        $market = (array) config('payment.markets.'.$gateway, []);
        $currencies = array_map('strtoupper', array_filter((array) ($market['currencies'] ?? []), 'is_string'));
        $countries = array_map('strtoupper', array_filter((array) ($market['countries'] ?? []), 'is_string'));

        // No market declaration means nothing restricts this lane.
        if ($currencies === [] && $countries === []) {
            return true;
        }

        $siteCurrency = strtoupper((string) config('payment.currency.default', 'THB'));
        $siteCountry = strtoupper((string) config('payment.country', 'TH'));

        return ($currencies === [] || in_array($siteCurrency, $currencies, true))
            && ($countries === [] || in_array($siteCountry, $countries, true));
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

    /**
     * Three decimal strings must parse, be positive and be ordered
     * min <= max and min <= daily. An unparseable limit is a FAIL, never a
     * silently ignored one.
     */
    private function limitsValid(string $minimum, string $maximum, string $daily): bool
    {
        try {
            $min = Money::of($minimum, Currency::THB);
            $max = Money::of($maximum, Currency::THB);
            $limit = Money::of($daily, Currency::THB);
        } catch (Throwable) {
            return false;
        }

        return $min->isPositive() && $max->isPositive() && $limit->isPositive()
            && bccomp($max->toString(), $min->toString(), 2) >= 0
            && bccomp($limit->toString(), $min->toString(), 2) >= 0;
    }

    private function safePath(string $path): bool
    {
        return preg_match('#\A/[A-Za-z0-9_./{}-]+\z#D', $path) === 1
            && preg_match('#(?:\A|/)\.\.(?:/|\z)#', $path) !== 1;
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
