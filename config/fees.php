<?php

/*
|--------------------------------------------------------------------------
| Canonical application fee schedule
|--------------------------------------------------------------------------
|
| Configuration-only fee catalogue for the public Fees page and any fee
| calculator. Values are DECIMAL STRINGS only (never floats) and are this
| platform's own commercial settings — NOT copied from any competitor.
|
| calculation: 'fixed' | 'percentage' | 'none'
| amount: fixed baht as decimal string (ignored when calculation=none)
| rate: percentage rate as decimal string fraction of 1 (e.g. '0.0150' = 1.50%)
| public_visible: only true rows render on GET /fees
| enabled: master switch; disabled rows never render or calculate
| version: fee rule version stamped onto historical calculations
|
| NOT_CONFIGURED: amount/rate null + enabled false → page shows NOT_CONFIGURED.
|
*/

return [

    'currency' => (string) env('FEES_CURRENCY', 'THB'),

    // Bumped whenever a fee row's economic meaning changes.
    'rule_version' => (string) env('FEES_RULE_VERSION', '1'),

    'categories' => [

        'account_renewal' => [
            'enabled' => false,
            'public_visible' => true,
            'calculation' => 'none',
            'amount' => null,
            'rate' => null,
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'description' => 'Account renewal fee (when renewal billing is enabled).',
        ],

        'account_verification' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'fixed',
            'amount' => '0.00',
            'rate' => null,
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'description' => 'Identity document upload verification — no platform fee.',
        ],

        'referral' => [
            'enabled' => false,
            'public_visible' => true,
            'calculation' => 'none',
            'amount' => null,
            'rate' => null,
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'description' => 'Referral programme fees (when enabled).',
        ],

        'affiliation' => [
            'enabled' => false,
            'public_visible' => true,
            'calculation' => 'none',
            'amount' => null,
            'rate' => null,
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'description' => 'Affiliation programme fees (when enabled).',
        ],

        'cash_balance_transfer' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => '0.0150',
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'description' => 'Transfer fee on cash-wallet balance moves between eligible accounts.',
        ],

        'win_balance_transfer' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => '0.0150',
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'description' => 'Transfer fee on win-wallet balance moves between eligible accounts.',
        ],

        'cash_to_win' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => '0.0050',
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'description' => 'Conversion fee when moving balance from cash to win wallet.',
        ],

        'win_to_cash' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => '0.0050',
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'description' => 'Conversion fee when moving balance from win to cash wallet.',
        ],

        'personal_to_agent' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => '0.0500',
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'description' => 'Fee when a personal account funds an eligible agent balance.',
        ],

        'withdrawal' => [
            // Display default mirrors finance.withdrawal.fee_percentage at
            // runtime via FeesPageService when that key is non-zero; the
            // static default stays 0.00 so config load order cannot drift.
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FINANCE_WITHDRAWAL_FEE_PERCENTAGE', '0.00'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'description' => 'Withdrawal processing fee percentage applied by the payout lane.',
        ],

        'cash_in' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FINANCE_DEPOSIT_FEE_PERCENTAGE', '0.00'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'description' => 'Cash-in / deposit fee percentage charged by the payment lane.',
        ],

        'agent_to_agent' => [
            'enabled' => false,
            'public_visible' => true,
            'calculation' => 'none',
            'amount' => null,
            'rate' => null,
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'description' => 'Agent-to-agent balance transfer fee (when enabled).',
        ],

        'agent_commission' => [
            // Public display only: rate comes from each agent's DB row and the
            // system ceiling; no individual margin is published here.
            'enabled' => (bool) env('AGENT_COMMISSION_ENABLED', false),
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => null, // per-agent; system max is config('agent.commission.max_rate')
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'description' => 'Agent commission is set per agent under the system maximum rate.',
        ],

        'maintenance' => [
            'enabled' => false,
            'public_visible' => true,
            'calculation' => 'none',
            'amount' => null,
            'rate' => null,
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'description' => 'Scheduled maintenance surcharge (when enabled).',
        ],
    ],

    // Categories never published even if present above (double guard).
    'never_public' => [],

];
