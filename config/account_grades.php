<?php

/*
|--------------------------------------------------------------------------
| Account grade / loyalty tiers
|--------------------------------------------------------------------------
|
| Own application tiers — NOT copied from any competitor naming or
| thresholds. Ordered lowest → highest. Spend thresholds are decimal
| strings of qualifying spend within the rolling grade_period_days window.
|
| discounts apply only to products NOT in glo_excluded_products.
| GLO L6/N3 official ticket prices are NEVER changed by grade discounts.
|
*/

return [

    'grade_period_days' => (int) env('ACCOUNT_GRADE_PERIOD_DAYS', 30),

    'rule_version' => (string) env('ACCOUNT_GRADE_RULE_VERSION', '1'),

    'currency' => (string) env('FEES_CURRENCY', 'THB'),

    // Highest eligible tier wins when spend meets multiple thresholds.
    'tiers' => [
        [
            'key' => 'bronze',
            'name' => 'Bronze',
            'min_spend' => '0.00',
            'discount_rate' => '0.0000',
            'discount_scope' => 'operator_markets',
            'enabled' => true,
        ],
        [
            'key' => 'silver',
            'name' => 'Silver',
            'min_spend' => '5000.00',
            'discount_rate' => '0.0050',
            'discount_scope' => 'operator_markets',
            'enabled' => true,
        ],
        [
            'key' => 'gold',
            'name' => 'Gold',
            'min_spend' => '25000.00',
            'discount_rate' => '0.0100',
            'discount_scope' => 'operator_markets',
            'enabled' => true,
        ],
        [
            'key' => 'platinum',
            'name' => 'Platinum',
            'min_spend' => '100000.00',
            'discount_rate' => '0.0150',
            'discount_scope' => 'operator_markets',
            'enabled' => true,
        ],
    ],

    // Product codes whose prices must ignore grade discounts entirely.
    'glo_excluded_products' => ['glo_l6', 'glo_n3', 'l6', 'n3'],

    // Discount stacking order (server pricing path only).
    'stacking_precedence' => [
        'base_price',
        'product_rule',
        'campaign',
        'grade_discount',
        'final_price',
    ],

    // Absolute ceiling so a mis-configured rate can never wipe the price.
    'max_discount_rate' => '0.2500',

    // Qualifying spend sources (completed money-in on wagers only).
    'qualifying_transaction_types' => ['bet_placement'],
    'qualifying_transaction_statuses' => ['completed'],
    'exclude_reversed' => true,
    'exclude_refunded_bets' => true,
    'exclude_cancelled_bets' => true,

];
