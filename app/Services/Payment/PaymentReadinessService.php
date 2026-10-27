<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\GatewayIntegrationStatus;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/** Pure configuration evaluator: it performs no network or database operations. */
final class PaymentReadinessService
{
    /** @var array<string, list<string>> */
    private const REQUIRED = [
        'stripe' => ['key', 'secret', 'webhook_secret'],
        'bkash' => ['app_key', 'app_secret', 'username', 'password', 'base_url', 'webhook_secret'],
        'nagad' => ['merchant_id', 'merchant_number', 'app_key', 'app_secret', 'public_key', 'private_key', 'base_url', 'webhook_secret'],
        'crypto' => ['provider', 'api_key', 'api_secret', 'webhook_secret'],
        'promptpay' => ['merchant_id'],
        'bank_transfer' => [],
        'thai_bank' => [],
    ];

    public function __construct(private readonly ConfigRepository $config) {}

    /** @return array<string, PaymentGatewayReadiness> */
    public function evaluateAll(): array
    {
        $results = [];
        foreach (array_keys(self::REQUIRED) as $gateway) {
            $results[$gateway] = $this->evaluate($gateway);
        }

        return $results;
    }

    public function evaluate(string $gateway, ?string $direction = null): PaymentGatewayReadiness
    {
        $gateway = strtolower(trim($gateway));
        $config = (array) $this->config->get('payment.gateways.'.$gateway, []);
        $market = (array) $this->config->get('payment.markets.'.$gateway, []);
        $currencies = array_values(array_map('strtoupper', array_filter((array) ($market['currencies'] ?? $config['supported_currencies'] ?? []), 'is_string')));
        $countries = array_values(array_map('strtoupper', array_filter((array) ($market['countries'] ?? []), 'is_string')));
        $enabled = $gateway === 'thai_bank'
            ? (bool) $this->config->get('thai_payout.enabled', false)
            : (bool) ($config['enabled'] ?? false);
        $status = match ($gateway) {
            'crypto' => GatewayIntegrationStatus::Unsupported,
            'bank_transfer' => GatewayIntegrationStatus::ConfiguredOnly,
            'thai_bank' => GatewayIntegrationStatus::PartiallyImplemented,
            default => GatewayIntegrationStatus::FullyImplemented,
        };
        $issues = [];

        if (! array_key_exists($gateway, self::REQUIRED)) {
            $issues[] = 'gateway_unknown';
        }
        if (! $enabled) {
            $issues[] = 'gateway_disabled';
        }
        if ($status === GatewayIntegrationStatus::Unsupported) {
            $issues[] = 'gateway_unsupported';
        }

        foreach (self::REQUIRED[$gateway] ?? [] as $key) {
            $value = $config[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $issues[] = 'missing_'.$key;
            }
        }
        if (in_array($gateway, ['bkash', 'nagad'], true) && ! $this->httpsUrl((string) ($config['base_url'] ?? ''))) {
            $issues[] = 'invalid_base_url';
        }
        if ($gateway === 'stripe' && ! $this->httpsUrl((string) $this->config->get('services.stripe.webhook_url', 'https://api.stripe.com'))) {
            // Stripe's API URL is fixed by the SDK; credentials are the meaningful readiness gate.
        }
        if ($gateway === 'bank_transfer' && ($direction === null || $direction === 'deposit')) {
            foreach (['bank_name', 'account_number', 'account_name'] as $key) {
                $value = $config['settlement'][$key] ?? null;
                if (! is_string($value) || trim($value) === '') {
                    $issues[] = 'missing_settlement_'.$key;
                }
            }
        }
        if ($gateway === 'promptpay') {
            $bankSecrets = (array) ($config['bank_webhooks'] ?? []);
            $hasWebhookSecret = false;
            foreach (['kbank', 'scb'] as $bank) {
                $secret = $bankSecrets[$bank]['webhook_secret'] ?? null;
                $hasWebhookSecret = $hasWebhookSecret || (is_string($secret) && trim($secret) !== '');
            }
            if (! $hasWebhookSecret) {
                $issues[] = 'missing_bank_webhook_secret';
            }
        }
        if ($gateway === 'thai_bank') {
            $bank = strtolower((string) $this->config->get('thai_payout.default_bank', ''));
            $bankConfig = (array) $this->config->get('thai_payout.banks.'.$bank, []);
            foreach (['enabled', 'contract_confirmed', 'base_url', 'token_path', 'transfer_path', 'status_path', 'client_id', 'client_secret', 'source_account'] as $key) {
                $value = $bankConfig[$key] ?? null;
                if (($key === 'enabled' || $key === 'contract_confirmed') ? $value !== true : (! is_string($value) || trim($value) === '')) {
                    $issues[] = 'thai_bank_missing_'.$key;
                }
            }
            if (! $this->httpsUrl((string) ($bankConfig['base_url'] ?? ''))) {
                $issues[] = 'thai_bank_invalid_base_url';
            }
        }

        $siteCurrency = strtoupper((string) $this->config->get('payment.currency.default', 'THB'));
        $siteCountry = strtoupper((string) $this->config->get('payment.country', 'TH'));
        if ($currencies !== [] && ! in_array($siteCurrency, $currencies, true)) {
            $issues[] = 'site_currency_not_in_market';
        }
        if ($countries !== [] && ! in_array($siteCountry, $countries, true)) {
            $issues[] = 'site_country_not_in_market';
        }
        $issues = array_values(array_unique($issues));

        return new PaymentGatewayReadiness($gateway, $enabled, $issues === [], $status, $issues, $currencies, $countries);
    }

    private function httpsUrl(string $url): bool
    {
        $parts = parse_url(trim($url));

        return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null) && $parts['host'] !== ''
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']);
    }
}
