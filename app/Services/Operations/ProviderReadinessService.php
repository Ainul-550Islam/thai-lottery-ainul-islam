<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Enums\GatewayIntegrationStatus;
use Illuminate\Support\Facades\Config;

/**
 * One honest answer per provider lane: configured, or NOT_CONFIGURED — and why.
 *
 * WHY THIS EXISTS SEPARATELY FROM ProviderHealthService
 * `ProviderHealthService::knownProviders()` is built from observed traffic:
 * it lists the gateways that appear in `payments`, `payment_webhooks` and
 * `provider_operations`. That is the right question for a health desk, and the
 * wrong one for readiness — a lane that has never been used is invisible to it,
 * and a lane with no credentials at all is exactly the one an operator needs to
 * see before launch.
 *
 * WHAT IT WILL NOT DO
 * It does not guess a provider's status, synthesise an uptime figure, or call
 * any external API. It reads the lane catalogue in
 * config/operations_readiness.php and reports what the named keys hold. A lane
 * that is off stays NOT_CONFIGURED; that is a true statement about this
 * deployment, not a placeholder to be filled in.
 *
 * DECLARATION GAP vs CONFIGURATION GAP
 * The `declared` list names the config keys that must EXIST for the lane to be
 * declared at all; a missing one is a LANE_NOT_DECLARED finding. The
 * `credentials` list names the keys that must hold a value once the lane is in
 * use; an existing-but-empty one is a NOT_CONFIGURED /
 * ENABLED_WITHOUT_CREDENTIALS gap. The hyphenated config file is only a
 * schedule alias and never carries the lanes.
 *
 * THE STATE THAT MATTERS MOST
 * `ENABLED_WITHOUT_CREDENTIALS` — a switch flipped on with no credentials behind
 * it. NOT_CONFIGURED is safe and visible; a half-configured lane is the one that
 * fails at the moment a player uses it.
 */
final class ProviderReadinessService
{
    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    public const CONFIGURED = 'CONFIGURED';

    public const ENABLED_WITHOUT_CREDENTIALS = 'ENABLED_WITHOUT_CREDENTIALS';

    public const LANE_NOT_DECLARED = 'LANE_NOT_DECLARED';

    /**
     * The config root that holds the lane catalogue. Kept in one place so the
     * hyphenated alias file (which only carries required_scheduled_commands)
     * can never again be mistaken for the source of the lanes.
     */
    private const LANES_CONFIG_KEY = 'operations_readiness.lanes';

    /**
     * The gateway catalogue, and the keys each one must hold before it can be
     * called ready. `bank_transfer` and `thai_bank` have no flat credential
     * list because their requirements depend on direction and on the selected
     * bank, so they are judged separately below.
     *
     * @var list<string>
     */
    private const GATEWAYS = ['stripe', 'bkash', 'nagad', 'crypto', 'promptpay', 'bank_transfer', 'thai_bank'];

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

    /** @var list<string> */
    private const SCB_PATHS = ['token_path', 'transfer_path', 'status_path'];

    /**
     * Every declared lane, in config order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function lanes(): array
    {
        $report = [];

        foreach ((array) config(self::LANES_CONFIG_KEY, []) as $lane => $definition) {
            $report[(string) $lane] = $this->lane((string) $lane, (array) $definition);
        }

        return $report;
    }

    /**
     * One lane. Unknown lanes are reported rather than throwing, because a
     * readiness report that dies on a typo tells an operator nothing.
     *
     * @param  array<string, mixed>|null  $definition
     * @return array<string, mixed>
     */
    public function lane(string $key, ?array $definition = null): array
    {
        // Array lookup, not a dotted config path: lane names contain dots
        // ('captcha.turnstile'), and config() would read those as nesting and
        // silently miss the lane.
        if ($definition === null) {
            $lanes = (array) config(self::LANES_CONFIG_KEY, []);
            $definition = (array) ($lanes[$key] ?? []);
        }

        if ($definition === []) {
            return [
                'lane' => $key,
                'state' => self::LANE_NOT_DECLARED,
                'enabled' => false,
                'switch' => null,
                'missing' => [],
                'undeclared' => [$key],
                'note' => 'This lane is not declared in config/operations_readiness.php.',
            ];
        }

        $switch = $definition['switch'] ?? null;
        $switch = is_string($switch) && $switch !== '' ? $switch : null;
        $credentials = array_values(array_map('strval', (array) ($definition['credentials'] ?? [])));
        $declared = array_values(array_map('strval', (array) ($definition['declared'] ?? [])));

        $enabled = $switch !== null ? (bool) config($switch, false) : false;

        $undeclared = [];
        $missing = [];

        // A declaration key that does not exist at all means the lane was
        // never fully provisioned in configuration — distinct from an empty
        // value the operator still has to fill in.
        foreach ($declared as $declaredKey) {
            if (! Config::has($declaredKey)) {
                $undeclared[] = $declaredKey;
            }
        }

        foreach ($credentials as $credentialKey) {
            if (! Config::has($credentialKey)) {
                $undeclared[] = $credentialKey;

                continue;
            }

            if (! $this->hasValue(config($credentialKey))) {
                $missing[] = $credentialKey;
            }
        }

        $undeclared = array_values(array_unique($undeclared));
        $missing = array_values(array_unique($missing));

        // A declaration gap wins over every other verdict: the report cannot
        // claim a lane is merely "off" when part of it does not exist.
        if ($undeclared !== []) {
            $state = self::LANE_NOT_DECLARED;
        } elseif ($switch === null) {
            // A lane with no switch is judged on its credentials alone: there
            // is nothing to turn on, so "configured" means the values exist.
            $state = $missing !== [] ? self::NOT_CONFIGURED : self::CONFIGURED;
        } elseif (! $enabled) {
            $state = self::NOT_CONFIGURED;
        } elseif ($missing !== []) {
            $state = self::ENABLED_WITHOUT_CREDENTIALS;
        } else {
            $state = self::CONFIGURED;
        }

        return [
            'lane' => $key,
            'state' => $state,
            'enabled' => $enabled,
            'switch' => $switch,
            'missing' => $missing,
            'undeclared' => $undeclared,
            'note' => (string) ($definition['note'] ?? ''),
        ];
    }

    /**
     * Only the lanes an operator has to act on: anything that is neither
     * cleanly CONFIGURED nor cleanly NOT_CONFIGURED.
     *
     * @return array<string, array<string, mixed>>
     */
    public function findings(): array
    {
        return array_filter(
            $this->lanes(),
            static fn (array $lane): bool => in_array(
                $lane['state'],
                [self::ENABLED_WITHOUT_CREDENTIALS, self::LANE_NOT_DECLARED],
                true,
            ),
        );
    }

    /**
     * @return array{configured: int, not_configured: int, enabled_without_credentials: int, lane_not_declared: int}
     */
    public function summary(): array
    {
        $counts = [
            'configured' => 0,
            'not_configured' => 0,
            'enabled_without_credentials' => 0,
            'lane_not_declared' => 0,
        ];

        foreach ($this->lanes() as $lane) {
            $counts[match ($lane['state']) {
                self::CONFIGURED => 'configured',
                self::NOT_CONFIGURED => 'not_configured',
                self::ENABLED_WITHOUT_CREDENTIALS => 'enabled_without_credentials',
                default => 'lane_not_declared',
            }]++;
        }

        return $counts;
    }

    /**
     * Every gateway the platform knows about, in a fixed order.
     *
     * @return array<string, PaymentGatewayReadiness>
     */
    public function gateways(): array
    {
        $report = [];
        foreach (self::GATEWAYS as $gateway) {
            $report[$gateway] = $this->gateway($gateway);
        }

        return $report;
    }

    /**
     * One gateway's readiness, judged from configuration only.
     *
     * This lives here rather than in a payment-specific service because a
     * readiness answer must have exactly one owner: the same object answers the
     * console command, the ops JSON endpoint, the boot refusal and the deposit
     * page. Two evaluators that disagree is the bug this consolidation removes.
     *
     * It performs no network or database operation, and it never echoes a
     * credential — issues name the missing KEY, never its value.
     */
    public function gateway(string $gateway, ?string $direction = null): PaymentGatewayReadiness
    {
        $gateway = strtolower(trim($gateway));
        $config = (array) config('payment.gateways.'.$gateway, []);
        $market = (array) config('payment.markets.'.$gateway, []);
        $currencies = array_values(array_map('strtoupper', array_filter((array) ($market['currencies'] ?? $config['supported_currencies'] ?? []), 'is_string')));
        $countries = array_values(array_map('strtoupper', array_filter((array) ($market['countries'] ?? []), 'is_string')));
        $enabled = $gateway === 'thai_bank'
            ? (bool) config('thai_payout.enabled', false)
            : (bool) ($config['enabled'] ?? false);
        $status = match ($gateway) {
            'crypto' => GatewayIntegrationStatus::Unsupported,
            'bank_transfer' => GatewayIntegrationStatus::ConfiguredOnly,
            'thai_bank' => GatewayIntegrationStatus::PartiallyImplemented,
            default => GatewayIntegrationStatus::FullyImplemented,
        };
        $issues = [];

        if (! in_array($gateway, self::GATEWAYS, true)) {
            $issues[] = 'gateway_unknown';
        }
        if (! $enabled) {
            $issues[] = 'gateway_disabled';
        }
        if ($status === GatewayIntegrationStatus::Unsupported) {
            $issues[] = 'gateway_unsupported';
        }

        // BDT-MARKET GUARD. A Thai-baht deployment must not be configured as a
        // BDT/BD market: no part of the Thai payout lane is reconciled against
        // taka, so the mismatch has to surface as a readiness failure rather
        // than as a currency that quietly settles wrongly.
        $siteCurrency = strtoupper((string) config('payment.currency.default', 'THB'));
        $siteCountry = strtoupper((string) config('payment.country', 'TH'));

        foreach (self::REQUIRED[$gateway] ?? [] as $key) {
            $value = $config[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $issues[] = 'missing_'.$key;
            }
        }
        if (in_array($gateway, ['bkash', 'nagad'], true) && ! $this->httpsUrl((string) ($config['base_url'] ?? ''))) {
            $issues[] = 'invalid_base_url';
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
            $bank = strtolower((string) config('thai_payout.default_bank', ''));
            $bankConfig = (array) config('thai_payout.banks.'.$bank, []);
            foreach (['enabled', 'contract_confirmed', 'base_url', 'token_path', 'transfer_path', 'status_path', 'client_id', 'client_secret', 'source_account'] as $key) {
                $value = $bankConfig[$key] ?? null;
                if (($key === 'enabled' || $key === 'contract_confirmed') ? $value !== true : (! is_string($value) || trim($value) === '')) {
                    $issues[] = 'thai_bank_missing_'.$key;
                }
            }
            if (! $this->httpsUrl((string) ($bankConfig['base_url'] ?? ''))) {
                $issues[] = 'thai_bank_invalid_base_url';
            }

            // BLANK SCB PATHS. A valid host with no endpoint path passes every
            // URL check and still sends the payout nowhere.
            foreach (self::SCB_PATHS as $path) {
                if (trim((string) config('thai_payout.banks.scb.'.$path, '')) === ''
                    && (bool) config('thai_payout.banks.scb.enabled', false)) {
                    $issues[] = 'scb_blank_'.$path;
                }
            }
        }

        if ($siteCurrency === 'BDT' || $siteCountry === 'BD') {
            $issues[] = 'bdt_market_refused';
        }
        if ($currencies !== [] && ! in_array($siteCurrency, $currencies, true)) {
            $issues[] = 'site_currency_not_in_market';
        }
        if ($countries !== [] && ! in_array($siteCountry, $countries, true)) {
            $issues[] = 'site_country_not_in_market';
        }
        $issues = array_values(array_unique($issues));

        return new PaymentGatewayReadiness($gateway, $enabled, $issues === [], $status, $issues, $currencies, $countries);
    }

    /**
     * A credential-bearing endpoint must be HTTPS, must name a host, and must
     * not smuggle userinfo, a query or a fragment into what the operator
     * believes is a base URL.
     */
    private function httpsUrl(string $url): bool
    {
        $parts = parse_url(trim($url));

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null) && $parts['host'] !== ''
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment']);
    }

    /**
     * A value counts when it is present and not an empty string. `false` is a
     * real value — a sandbox flag set to false is configured, not missing.
     */
    private function hasValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }
}
