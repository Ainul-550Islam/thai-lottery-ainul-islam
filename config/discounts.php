<?php

/*
|--------------------------------------------------------------------------
| Canonical discount & payout-presentation policy (PROMPT 4)
|--------------------------------------------------------------------------
|
| Configuration-only discount catalogue for the public Lotto Discount page and
| for App\Services\Pricing\LottoDiscountService. Every value here is this
| platform's own commercial setting. NOTHING in this file is copied from any
| other operator: the reference site used for the feature brief supplied the
| SHAPE of the page (game-specific discounts, direct/reverse payout, affiliate
| commission), never a number.
|
| MONEY IS A DECIMAL STRING. Never a float. Percentages are decimal strings in
| whole-percent units ('2.50' = 2.50%), and every calculation runs through
| bcmath in LottoDiscountService, never through PHP arithmetic on floats.
|
| RULE FIELDS
|   rule_id           stable key, stamped onto historical pricing
|   product           product family the rule belongs to
|   market            specific market key, or '*' for every market of product
|   enabled           master switch; a disabled rule never resolves or renders
|   public_visible    only true rows render on GET /discounts
|   discount_type     'percentage' | 'fixed' | 'none'
|   discount_value    decimal string; percent units or baht, per discount_type
|   minimum_amount    rule does not apply below this base amount (nullable)
|   maximum_discount  cap on the money taken off (nullable)
|   effective_from    inclusive date/instant (nullable = always started)
|   effective_to      EXCLUSIVE instant (nullable = no end)
|   stackable         false = this rule wins alone and blocks the rest
|   priority          lower number = evaluated first
|   eligible_grades   [] = every grade; otherwise account grade keys
|   excluded_products products this rule must never touch
|   rule_version      bumped whenever the economic meaning changes
|
| GLO PRICE PROTECTION
| 'immutable_products' lists products whose official price may NEVER be moved
| by this generic engine. GLO L6 is 80.00 THB and GLO N3 is 20.00 THB in
| config/glo.php, and those are official state-lottery prices, not a
| commercial margin this platform is free to discount. The service treats an
| attempt to discount them as a hard refusal (GLO_PRICE_IMMUTABLE), not a
| silent no-op, so a misconfigured rule is visible instead of invisible.
|
*/

return [

    'currency' => (string) env('DISCOUNTS_CURRENCY', 'THB'),

    // Bumped whenever any rule's economic meaning changes. Also the cache key
    // component for the public catalogue.
    'catalogue_version' => (string) env('DISCOUNTS_CATALOGUE_VERSION', '1'),

    // Public catalogue cache. Only the public projection is ever cached; no
    // per-user price is cached anywhere.
    'cache' => [
        'enabled' => (bool) env('DISCOUNTS_CACHE_ENABLED', true),
        'ttl_seconds' => (int) env('DISCOUNTS_CACHE_TTL', 300),
        'key_prefix' => 'discounts.public',
    ],

    /*
    |--------------------------------------------------------------------------
    | Products whose price this engine must never change
    |--------------------------------------------------------------------------
    |
    | Official GLO ticket prices live in config('glo.products.*.ticket_price')
    | and are reproduced here ONLY as an assertion target, never as a source of
    | truth. LottoDiscountService cross-checks the two and refuses to price a
    | GLO product at all.
    |
    */
    'immutable_products' => [
        'glo_l6' => [
            'reason' => 'Official GLO six-digit ticket price is set by the state lottery, not by this operator.',
            'price_source' => 'glo.l6.ticket_price',
            'expected_price' => '80.00',
        ],
        'glo_n3' => [
            'reason' => 'Official GLO three-digit ticket price is set by the state lottery, not by this operator.',
            'price_source' => 'glo.n3.ticket_price',
            'expected_price' => '20.00',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stacking precedence
    |--------------------------------------------------------------------------
    |
    | Evaluated strictly in this order. A layer may appear at most once, so the
    | same discount can never be applied twice. The first non-stackable rule
    | that applies ends the resolution and no later layer is considered.
    |
    */
    'precedence' => [
        'base',
        'market_rule',
        'account_grade',
        'campaign',
        'affiliate',
        'final',
    ],

    'limits' => [
        // Percentage bounds, inclusive. A rule outside these is refused.
        'min_percentage' => '0',
        'max_percentage' => '100',

        // Total discount from all stacked layers may never exceed this share
        // of the base price, and a final price may never go below zero.
        'max_total_percentage' => (string) env('DISCOUNTS_MAX_TOTAL_PERCENTAGE', '60'),
        'min_final_price' => '0.00',

        // Money scale used for every rounding step.
        'scale' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Product catalogue
    |--------------------------------------------------------------------------
    |
    | Display metadata for the public page. A product absent from here is not
    | rendered even if a rule references it.
    |
    */
    'products' => [
        'operator_3d' => [
            'enabled' => true,
            'public_visible' => true,
            'label_key' => 'prize_discount.product_operator_3d',
            'markets' => ['3d_direct', '3d_tod'],
            'supports_payout_display' => true,
        ],
        'operator_2d' => [
            'enabled' => true,
            'public_visible' => true,
            'label_key' => 'prize_discount.product_operator_2d',
            'markets' => ['2d_top', '2d_bottom'],
            'supports_payout_display' => true,
        ],
        'operator_run' => [
            'enabled' => true,
            'public_visible' => true,
            'label_key' => 'prize_discount.product_operator_run',
            'markets' => ['run_top', 'run_bottom'],
            'supports_payout_display' => true,
        ],
        'glo_l6' => [
            'enabled' => true,
            'public_visible' => true,
            'label_key' => 'prize_discount.product_glo_l6',
            'markets' => [],
            'supports_payout_display' => false,
        ],
        'glo_n3' => [
            'enabled' => true,
            'public_visible' => true,
            'label_key' => 'prize_discount.product_glo_n3',
            'markets' => [],
            'supports_payout_display' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Discount rules
    |--------------------------------------------------------------------------
    |
    | These are this platform's own launch settings, deliberately conservative.
    | They are not derived from, and do not match, any other operator's table.
    |
    */
    'rules' => [

        'operator_3d_launch' => [
            'rule_id' => 'operator_3d_launch',
            'product' => 'operator_3d',
            'market' => '*',
            'enabled' => true,
            'public_visible' => true,
            'discount_type' => 'percentage',
            'discount_value' => '2.00',
            'minimum_amount' => '100.00',
            'maximum_discount' => '40.00',
            'effective_from' => '2026-09-01',
            'effective_to' => null,
            'stackable' => true,
            'priority' => 20,
            'eligible_grades' => [],
            'excluded_products' => ['glo_l6', 'glo_n3'],
            'rule_version' => '1',
            'layer' => 'market_rule',
            'description_key' => 'prize_discount.rule_operator_3d_launch',
        ],

        'operator_2d_launch' => [
            'rule_id' => 'operator_2d_launch',
            'product' => 'operator_2d',
            'market' => '*',
            'enabled' => true,
            'public_visible' => true,
            'discount_type' => 'percentage',
            'discount_value' => '1.50',
            'minimum_amount' => '100.00',
            'maximum_discount' => '30.00',
            'effective_from' => '2026-09-01',
            'effective_to' => null,
            'stackable' => true,
            'priority' => 21,
            'eligible_grades' => [],
            'excluded_products' => ['glo_l6', 'glo_n3'],
            'rule_version' => '1',
            'layer' => 'market_rule',
            'description_key' => 'prize_discount.rule_operator_2d_launch',
        ],

        // A single-market rule: proves market granularity, not just product.
        'operator_run_bottom_intro' => [
            'rule_id' => 'operator_run_bottom_intro',
            'product' => 'operator_run',
            'market' => 'run_bottom',
            'enabled' => true,
            'public_visible' => true,
            'discount_type' => 'fixed',
            'discount_value' => '1.00',
            'minimum_amount' => '50.00',
            'maximum_discount' => null,
            'effective_from' => '2026-09-01',
            'effective_to' => null,
            'stackable' => true,
            'priority' => 22,
            'eligible_grades' => [],
            'excluded_products' => ['glo_l6', 'glo_n3'],
            'rule_version' => '1',
            'layer' => 'market_rule',
            'description_key' => 'prize_discount.rule_operator_run_bottom_intro',
        ],

        // Non-stackable: when it applies it is the only discount granted.
        'operator_3d_exclusive_event' => [
            'rule_id' => 'operator_3d_exclusive_event',
            'product' => 'operator_3d',
            'market' => '*',
            'enabled' => false,
            'public_visible' => false,
            'discount_type' => 'percentage',
            'discount_value' => '5.00',
            'minimum_amount' => '500.00',
            'maximum_discount' => '100.00',
            'effective_from' => '2026-09-01',
            'effective_to' => null,
            'stackable' => false,
            'priority' => 10,
            'eligible_grades' => [],
            'excluded_products' => ['glo_l6', 'glo_n3'],
            'rule_version' => '1',
            'layer' => 'campaign',
            'description_key' => 'prize_discount.rule_operator_3d_exclusive_event',
        ],

        // Historical row kept so the public page can prove expiry filtering.
        'operator_2d_opening_week' => [
            'rule_id' => 'operator_2d_opening_week',
            'product' => 'operator_2d',
            'market' => '*',
            'enabled' => true,
            'public_visible' => true,
            'discount_type' => 'percentage',
            'discount_value' => '3.00',
            'minimum_amount' => null,
            'maximum_discount' => '25.00',
            'effective_from' => '2026-08-01',
            'effective_to' => '2026-08-08',
            'stackable' => true,
            'priority' => 15,
            'eligible_grades' => [],
            'excluded_products' => ['glo_l6', 'glo_n3'],
            'rule_version' => '1',
            'layer' => 'campaign',
            'description_key' => 'prize_discount.rule_operator_2d_opening_week',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Account-grade layer
    |--------------------------------------------------------------------------
    |
    | The grade discount itself is owned by App\Services\Account\
    | AccountDiscountService and config/account_grades.php. This engine does
    | NOT restate those rates; it only declares whether the grade layer
    | participates, and which products it may never touch.
    |
    */
    'account_grade_layer' => [
        'enabled' => true,
        'public_visible' => true,
        'excluded_products' => ['glo_l6', 'glo_n3'],
        'source' => 'App\\Services\\Account\\AccountDiscountService',
        'description_key' => 'prize_discount.layer_account_grade',
    ],

    /*
    |--------------------------------------------------------------------------
    | Affiliate commission presentation
    |--------------------------------------------------------------------------
    |
    | PUBLIC-SAFE SUMMARY ONLY. Real commission accrual is owned by
    | App\Services\Agent\CommissionCalculationService and config/agent.php.
    | Nothing here is a per-agent rate, an individual's earnings, or an
    | internal margin: these are the published headline bands a prospective
    | affiliate is allowed to see, and 'publish' gates even that.
    |
    */
    'affiliate_display' => [
        'publish' => (bool) env('DISCOUNTS_PUBLISH_AFFILIATE', true),
        'currency' => (string) env('DISCOUNTS_CURRENCY', 'THB'),
        'note_key' => 'prize_discount.affiliate_note',
        'bands' => [
            'standard' => [
                'enabled' => true,
                'public_visible' => true,
                'label_key' => 'prize_discount.affiliate_band_standard',
                'rate_percentage' => '1.00',
                'eligibility_key' => 'prize_discount.affiliate_eligibility_standard',
            ],
            'partner' => [
                'enabled' => true,
                'public_visible' => true,
                'label_key' => 'prize_discount.affiliate_band_partner',
                'rate_percentage' => '1.50',
                'eligibility_key' => 'prize_discount.affiliate_eligibility_partner',
            ],
            'internal_pilot' => [
                // Never rendered: proves public_visible actually gates output.
                'enabled' => true,
                'public_visible' => false,
                'label_key' => 'prize_discount.affiliate_band_internal',
                'rate_percentage' => '2.25',
                'eligibility_key' => 'prize_discount.affiliate_eligibility_internal',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Direct / reverse payout presentation
    |--------------------------------------------------------------------------
    |
    | "Direct" is the exact-order market and "reverse" is the permutation
    | market. Both already exist in App\Enums\BetMarket and are priced by
    | config('lottery.markets') through App\Services\Betting\MarketRuleResolver.
    | This section therefore carries NO multipliers - it only says which
    | market key plays which role, so the page and the purchase engine can
    | never show two different numbers.
    |
    */
    'payout_display' => [
        'enabled' => true,
        'public_visible' => true,
        'source' => 'App\\Services\\Betting\\MarketRuleResolver',
        'note_key' => 'prize_discount.payout_note',
        'pairs' => [
            'operator_3d' => [
                'direct_market' => '3d_direct',
                'reverse_market' => '3d_tod',
            ],
            'operator_2d' => [
                'direct_market' => '2d_top',
                'reverse_market' => null,
            ],
            'operator_run' => [
                'direct_market' => 'run_top',
                'reverse_market' => null,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public page presentation
    |--------------------------------------------------------------------------
    */
    'page' => [
        // Hard ceiling on rendered rows: the catalogue is config-bounded, but
        // the view must never be able to run unbounded.
        'max_rows' => 200,
        'max_products_per_page' => 25,
        'show_effective_period' => true,
    ],
];
