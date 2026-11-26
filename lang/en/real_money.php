<?php

/**
 * Real-money licence gate copy.
 *
 * The keys mirror the message codes LicenseService throws and the codes the
 * about page lists, so a refusal is always shown as a sentence instead of a raw
 * translation key. `license.*` is the exact shape of the dotted codes
 * (real_money.license.issuer_missing, …).
 */

return [
    'not_licensed' => 'Real-money features are not licensed for this deployment. Please contact the operator.',

    'license' => [
        'issuer_missing' => 'The licence issuer is not configured.',
        'scope_missing' => 'The licence scope is not configured.',
        'expiry_missing' => 'The licence expiry date is not configured.',
        'countries_missing' => 'The licence does not list any authorised country.',
        'signature_missing' => 'The licence signature is not configured.',
        'expired' => 'The licence has expired.',
        'country_not_authorized' => 'Your country is not authorised by the licence.',
    ],

    /*
     * Technical readiness (RealMoneyTechnicalReadinessService).
     *
     * These are operator-facing and console-facing sentences. They name the
     * operation and the failing checks but never a credential, a key value or a
     * destination: the messages travel into CI transcripts, deploy logs and
     * incident tickets.
     */
    'technical' => [
        'assert_failed' => 'The platform is not technically ready to carry a :operation; refusing before any balance is read or written.',
        'operation_unknown' => 'Unknown money operation [:operation].',
    ],

    /*
     * Operation-level controls (MoneyOperationControlService).
     */
    'control' => [
        'operation_unknown' => 'Unknown money operation [:operation].',
        'master_switch_closed' => 'Real money is closed on this deployment, so a :operation is refused.',
        'operation_control_closed' => 'The runtime money operation control is closed, so a :operation is refused.',
        'currency_unsupported' => 'The :operation currency [:currency] is not a supported currency.',
        'amount_invalid' => 'The :operation amount is not a positive decimal value.',
        'amount_out_of_bounds' => 'The :operation amount is outside the configured :limit bound of :bound :currency.',
        'enforced_refusal' => 'The :operation was refused by an enforced control: :checks.',
        'allowed' => 'The :operation is allowed: every enforced control is satisfied.',
        'allowed_with_warnings' => 'The :operation is allowed, but :count report-mode control(s) recorded a finding.',
    ],
];
