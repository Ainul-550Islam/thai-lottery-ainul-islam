<?php

/*
|--------------------------------------------------------------------------
| Canonical application fee schedule
|--------------------------------------------------------------------------
|
| Configuration-only fee catalogue for the public Fees page, the public fee
| JSON surface and the fee-preview calculator. Values are DECIMAL STRINGS
| only (never floats) and are this platform's own commercial settings —
| reproducible only as public fee-schedule concepts, never as any third
| party's proprietary implementation.
|
| ROW CONTRACT (every key below)
|   enabled          master switch; disabled rows never render or calculate
|   public_visible   only true rows render on GET /fees and GET /api/v1/fees
|   internal         true = operator-only rule; never rendered publicly even
|                    when enabled (double-guarded by 'never_public')
|   calculation      'fixed' | 'percentage' | 'none'
|   amount           fixed amount as decimal string (fixed rows only)
|   rate             percentage as decimal-string FRACTION of 1
|                    ('0.0300' = 3.00%) — percentage rows only
|   min / max        optional decimal-string bounds applied to a calculated
|                    fee (clamped after rounding)
|   currency         row-level currency (defaults to FEES_CURRENCY)
|   effective_from   inclusive first day the row applies (YYYY-MM-DD)
|   effective_to     EXCLUSIVE last day the row applies; null = open-ended
|   rule_version     version stamped onto every calculation of the row
|   group            public section the row renders under (fees.php groups)
|   provider         stable provider key (bank|skrill|neteller|paypal|
|                    perfect_money) for provider-specific rows, else null
|   description_key  translation key for the public description
|   description      English fallback used only when the translation key is
|                    missing (keeps the page honest if a lang file lags)
|   execution        config path of the LIVE rule the row mirrors at runtime
|                    (e.g. 'finance.withdrawal.fee_percentage'). The static
|                    rate is IGNORED for these rows: the runtime value of
|                    the live rule is the single source of truth, so the
|                    public schedule can never disagree with what the
|                    engine actually charges. Values on that path are
|                    WHOLE-PERCENT decimal strings ('8.00' = 8%) and are
|                    converted to fractions by FeesPageService.
|
| NOT_CONFIGURED: an enabled, public, percentage/fixed row whose amount or
| rate is null renders as NOT_CONFIGURED. The row stays visible — an
| unspecified percentage is a state, not a zero. Never invent a value for
| it: the operator configures it or it stays NOT_CONFIGURED.
|
| PUBLIC SCHEDULE vs LIVE EXECUTION
|   Rows with 'execution' mirror a live engine rule (display == execution).
|   Rows without 'execution' are public-schedule rows for services this
|   platform has no live fee-charging lane for yet. They are documented
|   fees only: nothing in the finance engine reads them, so publishing
|   them can never create a charge, a ledger posting or a double fee.
|
*/

return [

    'currency' => (string) env('FEES_CURRENCY', 'THB'),

    // Bumped whenever a fee row's economic meaning changes ('2' = provider
    // rows + grouped public schedule restructure).
    'rule_version' => (string) env('FEES_RULE_VERSION', '2'),

    /*
    |----------------------------------------------------------------------
    | Public groups (ordered page sections)
    |----------------------------------------------------------------------
    | The ordered information architecture of the public Fees page. Every
    | category below must belong to exactly one group; FeesPageServiceTest
    | asserts there are no orphan rows and no empty groups.
    | Label keys live in lang/{en,th}/account_services.php.
    */
    'groups' => [
        'account' => ['label_key' => 'account_services.fees_group_account'],
        'transfers' => ['label_key' => 'account_services.fees_group_transfers'],
        'withdrawal' => ['label_key' => 'account_services.fees_group_withdrawal'],
        'cash_in' => ['label_key' => 'account_services.fees_group_cash_in'],
        'agent' => ['label_key' => 'account_services.fees_group_agent'],
        'maintenance' => ['label_key' => 'account_services.fees_group_maintenance'],
    ],

    /*
    |----------------------------------------------------------------------
    | Stable public provider keys
    |----------------------------------------------------------------------
    | Provider identity for the public schedule. The LIVE payment-method
    | bridge (which of these has a real gateway lane today) is declared in
    | config/payment.php under 'public_fees'; this file only names labels.
    | No gateway secret, credential or internal identifier appears here.
    */
    'providers' => [
        'bank' => ['label_key' => 'account_services.fees_provider_bank'],
        'skrill' => ['label_key' => 'account_services.fees_provider_skrill'],
        'neteller' => ['label_key' => 'account_services.fees_provider_neteller'],
        'paypal' => ['label_key' => 'account_services.fees_provider_paypal'],
        'perfect_money' => ['label_key' => 'account_services.fees_provider_perfect_money'],
    ],

    'categories' => [

        // ------------------------------------------------------------ account
        'account_renewal' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'fixed',
            'amount' => (string) env('FEES_ACCOUNT_RENEWAL_AMOUNT', '3.00'),
            'rate' => null,
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '2',
            'internal' => false,
            'group' => 'account',
            'provider' => null,
            'description_key' => 'account_services.fees_description_account_renewal',
            'description' => 'One-time fee charged when an account renewal is billed.',
            'execution' => null,
        ],

        'account_verification' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'fixed',
            'amount' => (string) env('FEES_ACCOUNT_VERIFICATION_AMOUNT', '3.00'),
            'rate' => null,
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '2',
            'internal' => false,
            'group' => 'account',
            'provider' => null,
            'description_key' => 'account_services.fees_description_account_verification',
            'description' => 'Processing fee for reviewing submitted identity documents.',
            'execution' => null,
        ],

        'referral' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'fixed',
            'amount' => (string) env('FEES_REFERRAL_AMOUNT', '1.00'),
            'rate' => null,
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '2',
            'internal' => false,
            'group' => 'account',
            'provider' => null,
            'description_key' => 'account_services.fees_description_referral',
            'description' => 'Referral programme bonus value per qualifying referral.',
            'execution' => null,
        ],

        'affiliation' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_AFFILIATION_RATE', '0.0200'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '2',
            'internal' => false,
            'group' => 'account',
            'provider' => null,
            'description_key' => 'account_services.fees_description_affiliation',
            'description' => 'Affiliation programme bonus rate on qualifying activity.',
            'execution' => null,
        ],

        // ---------------------------------------------------------- transfers
        'cash_balance_transfer' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_CASH_BALANCE_TRANSFER_RATE', '0.0300'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '2',
            'internal' => false,
            'group' => 'transfers',
            'provider' => null,
            'description_key' => 'account_services.fees_description_cash_balance_transfer',
            'description' => 'Transfer fee on cash-wallet balance moves between eligible accounts.',
            'execution' => null,
        ],

        'win_balance_transfer' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_WIN_BALANCE_TRANSFER_RATE', '0.0200'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '2',
            'internal' => false,
            'group' => 'transfers',
            'provider' => null,
            'description_key' => 'account_services.fees_description_win_balance_transfer',
            'description' => 'Transfer fee on win-wallet balance moves between eligible accounts.',
            'execution' => null,
        ],

        'cash_to_win' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_CASH_TO_WIN_RATE', '0.0100'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '2',
            'internal' => false,
            'group' => 'transfers',
            'provider' => null,
            'description_key' => 'account_services.fees_description_cash_to_win',
            'description' => 'Conversion fee when moving balance from cash to win wallet.',
            'execution' => null,
        ],

        'win_to_cash' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_WIN_TO_CASH_RATE', '0.0100'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '2',
            'internal' => false,
            'group' => 'transfers',
            'provider' => null,
            'description_key' => 'account_services.fees_description_win_to_cash',
            'description' => 'Conversion fee when moving balance from win to cash wallet.',
            'execution' => null,
        ],

        'personal_to_agent' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_PERSONAL_TO_AGENT_RATE', '0.0800'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '2',
            'internal' => false,
            'group' => 'transfers',
            'provider' => null,
            'description_key' => 'account_services.fees_description_personal_to_agent',
            'description' => 'Fee when a personal account funds an eligible agent balance.',
            'execution' => null,
        ],

        // --------------------------------------------------------- withdrawal
        // Generic row: mirrors the LIVE payout-lane rule. All withdrawal
        // methods without a provider-specific row (including gateway-backed
        // methods) are charged exactly this rate by WithdrawalService.
        'withdrawal' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => null, // resolved at runtime from 'execution'
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'group' => 'withdrawal',
            'provider' => null,
            'description_key' => 'account_services.fees_description_withdrawal',
            'description' => 'Withdrawal processing fee percentage applied by the payout lane.',
            'execution' => 'finance.withdrawal.fee_percentage',
        ],

        'withdrawal_bank' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => null, // resolved at runtime from 'execution' (bank lane)
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'group' => 'withdrawal',
            'provider' => 'bank',
            'description_key' => 'account_services.fees_description_withdrawal_bank',
            'description' => 'Withdrawal processing fee for bank-transfer payouts.',
            'execution' => 'finance.withdrawal.fee_percentage',
        ],

        'withdrawal_skrill' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_SKRILL_WITHDRAWAL_RATE', '0.0900'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'group' => 'withdrawal',
            'provider' => 'skrill',
            'description_key' => 'account_services.fees_description_withdrawal_skrill',
            'description' => 'Withdrawal processing fee for Skrill payouts.',
            'execution' => null,
        ],

        'withdrawal_neteller' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_NETELLER_WITHDRAWAL_RATE', '0.0900'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'group' => 'withdrawal',
            'provider' => 'neteller',
            'description_key' => 'account_services.fees_description_withdrawal_neteller',
            'description' => 'Withdrawal processing fee for Neteller payouts.',
            'execution' => null,
        ],

        // Unspecified percentage: stays visible, stays NOT_CONFIGURED. The
        // operator sets FEES_PAYPAL_WITHDRAWAL_RATE to publish a value.
        'withdrawal_paypal' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => env('FEES_PAYPAL_WITHDRAWAL_RATE') !== null
                ? (string) env('FEES_PAYPAL_WITHDRAWAL_RATE')
                : null,
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'group' => 'withdrawal',
            'provider' => 'paypal',
            'description_key' => 'account_services.fees_description_withdrawal_paypal',
            'description' => 'Withdrawal processing fee for PayPal payouts (not yet specified).',
            'execution' => null,
        ],

        'withdrawal_perfect_money' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_PERFECT_MONEY_WITHDRAWAL_RATE', '0.0900'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'group' => 'withdrawal',
            'provider' => 'perfect_money',
            'description_key' => 'account_services.fees_description_withdrawal_perfect_money',
            'description' => 'Withdrawal processing fee for Perfect Money payouts.',
            'execution' => null,
        ],

        // ------------------------------------------------------------ cash in
        // Generic row: mirrors the LIVE deposit-lane rule charged by
        // DepositService for every cash-in method.
        'cash_in' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => null, // resolved at runtime from 'execution'
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-24',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'group' => 'cash_in',
            'provider' => null,
            'description_key' => 'account_services.fees_description_cash_in',
            'description' => 'Cash-in / deposit fee percentage charged by the payment lane.',
            'execution' => 'finance.deposit.fee_percentage',
        ],

        'cash_in_bank' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => null, // resolved at runtime from 'execution' (bank lane)
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'group' => 'cash_in',
            'provider' => 'bank',
            'description_key' => 'account_services.fees_description_cash_in_bank',
            'description' => 'Cash-in fee for bank-transfer deposits.',
            'execution' => 'finance.deposit.fee_percentage',
        ],

        'cash_in_skrill' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_SKRILL_CASH_IN_RATE', '0.0000'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'group' => 'cash_in',
            'provider' => 'skrill',
            'description_key' => 'account_services.fees_description_cash_in_skrill',
            'description' => 'Cash-in fee for Skrill deposits.',
            'execution' => null,
        ],

        'cash_in_neteller' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_NETELLER_CASH_IN_RATE', '0.0000'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'group' => 'cash_in',
            'provider' => 'neteller',
            'description_key' => 'account_services.fees_description_cash_in_neteller',
            'description' => 'Cash-in fee for Neteller deposits.',
            'execution' => null,
        ],

        // Unspecified percentage: NOT_CONFIGURED until the operator sets it.
        'cash_in_paypal' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => env('FEES_PAYPAL_CASH_IN_RATE') !== null
                ? (string) env('FEES_PAYPAL_CASH_IN_RATE')
                : null,
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'group' => 'cash_in',
            'provider' => 'paypal',
            'description_key' => 'account_services.fees_description_cash_in_paypal',
            'description' => 'Cash-in fee for PayPal deposits (not yet specified).',
            'execution' => null,
        ],

        'cash_in_perfect_money' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_PERFECT_MONEY_CASH_IN_RATE', '0.0000'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'group' => 'cash_in',
            'provider' => 'perfect_money',
            'description_key' => 'account_services.fees_description_cash_in_perfect_money',
            'description' => 'Cash-in fee for Perfect Money deposits.',
            'execution' => null,
        ],

        // -------------------------------------------------------------- agent
        'agent_to_agent' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_AGENT_TO_AGENT_RATE', '0.0100'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '2',
            'internal' => false,
            'group' => 'agent',
            'provider' => null,
            'description_key' => 'account_services.fees_description_agent_to_agent',
            'description' => 'Balance transfer fee between two agent accounts.',
            'execution' => null,
        ],

        'agent_to_win_commission' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_AGENT_TO_WIN_COMMISSION_RATE', '0.0400'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'group' => 'agent',
            'provider' => null,
            'description_key' => 'account_services.fees_description_agent_to_win_commission',
            'description' => 'Commission applied when an agent moves balance to a win wallet.',
            'execution' => null,
        ],

        'agent_to_cash_commission' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_AGENT_TO_CASH_COMMISSION_RATE', '0.0400'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'group' => 'agent',
            'provider' => null,
            'description_key' => 'account_services.fees_description_agent_to_cash_commission',
            'description' => 'Commission applied when an agent moves balance to a cash wallet.',
            'execution' => null,
        ],

        'personal_to_agent_commission' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_PERSONAL_TO_AGENT_COMMISSION_RATE', '0.0300'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => false,
            'group' => 'agent',
            'provider' => null,
            'description_key' => 'account_services.fees_description_personal_to_agent_commission',
            'description' => 'Commission credited to an agent on personal-to-agent funding.',
            'execution' => null,
        ],

        // Public display only: the live commission rate lives on each
        // agent's database row under the system ceiling; no individual
        // margin is ever published here.
        'agent_commission' => [
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
            'group' => 'agent',
            'provider' => null,
            'description_key' => 'account_services.fees_description_agent_commission',
            'description' => 'Agent commission is set per agent under the system maximum rate.',
            'execution' => null,
        ],

        // --------------------------------------------------------- maintenance
        'maintenance' => [
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'fixed',
            'amount' => (string) env('FEES_MAINTENANCE_AMOUNT', '5.00'),
            'rate' => null,
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '2',
            'internal' => false,
            'group' => 'maintenance',
            'provider' => null,
            'description_key' => 'account_services.fees_description_maintenance',
            'description' => 'Yearly account maintenance fee (when renewal billing is enabled).',
            'execution' => null,
        ],

        // ------------------------------------------------------------ internal
        // Operator margin reference. internal=true + public_visible=false +
        // the 'never_public' list below: three independent guards keep this
        // off every public surface. It exists to prove internal suppression
        // against real production config, not only test overrides.
        'internal_provider_margin' => [
            'enabled' => true,
            'public_visible' => false,
            'calculation' => 'percentage',
            'amount' => null,
            'rate' => (string) env('FEES_INTERNAL_PROVIDER_MARGIN_RATE', '0.0200'),
            'min' => null,
            'max' => null,
            'currency' => (string) env('FEES_CURRENCY', 'THB'),
            'effective_from' => '2026-09-27',
            'effective_to' => null,
            'rule_version' => '1',
            'internal' => true,
            'group' => 'withdrawal',
            'provider' => null,
            'description_key' => 'account_services.fees_description_internal_provider_margin',
            'description' => 'Internal operator margin reference. Never published.',
            'execution' => null,
        ],
    ],

    // Categories never published even if present above (double guard).
    'never_public' => [
        'internal_provider_margin',
    ],

];
