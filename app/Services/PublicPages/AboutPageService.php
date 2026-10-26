<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Canonical public About-page data source.
 *
 * All user-facing copy is localized through public_pages translations. The
 * service only composes approved public data, validates timeline records, and
 * resolves CTAs to named routes that exist in the application.
 */
final class AboutPageService
{
    public function __construct(
        private readonly PublicPageTextBag $text,
        private readonly AboutTimelineService $timeline,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function data(?string $locale = null): array
    {
        $locale = $locale !== null && $locale !== '' ? $locale : (string) app()->getLocale();

        return [
            'status' => 'AVAILABLE',
            'locale' => $locale,
            'title' => $this->text->line('about_title', $locale),
            'lead' => $this->text->line('about_lead', $locale),
            'meta_title' => $this->text->line('about_meta_title', $locale),
            'meta_description' => $this->text->line('about_meta_description', $locale),
            'hero' => [
                'eyebrow' => $this->text->line('about_hero_eyebrow', $locale),
                'title' => $this->text->line('about_hero_title', $locale),
                'visual_label' => $this->text->line('about_hero_visual_label', $locale),
            ],
            'story' => [
                'eyebrow' => $this->text->line('about_story_eyebrow', $locale),
                'title' => $this->text->line('history_title', $locale),
                'text' => $this->text->line('history_text', $locale),
                'source_note' => $this->text->line('about_story_source', $locale),
            ],
            'timeline' => [
                'eyebrow' => $this->text->line('about_timeline_eyebrow', $locale),
                'title' => $this->text->line('about_timeline_title', $locale),
                'text' => $this->text->line('about_timeline_text', $locale),
                'source_label' => $this->text->line('about_timeline_source_label', $locale),
                'empty_label' => $this->text->line('about_timeline_empty', $locale),
                'items' => $this->timeline->milestones($locale),
            ],
            'how_it_works' => $this->howItWorks($locale),
            'values' => [
                'eyebrow' => $this->text->line('about_values_eyebrow', $locale),
                'title' => $this->text->line('about_values_title', $locale),
                'text' => $this->text->line('about_values_text', $locale),
                'items' => $this->values($locale),
            ],
            'trust' => [
                'eyebrow' => $this->text->line('about_trust_eyebrow', $locale),
                'title' => $this->text->line('about_trust_title', $locale),
                'text' => $this->text->line('about_trust_text', $locale),
                'items' => $this->trustItems($locale),
            ],
            // ========================================================================================
            // NEW SECTIONS — comprehensive about content
            // ========================================================================================
            'identity' => [
                'title' => $this->text->line('about_identity_title', $locale),
                'lead' => $this->text->line('about_identity_lead', $locale),
                'full_text' => $this->text->line('about_identity_full_text', $locale),
                'rows' => $this->identityRows($locale),
            ],
            'real_money' => [
                'title' => $this->text->line('about_real_money_title', $locale),
                'lead' => $this->text->line('about_real_money_lead', $locale),
                'full_text' => $this->text->line('about_real_money_full_text', $locale),
                'fields' => $this->realMoneyFields($locale),
                'error_codes' => $this->realMoneyErrorCodes($locale),
            ],
            'security' => [
                'title' => $this->text->line('about_security_title', $locale),
                'lead' => $this->text->line('about_security_lead', $locale),
                'full_text' => $this->text->line('about_security_full_text', $locale),
                'threat_model' => $this->securityThreatModel($locale),
            ],
            'finance' => [
                'title' => $this->text->line('about_finance_title', $locale),
                'lead' => $this->text->line('about_finance_lead', $locale),
                'full_text' => $this->text->line('about_finance_full_text', $locale),
                'currencies' => $this->financeCurrencies($locale),
                'methods' => $this->financeMethods($locale),
            ],
            'kyc' => [
                'title' => $this->text->line('about_kyc_title', $locale),
                'lead' => $this->text->line('about_kyc_lead', $locale),
                'full_text' => $this->text->line('about_kyc_full_text', $locale),
                'steps' => $this->kycSteps($locale),
            ],
            'responsible_gaming' => [
                'title' => $this->text->line('about_responsible_gaming_title', $locale),
                'lead' => $this->text->line('about_responsible_gaming_lead', $locale),
                'full_text' => $this->text->line('about_responsible_gaming_full_text', $locale),
                'limits_table' => $this->responsibleGamingLimits($locale),
            ],
            'lottery' => [
                'title' => $this->text->line('about_lottery_title', $locale),
                'lead' => $this->text->line('about_lottery_lead', $locale),
                'full_text' => $this->text->line('about_lottery_full_text', $locale),
                'draw_types' => $this->lotteryDrawTypes($locale),
            ],
            'betting' => [
                'title' => $this->text->line('about_betting_title', $locale),
                'lead' => $this->text->line('about_betting_lead', $locale),
                'full_text' => $this->text->line('about_betting_full_text', $locale),
                'markets' => $this->bettingMarkets($locale),
            ],
            'deposit_withdraw' => [
                'title' => $this->text->line('about_deposit_withdraw_title', $locale),
                'lead' => $this->text->line('about_deposit_withdraw_lead', $locale),
                'full_text' => $this->text->line('about_deposit_withdraw_full_text', $locale),
                'steps' => $this->depositWithdrawSteps($locale),
            ],
            'wallet' => [
                'title' => $this->text->line('about_wallet_title', $locale),
                'lead' => $this->text->line('about_wallet_lead', $locale),
                'full_text' => $this->text->line('about_wallet_full_text', $locale),
                'pockets' => $this->walletPockets($locale),
            ],
            'agent' => [
                'title' => $this->text->line('about_agent_title', $locale),
                'lead' => $this->text->line('about_agent_lead', $locale),
                'full_text' => $this->text->line('about_agent_full_text', $locale),
                'features' => $this->agentFeatures($locale),
            ],
            'support' => [
                'title' => $this->text->line('about_support_title', $locale),
                'lead' => $this->text->line('about_support_lead', $locale),
                'full_text' => $this->text->line('about_support_full_text', $locale),
                'topics' => $this->supportTopics($locale),
            ],
            'privacy' => [
                'title' => $this->text->line('about_privacy_title', $locale),
                'lead' => $this->text->line('about_privacy_lead', $locale),
                'full_text' => $this->text->line('about_privacy_full_text', $locale),
                'rights' => $this->privacyRights($locale),
            ],
            'tech' => [
                'title' => $this->text->line('about_tech_title', $locale),
                'lead' => $this->text->line('about_tech_lead', $locale),
                'full_text' => $this->text->line('about_tech_full_text', $locale),
                'stack' => $this->techStack($locale),
            ],
            'governance' => [
                'title' => $this->text->line('about_governance_title', $locale),
                'lead' => $this->text->line('about_governance_lead', $locale),
                'full_text' => $this->text->line('about_governance_full_text', $locale),
                'controls' => $this->governanceControls($locale),
            ],
            'disclaimer' => [
                'title' => $this->text->line('about_disclaimer_title', $locale),
                'lead' => $this->text->line('about_disclaimer_lead', $locale),
                'full_text' => $this->text->line('about_disclaimer_full_text', $locale),
                'items' => $this->disclaimerItems($locale),
            ],
            'contact' => [
                'title' => $this->text->line('about_contact_title', $locale),
                'lead' => $this->text->line('about_contact_lead', $locale),
                'full_text' => $this->text->line('about_contact_full_text', $locale),
                'channels' => $this->contactChannels($locale),
            ],
            // Render every canonical policy/detail entry, including structured lists.
            'detailed_sections' => $this->detailedSections($locale),
            'useful_links' => $this->usefulLinks($locale),
            'contact_route' => [
                'title' => $this->text->line('contact_title', $locale),
                'text' => $this->text->line('contact_text', $locale),
                'href' => $this->namedRoute('contact'),
            ],
            'cta' => [
                'title' => $this->text->line('about_cta_title', $locale),
                'text' => $this->text->line('about_cta_text', $locale),
                'source_note' => $this->text->line('about_source_note', $locale),
                'links' => [
                    [
                        'key' => 'results',
                        'label' => $this->text->line('about_cta_results', $locale),
                        'href' => $this->namedRoute('results.index'),
                    ],
                    [
                        'key' => 'ticket_check',
                        'label' => $this->text->line('about_cta_check', $locale),
                        'href' => $this->namedRoute('ticket-check'),
                    ],
                    [
                        'key' => 'contact',
                        'label' => $this->text->line('about_cta_contact', $locale),
                        'href' => $this->namedRoute('contact'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Build all 17 detailed About sections from the canonical locale file.
     * Values from nested arrays are flattened for the accessible Blade view;
     * no source row is discarded.
     *
     * @return array<int, array{key: string, title: string, lead: string, full_text: string, items: array<int, array{label: string, value: string}>}>
     */
    private function detailedSections(string $locale): array
    {
        $sections = [
            'identity' => 'Company identity and legal status',
            'real_money' => 'Real-money policy and licence gate',
            'security' => 'Security architecture',
            'finance' => 'Financial integrity',
            'kyc' => 'KYC and compliance',
            'responsible_gaming' => 'Responsible gaming',
            'lottery' => 'Lottery systems',
            'betting' => 'Betting rules and markets',
            'deposit_withdraw' => 'Deposit and withdrawal',
            'wallet' => 'Wallet and currency',
            'agent' => 'Agent and affiliate system',
            'support' => 'Customer support',
            'privacy' => 'Privacy and data protection',
            'tech' => 'Technical architecture',
            'governance' => 'Governance and audit',
            'disclaimer' => 'Disclaimers',
            'contact' => 'Contact information',
        ];
        $rows = [];
        $translations = $this->text->all($locale);

        foreach ($sections as $key => $fallbackTitle) {
            $prefix = 'about_'.$key;
            $title = $this->text->line($prefix.'_title', $locale) ?: $fallbackTitle;
            $lead = $this->text->line($prefix.'_lead', $locale);
            $fullText = $this->text->line($prefix.'_full_text', $locale);
            $items = [];

            foreach ($translations as $translationKey => $value) {
                if (! str_starts_with((string) $translationKey, $prefix.'_')) {
                    continue;
                }
                $suffix = substr((string) $translationKey, strlen($prefix) + 1);
                if (in_array($suffix, ['title', 'lead', 'full_text', 'text'], true)) {
                    continue;
                }
                $this->flattenAboutValue($items, $suffix, $value);
            }

            $rows[] = [
                'key' => $key,
                'title' => $title,
                'lead' => $lead,
                'full_text' => $fullText,
                'items' => $items,
            ];
        }

        return $rows;
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function flattenAboutValue(array &$items, string $label, mixed $value): void
    {
        if (is_string($value) || is_numeric($value)) {
            $items[] = ['label' => str_replace('_', ' ', ucfirst($label)), 'value' => (string) $value];
            return;
        }
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $index => $child) {
            $childLabel = is_int($index) ? $label.' '.($index + 1) : $label.' — '.str_replace('_', ' ', (string) $index);
            if (is_array($child)) {
                $this->flattenAboutValue($items, $childLabel, $child);
            } elseif (is_string($child) || is_numeric($child)) {
                $items[] = ['label' => str_replace('_', ' ', ucfirst($childLabel)), 'value' => (string) $child];
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Section producers                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array{key: string, label: string, text: string, index: int}>
     */
    private function howItWorks(string $locale): array
    {
        $steps = [];
        $index = 1;
        foreach ((array) config('public_pages.how_it_works_steps', ['choose', 'buy', 'result', 'claim']) as $key) {
            $key = (string) $key;
            $label = $this->text->line('how_it_works_'.$key.'_label', $locale);
            $text = $this->text->line('how_it_works_'.$key.'_text', $locale);
            if ($label === '' || $text === '') {
                continue;
            }
            $steps[] = [
                'key' => $key,
                'label' => $label,
                'text' => $text,
                'index' => $index,
            ];
            $index++;
        }

        return $steps;
    }

    /**
     * @return array<int, array{key: string, label: string, text: string}>
     */
    private function values(string $locale): array
    {
        $values = [];
        foreach ((array) config('public_pages.core_values', []) as $key) {
            $key = (string) $key;
            $label = $this->text->line('core_values_'.$key.'_label', $locale);
            $text = $this->text->line('core_values_'.$key.'_text', $locale);
            if ($label === '' || $text === '') {
                continue;
            }
            $values[] = ['key' => $key, 'label' => $label, 'text' => $text];
        }

        return $values;
    }

    /**
     * @return array<int, array{key: string, text: string}>
     */
    private function trustItems(string $locale): array
    {
        $items = [];
        foreach ((array) config('public_pages.governance_controls', []) as $key) {
            $key = (string) $key;
            $text = $this->text->line('governance_'.$key, $locale);
            if ($text === '') {
                continue;
            }
            $items[] = ['key' => $key, 'text' => $text];
        }

        return $items;
    }

    /* ------------------------------------------------------------------ */
    /* New section producers                                              */
    /* ------------------------------------------------------------------ */

    /** @return array<int, array{label: string, value: string}> */
    private function identityRows(string $locale): array
    {
        $labels = [
            'about_identity_legal_entity',
            'about_identity_registration',
            'about_identity_license_type',
            'about_identity_authorized_countries',
            'about_identity_license_expiry',
            'about_identity_government_relation',
            'about_identity_data_feed',
        ];

        $rows = [];
        foreach ($labels as $key) {
            $label = $this->text->line($key.'_label', $locale);
            $value = $this->text->line($key.'_value', $locale);
            if ($label === '' || $value === '') {
                continue;
            }
            $rows[] = ['label' => $label, 'value' => $value];
        }

        return $rows;
    }

    /** @return array<int, array{field: string, description: string}> */
    private function realMoneyFields(string $locale): array
    {
        $fields = [
            'about_real_money_license_fields_issuer' => 'REAL_MONEY_LICENSE_ISSUER',
            'about_real_money_license_fields_scope' => 'REAL_MONEY_LICENSE_SCOPE',
            'about_real_money_license_fields_expiry' => 'REAL_MONEY_LICENSE_EXPIRY',
            'about_real_money_license_fields_countries' => 'REAL_MONEY_LICENSE_COUNTRIES',
            'about_real_money_license_fields_signature' => 'REAL_MONEY_LICENSE_SIGNATURE',
        ];

        $out = [];
        foreach ($fields as $key => $field) {
            $description = $this->text->line($key, $locale);
            if ($description === '') {
                $description = $field;
            }
            $out[] = ['field' => $field, 'description' => $description];
        }

        return $out;
    }

    /** @return array<int, array{code: string, meaning: string}> */
    private function realMoneyErrorCodes(string $locale): array
    {
        $codes = [
            'about_real_money_error_codes_issuer_missing' => 'real_money.license.issuer_missing',
            'about_real_money_error_codes_scope_missing' => 'real_money.license.scope_missing',
            'about_real_money_error_codes_expiry_missing' => 'real_money.license.expiry_missing',
            'about_real_money_error_codes_countries_missing' => 'real_money.license.countries_missing',
            'about_real_money_error_codes_signature_missing' => 'real_money.license.signature_missing',
            'about_real_money_error_codes_expired' => 'real_money.license.expired',
            'about_real_money_error_codes_country_not_authorized' => 'real_money.license.country_not_authorized',
        ];

        $out = [];
        foreach ($codes as $key => $code) {
            $meaning = $this->text->line($key, $locale);
            if ($meaning === '') {
                $meaning = $code;
            }
            $out[] = ['code' => $code, 'meaning' => $meaning];
        }

        return $out;
    }

    /** @return array<int, array{threat: string, control: string}> */
    private function securityThreatModel(string $locale): array
    {
        $threats = [
            'about_security_threat_model_balance_tampering' => 'Balance tampering',
            'about_security_threat_model_duplicate_transactions' => 'Duplicate transactions',
            'about_security_threat_model_unauthorized_withdrawals' => 'Unauthorized withdrawals',
            'about_security_threat_model_data_enumeration' => 'Data enumeration',
            'about_security_threat_model_credential_stuffing' => 'Credential stuffing',
            'about_security_threat_model_pii_leakage' => 'PII leakage',
        ];

        $controls = [
            'about_security_threat_model_balance_tampering_control' => 'bcmath exact arithmetic + double-entry ledger + row-level locking',
            'about_security_threat_model_duplicate_transactions_control' => 'Idempotency keys on all money-moving endpoints',
            'about_security_threat_model_unauthorized_withdrawals_control' => 'KYC gate + licence gate + session authorization',
            'about_security_threat_model_data_enumeration_control' => 'IDOR protection + 404 for foreign resources',
            'about_security_threat_model_credential_stuffing_control' => 'Rate limiting + CAPTCHA + TOTP 2FA',
            'about_security_threat_model_pii_leakage_control' => 'Encrypted payout details + no logging of sensitive data',
        ];

        $out = [];
        foreach ($threats as $key => $threat) {
            $threatLabel = $this->text->line($key, $locale) ?: $threat;
            $controlKey = str_replace('_threat_model_', '_threat_model_control', $key);
            $controlLabel = $this->text->line($controlKey, $locale) ?: ($controls[$key] ?? '');
            $out[] = ['threat' => $threatLabel, 'control' => $controlLabel];
        }

        return $out;
    }

    /** @return array<int, array{currency: string, description: string}> */
    private function financeCurrencies(string $locale): array
    {
        $currencies = [
            'about_finance_currencies_thb' => ['THB', 'Thai Baht — primary wallet currency'],
            'about_finance_currencies_usd' => ['USD', 'US Dollar — secondary wallet currency'],
            'about_finance_currencies_bdt' => ['BDT', 'Bangladeshi Taka — secondary wallet currency'],
        ];

        $out = [];
        foreach ($currencies as $key => $data) {
            $currency = $this->text->line($key.'_currency', $locale) ?: $data[0];
            $description = $this->text->line($key.'_description', $locale) ?: $data[1];
            $out[] = ['currency' => $currency, 'description' => $description];
        }

        return $out;
    }

    /** @return array<int, array{method: string, type: string, description: string}> */
    private function financeMethods(string $locale): array
    {
        $methods = [
            'about_finance_methods_bank_transfer' => ['Bank Transfer', 'Deposit & Withdrawal', 'Thai bank wire settlement with manual operator instructions'],
            'about_finance_methods_bkash' => ['bKash', 'Deposit & Withdrawal', 'Mobile money wallet (Bangladesh)'],
            'about_finance_methods_nagad' => ['Nagad', 'Deposit & Withdrawal', 'Mobile money wallet (Bangladesh)'],
            'about_finance_methods_crypto' => ['Crypto', 'Deposit & Withdrawal', 'Cryptocurrency wallet address'],
            'about_finance_methods_stripe' => ['Stripe', 'Deposit', 'Credit/debit card processing'],
            'about_finance_methods_promptpay' => ['PromptPay', 'Deposit', 'Thai real-time payment system'],
            'about_finance_methods_truenoney' => ['TrueMoney', 'Deposit', 'Thai e-wallet'],
        ];

        $out = [];
        foreach ($methods as $key => $data) {
            $out[] = [
                'method' => $this->text->line($key.'_method', $locale) ?: $data[0],
                'type' => $this->text->line($key.'_type', $locale) ?: $data[1],
                'description' => $this->text->line($key.'_description', $locale) ?: $data[2],
            ];
        }

        return $out;
    }

    /** @return array<int, array{step: int, title: string, description: string}> */
    private function kycSteps(string $locale): array
    {
        $steps = [
            'about_kyc_steps_1_title' => 'Submit Documents',
            'about_kyc_steps_1_desc' => 'Upload national ID, passport, or driver license through the verification page',
            'about_kyc_steps_2_title' => 'Reviewer Authorization',
            'about_kyc_steps_2_desc' => 'Operator reviewer examines documents with audit logging',
            'about_kyc_steps_3_title' => 'Decision',
            'about_kyc_steps_3_desc' => 'Approve or reject with reason recorded',
            'about_kyc_steps_4_title' => 'KYC Status Updated',
            'about_kyc_steps_4_desc' => 'Account verification status reflected in profile and withdrawal gate',
        ];

        $out = [];
        for ($i = 1; $i <= 4; $i++) {
            $out[] = [
                'step' => $i,
                'title' => $this->text->line("about_kyc_steps_{$i}_title", $locale) ?: $steps["about_kyc_steps_{$i}_title"],
                'description' => $this->text->line("about_kyc_steps_{$i}_desc", $locale) ?: $steps["about_kyc_steps_{$i}_desc"],
            ];
        }

        return $out;
    }

    /** @return array<int, array{limit: string, scope: string, enforcement: string}> */
    private function responsibleGamingLimits(string $locale): array
    {
        $limits = [
            'about_responsible_gaming_limits_daily_deposit' => ['Daily Deposit', 'Per calendar day', 'Server-side, checked before deposit creation'],
            'about_responsible_gaming_limits_single_bet' => ['Single Bet', 'Per individual bet', 'Server-side, checked before bet placement'],
            'about_responsible_gaming_limits_daily_wagering' => ['Daily Wagering', 'Per calendar day', 'Server-side, checked before bet placement'],
            'about_responsible_gaming_limits_self_exclusion' => ['Self-Exclusion', 'Account-wide', 'Blocks all money-moving actions during period'],
        ];

        $out = [];
        foreach ($limits as $key => $data) {
            $out[] = [
                'limit' => $this->text->line($key.'_limit', $locale) ?: $data[0],
                'scope' => $this->text->line($key.'_scope', $locale) ?: $data[1],
                'enforcement' => $this->text->line($key.'_enforcement', $locale) ?: $data[2],
            ];
        }

        return $out;
    }

    /** @return array<int, array{type: string, frequency: string, digits: string}> */
    private function lotteryDrawTypes(string $locale): array
    {
        $types = [
            'about_lottery_draw_types_glo_l6' => ['GLO L6', 'Twice monthly (1st and 16th)', '6 digits'],
            'about_lottery_draw_types_national' => ['National Lottery', 'Per draw schedule', 'Varies'],
            'about_lottery_draw_types_weekly' => ['Weekly Lottery', 'Weekly', 'Varies'],
            'about_lottery_draw_types_bingo' => ['Bingo Lottery', 'Multiple daily', 'Varies'],
            'about_lottery_draw_types_pcso' => ['PCSO Lottery', 'Multiple daily with time', 'Varies'],
            'about_lottery_draw_types_bangkok' => ['Bangkok Lottery', 'Per draw schedule', 'Varies'],
        ];

        $out = [];
        foreach ($types as $key => $data) {
            $out[] = [
                'type' => $this->text->line($key.'_type', $locale) ?: $data[0],
                'frequency' => $this->text->line($key.'_frequency', $locale) ?: $data[1],
                'digits' => $this->text->line($key.'_digits', $locale) ?: $data[2],
            ];
        }

        return $out;
    }

    /** @return array<int, array{market: string, description: string, multiplier: string}> */
    private function bettingMarkets(string $locale): array
    {
        $markets = [
            'about_betting_markets_2d' => ['2D', 'Two-digit match', 'Configured per draw'],
            'about_betting_markets_3d' => ['3D', 'Three-digit match', 'Configured per draw'],
            'about_betting_markets_6d' => ['6D', 'Six-digit match', 'Configured per draw'],
            'about_betting_markets_3up' => ['3Up', 'Last three digits', 'Configured per draw'],
            'about_betting_markets_2up' => ['2Up', 'Last two digits', 'Configured per draw'],
            'about_betting_markets_2down' => ['2Down', 'First two digits', 'Configured per draw'],
        ];

        $out = [];
        foreach ($markets as $key => $data) {
            $out[] = [
                'market' => $this->text->line($key.'_market', $locale) ?: $data[0],
                'description' => $this->text->line($key.'_description', $locale) ?: $data[1],
                'multiplier' => $this->text->line($key.'_multiplier', $locale) ?: $data[2],
            ];
        }

        return $out;
    }

    /** @return array<int, array{step: int, title: string, description: string}> */
    private function depositWithdrawSteps(string $locale): array
    {
        $steps = [
            'about_deposit_withdraw_steps_1_title' => 'Initiate',
            'about_deposit_withdraw_steps_1_desc' => 'Select amount, method, and provide idempotency key',
            'about_deposit_withdraw_steps_2_title' => 'Validate',
            'about_deposit_withdraw_steps_2_desc' => 'System checks balance, limits, KYC, and licence gate',
            'about_deposit_withdraw_steps_3_title' => 'Process',
            'about_deposit_withdraw_steps_3_desc' => 'Gateway confirmation (deposit) or operator approval (withdrawal)',
            'about_deposit_withdraw_steps_4_title' => 'Complete',
            'about_deposit_withdraw_steps_4_desc' => 'Wallet credit/debit with balanced ledger entries',
            'about_deposit_withdraw_steps_5_title' => 'Track',
            'about_deposit_withdraw_steps_5_desc' => 'Status tracking through dedicated status pages',
        ];

        $out = [];
        for ($i = 1; $i <= 5; $i++) {
            $out[] = [
                'step' => $i,
                'title' => $this->text->line("about_deposit_withdraw_steps_{$i}_title", $locale) ?: $steps["about_deposit_withdraw_steps_{$i}_title"],
                'description' => $this->text->line("about_deposit_withdraw_steps_{$i}_desc", $locale) ?: $steps["about_deposit_withdraw_steps_{$i}_desc"],
            ];
        }

        return $out;
    }

    /** @return array<int, array{pocket: string, description: string}> */
    private function walletPockets(string $locale): array
    {
        $pockets = [
            'about_wallet_pockets_available_balance' => ['Available Balance', 'Funds immediately available for bets and withdrawals'],
            'about_wallet_pockets_locked_balance' => ['Locked Balance', 'Funds committed to pending withdrawals or bets'],
            'about_wallet_pockets_total_deposited' => ['Total Deposited', 'Lifetime total deposits in this currency'],
            'about_wallet_pockets_total_withdrawn' => ['Total Withdrawn', 'Lifetime total withdrawals in this currency'],
            'about_wallet_pockets_total_wagered' => ['Total Wagered', 'Lifetime total amount wagered in this currency'],
            'about_wallet_pockets_total_won' => ['Total Won', 'Lifetime total prizes won in this currency'],
        ];

        $out = [];
        foreach ($pockets as $key => $data) {
            $out[] = [
                'pocket' => $this->text->line($key.'_pocket', $locale) ?: $data[0],
                'description' => $this->text->line($key.'_description', $locale) ?: $data[1],
            ];
        }

        return $out;
    }

    /** @return array<int, array{feature: string, description: string}> */
    private function agentFeatures(string $locale): array
    {
        $features = [
            'about_agent_features_dashboard' => ['Agent Dashboard', 'Commissions, settlements, statements, and referrals overview'],
            'about_agent_features_referral' => ['Referral Tracking', 'Unique codes attributed to agents at registration'],
            'about_agent_features_commission' => ['Commission Accrual', 'Per-bet commission, idempotent and auditable'],
            'about_agent_features_settlement' => ['Settlement Management', 'Operator-controlled commission payout scheduling'],
            'about_agent_features_statements' => ['Agent Statements', 'Detailed commission and settlement statements'],
        ];

        $out = [];
        foreach ($features as $key => $data) {
            $out[] = [
                'feature' => $this->text->line($key.'_feature', $locale) ?: $data[0],
                'description' => $this->text->line($key.'_description', $locale) ?: $data[1],
            ];
        }

        return $out;
    }

    /** @return array<int, array{topic: string, description: string}> */
    private function supportTopics(string $locale): array
    {
        $topics = [
            'about_support_topics_account_access' => ['Account Access', 'Login, registration, password reset, 2FA setup'],
            'about_support_topics_deposits_withdrawals' => ['Deposits & Withdrawals', 'Payment status, failed transactions, payout delays'],
            'about_support_topics_betting_results' => ['Betting & Results', 'Bet placement, settlement, prize verification'],
            'about_support_topics_kyc' => ['KYC & Verification', 'Document submission, review status, rejection reasons'],
            'about_support_topics_responsible_gaming' => ['Responsible Gaming', 'Limit setting, self-exclusion, cooling-off periods'],
            'about_support_topics_technical' => ['Technical Issues', 'Website errors, mobile app problems, API integration'],
        ];

        $out = [];
        foreach ($topics as $key => $data) {
            $out[] = [
                'topic' => $this->text->line($key.'_topic', $locale) ?: $data[0],
                'description' => $this->text->line($key.'_description', $locale) ?: $data[1],
            ];
        }

        return $out;
    }

    /** @return array<int, array{right: string, description: string}> */
    private function privacyRights(string $locale): array
    {
        $rights = [
            'about_privacy_rights_access' => ['Access', 'Request a copy of your personal data'],
            'about_privacy_rights_rectification' => ['Rectification', 'Correct inaccurate personal data'],
            'about_privacy_rights_erasure' => ['Erasure', 'Request deletion where legally permitted'],
            'about_privacy_rights_restriction' => ['Restriction', 'Limit processing of your data'],
            'about_privacy_rights_portability' => ['Portability', 'Receive your data in a portable format'],
            'about_privacy_rights_objection' => ['Objection', 'Object to processing for specific purposes'],
        ];

        $out = [];
        foreach ($rights as $key => $data) {
            $out[] = [
                'right' => $this->text->line($key.'_right', $locale) ?: $data[0],
                'description' => $this->text->line($key.'_description', $locale) ?: $data[1],
            ];
        }

        return $out;
    }

    /** @return array<int, array{layer: string, technology: string}> */
    private function techStack(string $locale): array
    {
        $stack = [
            'about_tech_stack_backend' => ['Backend', 'Laravel 11, PHP 8.4'],
            'about_tech_stack_frontend' => ['Frontend', 'Blade, Tailwind CSS, Vite, JavaScript'],
            'about_tech_stack_database' => ['Database', 'PostgreSQL / MySQL / SQLite'],
            'about_tech_stack_cache_queue' => ['Cache & Queue', 'Redis'],
            'about_tech_stack_authentication' => ['Authentication', 'Laravel Sanctum, TOTP 2FA'],
            'about_tech_stack_mobile' => ['Mobile', 'Android WebView (signed APK)'],
        ];

        $out = [];
        foreach ($stack as $key => $data) {
            $out[] = [
                'layer' => $this->text->line($key.'_layer', $locale) ?: $data[0],
                'technology' => $this->text->line($key.'_technology', $locale) ?: $data[1],
            ];
        }

        return $out;
    }

    /** @return array<int, array{control: string, description: string}> */
    private function governanceControls(string $locale): array
    {
        $controls = [
            'about_governance_controls_license_gate' => ['Real-Money Licence Gate', 'Fail-closed gate on all deposit, withdrawal, and bet routes'],
            'about_governance_controls_double_entry' => ['Double-Entry Ledger', 'Balanced debit/credit entries for every money movement'],
            'about_governance_controls_idempotency' => ['Idempotency Keys', 'Duplicate request prevention on all financial endpoints'],
            'about_governance_controls_wallet_lock' => ['Row-Level Wallet Locking', 'FOR UPDATE locking on balance changes'],
            'about_governance_controls_kyc_gate' => ['KYC Withdrawal Gate', 'Identity verification required above threshold'],
            'about_governance_controls_responsible_gaming' => ['Responsible Gaming Limits', 'Server-side deposit, bet, and wagering limits'],
            'about_governance_controls_two_factor' => ['TOTP Two-Factor Auth', 'Time-based one-time password for account security'],
            'about_governance_controls_encrypted_payout' => ['Encrypted Payout Details', 'Bank/mobile/crypto details encrypted at rest'],
        ];

        $out = [];
        foreach ($controls as $key => $data) {
            $out[] = [
                'control' => $this->text->line($key.'_control', $locale) ?: $data[0],
                'description' => $this->text->line($key.'_description', $locale) ?: $data[1],
            ];
        }

        return $out;
    }

    /** @return array<int, array{item: string, detail: string}> */
    private function disclaimerItems(string $locale): array
    {
        $items = [
            'about_disclaimer_items_independent' => ['Not a government portal', 'This platform is independent and not operated by any government agency'],
            'about_disclaimer_items_licence' => ['Licence required', 'Real-money features require configured and unexpired operator licence'],
            'about_disclaimer_items_results' => ['Results are aggregated', 'Official GLO results come from the Government Lottery Office'],
            'about_disclaimer_items_financial_risk' => ['Financial risk', 'Gambling involves risk; only wager what you can afford to lose'],
            'about_disclaimer_items_no_guarantee' => ['No guaranteed winnings', 'Lottery outcomes are random and unpredictable'],
        ];

        $out = [];
        foreach ($items as $key => $data) {
            $out[] = [
                'item' => $this->text->line($key.'_item', $locale) ?: $data[0],
                'detail' => $this->text->line($key.'_detail', $locale) ?: $data[1],
            ];
        }

        return $out;
    }

    /** @return array<int, array{channel: string, path: string, description: string}> */
    private function contactChannels(string $locale): array
    {
        $channels = [
            'about_contact_channels_support_portal' => ['Support Portal', '/support', 'Ticket creation, tracking, and replies'],
            'about_contact_channels_live_chat' => ['Live Chat', '/chat/widget', 'Real-time assistance'],
            'about_contact_channels_email' => ['Email', 'Configured in mail settings', 'Complex inquiries and documentation'],
            'about_contact_channels_kyc' => ['KYC Verification', '/account/verification', 'Document submission and status'],
            'about_contact_channels_responsible_gaming' => ['Responsible Gaming', '/settings', 'Limits and self-exclusion'],
        ];

        $out = [];
        foreach ($channels as $key => $data) {
            $out[] = [
                'channel' => $this->text->line($key.'_channel', $locale) ?: $data[0],
                'path' => $this->text->line($key.'_path', $locale) ?: $data[1],
                'description' => $this->text->line($key.'_description', $locale) ?: $data[2],
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{key: string, label: string, href: string}>
     */
    private function usefulLinks(string $locale): array
    {
        $labels = [
            'results' => $this->text->line('useful_links_results', $locale),
            'ticket_check' => $this->text->line('useful_links_ticket_check', $locale),
            'sales_points' => $this->text->line('useful_links_sales_points', $locale),
            'contact' => $this->text->line('useful_links_contact', $locale),
            'privacy' => $this->text->line('useful_links_privacy', $locale),
            'terms' => $this->text->line('useful_links_terms', $locale),
        ];
        $links = [];

        foreach ((array) config('public_pages.useful_links', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $key = (string) ($entry['key'] ?? '');
            $routeName = (string) ($entry['route'] ?? '');
            if ($key === '' || $routeName === '' || ! $this->routeExists($routeName)) {
                continue;
            }
            $links[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $key,
                'href' => route($routeName),
            ];
        }

        return $links;
    }

    private function namedRoute(string $name): string
    {
        return $this->routeExists($name) ? route($name) : route('home');
    }

    private function routeExists(string $name): bool
    {
        try {
            return Route::has($name);
        } catch (Throwable) {
            return false;
        }
    }
}
