<?php

/*
|--------------------------------------------------------------------------
| Canonical National + Bangkok Weekly game/prize/discount matrix
|--------------------------------------------------------------------------
|
| This platform's own public game catalogue for the Account Grade /
| Lotto Discount surfaces. Every value here is this platform's own
| commercial configuration, expressed in the platform currency and
| overridable per environment. NOTHING is copied from any competitor:
| the benchmark supplied the SHAPE of the schedule (which game families
| exist, D/R dual multipliers, game-total and single-digit variants,
| per-game discount percentages, an affiliate commission line), never
| a proprietary implementation.
|
| MONEY AND RATES ARE DECIMAL STRINGS. Never floats. Win multipliers
| are plain decimal strings of the payout per 1.00 stake unit ('3000'
| means 1.00 stakes 3000.00 back). Discount percentages are WHOLE-PERCENT
| decimal strings ('35.00' = 35%), the same convention config/discounts.php
| uses, converted to a fraction with bcdiv only inside the calculator.
|
| NOT_CONFIGURED
| A null 'discount' is a deliberate state: the operator has not published
| a percentage for that row. It is NEVER treated as zero and NEVER guessed;
| public projections render it as NOT_CONFIGURED and calculators refuse
| to quote a percentage-driven discount for it.
|
| ROW CONTRACT
|   key              stable game key (App\Enums\DiscountGame value)
|   label_key        translation key for the human-readable game name
|   modes            ['D' => mult, 'R' => mult] for headline games, or
|                    ['*' => mult] for game-total / single-digit variants
|   discount         whole-percent decimal string, or null = NOT_CONFIGURED
|   enabled          master switch; a disabled game never renders/calculates
|   public_visible   only true rows appear on the public Discount page
|   effective_from   inclusive first effective day (null = always)
|   effective_to     EXCLUSIVE last effective day (null = no end)
|   rule_version     bumped whenever the economic meaning of a row changes
|
| GRADE ENTITLEMENTS live in config/account_grades.php, not here: the
| matrix says what each game pays and discounts; the grade catalogue says
| which grades may apply their discount to which games.
|
*/

return [

    'rule_version' => (string) env('LOTTO_MATRIX_RULE_VERSION', '1'),

    'currency' => (string) env('LOTTO_MATRIX_CURRENCY', 'THB'),

    // Base stake unit the published multipliers are quoted against.
    'base_stake' => (string) env('LOTTO_MATRIX_BASE_STAKE', '1.00'),

    /*
    |----------------------------------------------------------------------
    | Affiliate commission (published line on the Discount page)
    |----------------------------------------------------------------------
    */
    'affiliate_commission' => [
        'national' => (string) env('LOTTO_MATRIX_AFFILIATE_NATIONAL', '2.00'),
        'bangkok_weekly' => (string) env('LOTTO_MATRIX_AFFILIATE_WEEKLY', '2.00'),
    ],

    /*
    |----------------------------------------------------------------------
    | The matrix, grouped by rule family
    |----------------------------------------------------------------------
    */
    'lotteries' => [

        'national' => [
            'label_key' => 'prize_discount.matrix_lottery_national',
            'enabled' => true,
            'games' => [

                'national_six_digit' => [
                    'label_key' => 'prize_discount.matrix_game_national_six_digit',
                    'modes' => ['D' => '3000', 'R' => '500'],
                    'discount' => (string) env('LOTTO_MATRIX_NATIONAL_SIX_DIGIT_DISCOUNT', '35.00'),
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'national_3_up' => [
                    'label_key' => 'prize_discount.matrix_game_national_3_up',
                    'modes' => ['D' => '500', 'R' => '100'],
                    'discount' => (string) env('LOTTO_MATRIX_NATIONAL_3_UP_DISCOUNT', '30.00'),
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'national_2_up' => [
                    'label_key' => 'prize_discount.matrix_game_national_2_up',
                    'modes' => ['D' => '80', 'R' => '40'],
                    'discount' => (string) env('LOTTO_MATRIX_NATIONAL_2_UP_DISCOUNT', '20.00'),
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'national_2_down' => [
                    'label_key' => 'prize_discount.matrix_game_national_2_down',
                    'modes' => ['D' => '80', 'R' => '40'],
                    'discount' => (string) env('LOTTO_MATRIX_NATIONAL_2_DOWN_DISCOUNT', '20.00'),
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'national_1_of_3_up_single_digit' => [
                    'label_key' => 'prize_discount.matrix_game_national_1_of_3_up_single_digit',
                    'modes' => ['*' => '3'],
                    // The public schedule publishes no percentage for this
                    // row: NOT_CONFIGURED, never zero, never guessed.
                    'discount' => null,
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'national_1_of_2_up_single_digit' => [
                    'label_key' => 'prize_discount.matrix_game_national_1_of_2_up_single_digit',
                    'modes' => ['*' => '3'],
                    'discount' => null,
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'national_1_of_2_down_single_digit' => [
                    'label_key' => 'prize_discount.matrix_game_national_1_of_2_down_single_digit',
                    'modes' => ['*' => '4'],
                    'discount' => null,
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'national_3_up_game_total' => [
                    'label_key' => 'prize_discount.matrix_game_national_3_up_game_total',
                    'modes' => ['*' => '6'],
                    'discount' => null,
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'national_2_up_game_total' => [
                    'label_key' => 'prize_discount.matrix_game_national_2_up_game_total',
                    'modes' => ['*' => '6'],
                    'discount' => null,
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'national_2_down_game_total' => [
                    'label_key' => 'prize_discount.matrix_game_national_2_down_game_total',
                    'modes' => ['*' => '6'],
                    'discount' => null,
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],
            ],
        ],

        'bangkok_weekly' => [
            'label_key' => 'prize_discount.matrix_lottery_bangkok_weekly',
            'enabled' => true,
            'games' => [

                'weekly_6_ball' => [
                    'label_key' => 'prize_discount.matrix_game_weekly_6_ball',
                    'modes' => ['D' => '1000', 'R' => '500'],
                    'discount' => (string) env('LOTTO_MATRIX_WEEKLY_6_BALL_DISCOUNT', '35.00'),
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'weekly_3_ball' => [
                    'label_key' => 'prize_discount.matrix_game_weekly_3_ball',
                    'modes' => ['D' => '200', 'R' => '60'],
                    'discount' => (string) env('LOTTO_MATRIX_WEEKLY_3_BALL_DISCOUNT', '20.00'),
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'weekly_2_ball' => [
                    'label_key' => 'prize_discount.matrix_game_weekly_2_ball',
                    'modes' => ['D' => '50', 'R' => '25'],
                    'discount' => (string) env('LOTTO_MATRIX_WEEKLY_2_BALL_DISCOUNT', '15.00'),
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'weekly_1_of_3_ball_single_digit' => [
                    'label_key' => 'prize_discount.matrix_game_weekly_1_of_3_ball_single_digit',
                    'modes' => ['*' => '2'],
                    'discount' => null,
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'weekly_1_of_2_ball_single_digit' => [
                    'label_key' => 'prize_discount.matrix_game_weekly_1_of_2_ball_single_digit',
                    'modes' => ['*' => '2'],
                    'discount' => null,
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'weekly_6_ball_game_total' => [
                    'label_key' => 'prize_discount.matrix_game_weekly_6_ball_game_total',
                    'modes' => ['*' => '7'],
                    'discount' => null,
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'weekly_3_ball_game_total' => [
                    'label_key' => 'prize_discount.matrix_game_weekly_3_ball_game_total',
                    'modes' => ['*' => '6'],
                    'discount' => null,
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],

                'weekly_2_ball_game_total' => [
                    'label_key' => 'prize_discount.matrix_game_weekly_2_ball_game_total',
                    'modes' => ['*' => '7'],
                    'discount' => null,
                    'enabled' => true,
                    'public_visible' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'rule_version' => '1',
                ],
            ],
        ],
    ],
];
