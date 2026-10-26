<?php

declare(strict_types=1);

namespace App\Services\License;

use App\Exceptions\RealMoneyNotLicensedException;

/**
 * Real-money licence gate.
 *
 * Validates that the operator has configured a complete, unexpired licence
 * before any money-moving route (deposit, withdrawal, bet) may be used.
 *
 * The gate is intentionally fail-closed: every missing or invalid field
 * blocks real money.
 */
final class LicenseService
{
    /** Licence is only valid if every field below is present and non-empty. */
    private const REQUIRED_FIELDS = ['issuer', 'scope', 'expiry', 'countries'];

    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * Return true when real money is licensed for the given country.
     *
     * @param  string|null  $countryCode  ISO-3166 alpha-2 code (e.g. "TH", "BD")
     * @throws RealMoneyNotLicensedException when the gate refuses
     */
    public function assertLicensed(?string $countryCode = null): void
    {
        $license = $this->config['license'] ?? [];

        // Every required field must be present and non-empty.
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! isset($license[$field]) || (string) $license[$field] === '') {
                throw new RealMoneyNotLicensedException(
                    "real_money.license.{$field}_missing",
                    "Licence field [{$field}] is not configured."
                );
            }
        }

        // Expiry must be a valid future date.
        $expiry = (string) $license['expiry'];
        if (! $this->isValidFutureDate($expiry)) {
            throw new RealMoneyNotLicensedException(
                'real_money.license.expired',
                "Licence expiry [{$expiry}] is invalid or in the past."
            );
        }

        // Signature must be present (audit trail; not cryptographically verified here).
        if ((string) ($license['signature'] ?? '') === '') {
            throw new RealMoneyNotLicensedException(
                'real_money.license.signature_missing',
                'Licence signature is not configured.'
            );
        }

        // Country authorization: if a country is supplied, it must be in the list.
        if ($countryCode !== null && $countryCode !== '') {
            $authorized = $this->config['authorized_countries'] ?? [];
            if (! in_array(strtoupper($countryCode), $authorized, true)) {
                throw new RealMoneyNotLicensedException(
                    'real_money.license.country_not_authorized',
                    "Country [{$countryCode}] is not authorized by the licence."
                );
            }
        }
    }

    /**
     * Return a plain-language status report for UI/operator display.
     *
     * @return array{licensed: bool, reason: string|null, fields: array<string, bool>, expiry: string|null}
     */
    public function status(): array
    {
        $license = $this->config['license'] ?? [];
        $missing = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! isset($license[$field]) || (string) $license[$field] === '') {
                $missing[] = $field;
            }
        }

        $expiry = (string) ($license['expiry'] ?? '');
        $expired = $expiry !== '' && ! $this->isValidFutureDate($expiry);

        $signatureMissing = (string) ($license['signature'] ?? '') === '';

        $countries = $this->config['authorized_countries'] ?? [];
        $countriesEmpty = count($countries) === 0;

        $reason = null;
        if (count($missing) > 0) {
            $reason = 'missing_fields:'.implode(',', $missing);
        } elseif ($expired) {
            $reason = 'expired';
        } elseif ($signatureMissing) {
            $reason = 'signature_missing';
        } elseif ($countriesEmpty) {
            $reason = 'no_authorized_countries';
        }

        return [
            'licensed' => $reason === null,
            'reason'   => $reason,
            'fields'   => [
                'issuer'     => (string) ($license['issuer'] ?? '') !== '',
                'scope'      => (string) ($license['scope'] ?? '') !== '',
                'expiry'     => (string) ($license['expiry'] ?? '') !== '',
                'countries'  => (string) ($license['countries'] ?? '') !== '',
                'signature'  => $signatureMissing === false,
            ],
            'expiry'   => $expiry !== '' ? $expiry : null,
        ];
    }

    /**
     * Check whether a date string is a valid future date (ISO-8601).
     */
    private function isValidFutureDate(string $date): bool
    {
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);
        if ($parsed === false) {
            return false;
        }
        // Ensure the parsed date round-trips exactly (rejects "2024-13-01" etc.)
        if ($parsed->format('Y-m-d') !== $date) {
            return false;
        }

        return $parsed > new \DateTime('tomorrow', new \DateTimeZone('UTC'));
    }
}
