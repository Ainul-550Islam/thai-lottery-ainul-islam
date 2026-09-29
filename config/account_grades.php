<?php

/*
|--------------------------------------------------------------------------
| Account grade / loyalty tiers — canonical public catalogue
|--------------------------------------------------------------------------
|
| Own application tiers. Ordered lowest → highest by SL. Spend thresholds
| are decimal strings of qualifying spend within the rolling
| grade_period_days window, in the platform currency (never a foreign
| currency literal).
|
| THE FIVE PUBLIC PROGRAMME TIERS are the canonical benchmark ladder
| (SL 1..5): Gold Plus → Diamond Plus. The base row below them is the
| platform's pre-existing legitimate base/no-grade state (historically
| "bronze"): a user whose 30-day spend is below the first threshold is
| in that state. It is NOT a programme tier, is never advertised on the
| public Grade page, and no filler tier (Bronze/Silver/…) was invented
| around it.
|
| ROW CONTRACT (every tier)
|   key             stable machine key (App\Enums\AccountGradeLevel value)
|   name            display name
|   sl              public display order; 0 = base state, not a tier
|   min_spend       inclusive qualifying-spend threshold (decimal string)
|   discount_rate   grade discount as a FRACTION ('0.0200' = 2.00%)
|   eligible_games  benchmark game keys (App\Enums\DiscountGame values)
|                   the grade discount may be applied to; empty for base
|   icon            stable icon key resolved by the front-end theme
|   enabled         master switch
|   public_visible  only true rows render on the public Grade page
|   effective_from  inclusive first effective day (null = always)
|   effective_to    EXCLUSIVE last effective day (null = no end)
|   discount_scope  live pricing scope (unchanged engine contract)
|
| THRESHOLD RULES
|   - thresholds are INCLUSIVE: spend == min_spend qualifies
|   - the HIGHEST qualifying tier wins
|   - public tiers must have strictly increasing thresholds (validated by
|     GradeTierCatalog), so no spend value can satisfy two tiers
|     ambiguously
|
| GRADE DISCOUNT vs GAME DISCOUNT (the explicit policy)
|   The grade discount is an ADDITIONAL layer on top of the published
|   per-game discount — the stacking model the pricing engine has always
|   used (config/discounts.php precedence: … market_rule → account_grade
|   …), with each layer granted at most once and the total capped by
|   discounts.limits.max_total_percentage. GradeDiscountApplicationPolicy
|   encodes and tests that contract; nothing multiplies the two rates.
|
| GRADE DISCOUNT vs GAME ELIGIBILITY
|   The grade percentage applies ONLY to the games in the tier's
|   eligible_games list. For any other game the grade layer contributes
|   exactly 0.00 and an explicit NOT_ELIGIBLE state — never a silent
|   partial application.
|
| GLO PROTECTION
|   discounts apply only to products NOT in glo_excluded_products.
|   GLO L6/N3 official ticket prices are NEVER changed by grade discounts.
|
*/

return [

    'grade_period_days' => (int) env('ACCOUNT_GRADE_PERIOD_DAYS', 30),

    'rule_version' => (string) env('ACCOUNT_GRADE_RULE_VERSION', '2'),

    'currency' => (string) env('FEES_CURRENCY', 'THB'),

    /*
    |----------------------------------------------------------------------
    | Tiers (base state first, then the five public programme tiers)
    |----------------------------------------------------------------------
    */
    'tiers' => [

        [
            'key' => 'bronze',
            'name' => 'Base',
            'sl' => 0,
            'min_spend' => '0.00',
            'discount_rate' => '0.0000',
            'eligible_games' => [],
            'icon' => 'grade-base',
            'enabled' => true,
            'public_visible' => false,
            'effective_from' => null,
            'effective_to' => null,
            'discount_scope' => 'operator_markets',
        ],

        [
            'key' => 'gold_plus',
            'name' => 'Gold Plus',
            'sl' => 1,
            'min_spend' => (string) env('ACCOUNT_GRADE_GOLD_PLUS_MIN_SPEND', '200.00'),
            'discount_rate' => (string) env('ACCOUNT_GRADE_GOLD_PLUS_RATE', '0.0200'),
            'eligible_games' => [
                'national_six_digit',
                'national_3_up',
                'national_2_up',
                'weekly_6_ball',
            ],
            'icon' => 'grade-gold-plus',
            'enabled' => true,
            'public_visible' => true,
            'effective_from' => null,
            'effective_to' => null,
            'discount_scope' => 'operator_markets',
        ],

        [
            'key' => 'platinum',
            'name' => 'Platinum',
            'sl' => 2,
            'min_spend' => (string) env('ACCOUNT_GRADE_PLATINUM_MIN_SPEND', '300.00'),
            'discount_rate' => (string) env('ACCOUNT_GRADE_PLATINUM_RATE', '0.0300'),
            'eligible_games' => [
                'national_six_digit',
                'national_3_up',
                'national_2_up',
                'national_2_down',
                'weekly_6_ball',
                'weekly_3_ball',
            ],
            'icon' => 'grade-platinum',
            'enabled' => true,
            'public_visible' => true,
            'effective_from' => null,
            'effective_to' => null,
            'discount_scope' => 'operator_markets',
        ],

        [
            'key' => 'platinum_plus',
            'name' => 'Platinum Plus',
            'sl' => 3,
            'min_spend' => (string) env('ACCOUNT_GRADE_PLATINUM_PLUS_MIN_SPEND', '400.00'),
            'discount_rate' => (string) env('ACCOUNT_GRADE_PLATINUM_PLUS_RATE', '0.0400'),
            'eligible_games' => [
                'national_six_digit',
                'national_3_up',
                'national_2_up',
                'national_2_down',
                'national_1_of_2_up_single_digit',
                'national_2_up_game_total',
                'weekly_6_ball',
                'weekly_3_ball',
                'weekly_2_ball',
                'weekly_2_ball_game_total',
            ],
            'icon' => 'grade-platinum-plus',
            'enabled' => true,
            'public_visible' => true,
            'effective_from' => null,
            'effective_to' => null,
            'discount_scope' => 'operator_markets',
        ],

        [
            'key' => 'diamond',
            'name' => 'Diamond',
            'sl' => 4,
            'min_spend' => (string) env('ACCOUNT_GRADE_DIAMOND_MIN_SPEND', '500.00'),
            'discount_rate' => (string) env('ACCOUNT_GRADE_DIAMOND_RATE', '0.0500'),
            'eligible_games' => [
                'national_six_digit',
                'national_3_up',
                'national_2_up',
                'national_2_down',
                'national_1_of_2_up_single_digit',
                'national_1_of_2_down_single_digit',
                'national_2_up_game_total',
                'national_2_down_game_total',
                'weekly_6_ball',
                'weekly_3_ball',
                'weekly_2_ball',
                'weekly_1_of_2_ball_single_digit',
                'weekly_2_ball_game_total',
            ],
            'icon' => 'grade-diamond',
            'enabled' => true,
            'public_visible' => true,
            'effective_from' => null,
            'effective_to' => null,
            'discount_scope' => 'operator_markets',
        ],

        [
            'key' => 'diamond_plus',
            'name' => 'Diamond Plus',
            'sl' => 5,
            'min_spend' => (string) env('ACCOUNT_GRADE_DIAMOND_PLUS_MIN_SPEND', '600.00'),
            'discount_rate' => (string) env('ACCOUNT_GRADE_DIAMOND_PLUS_RATE', '0.0600'),
            'eligible_games' => [
                'national_six_digit',
                'national_3_up',
                'national_2_up',
                'national_2_down',
                'national_1_of_3_up_single_digit',
                'national_1_of_2_up_single_digit',
                'national_1_of_2_down_single_digit',
                'national_3_up_game_total',
                'national_2_up_game_total',
                'national_2_down_game_total',
                'weekly_6_ball',
                'weekly_3_ball',
                'weekly_2_ball',
                'weekly_1_of_3_ball_single_digit',
                'weekly_1_of_2_ball_single_digit',
                'weekly_6_ball_game_total',
                'weekly_3_ball_game_total',
                'weekly_2_ball_game_total',
            ],
            'icon' => 'grade-diamond-plus',
            'enabled' => true,
            'public_visible' => true,
            'effective_from' => null,
            'effective_to' => null,
            'discount_scope' => 'operator_markets',
        ],
    ],

    // Product codes whose prices must ignore grade discounts entirely.
    'glo_excluded_products' => ['glo_l6', 'glo_n3', 'l6', 'n3'],

    // Discount stacking order (server pricing path only). The grade layer
    // participates as ONE additional layer — see the policy note above.
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
