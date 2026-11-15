<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Lottery Games — RULES PROFILES (non-production only)
|--------------------------------------------------------------------------
|
| WHAT A PROFILE IS
| A profile is a COMPLETE, self-consistent reference rule set for one or more
| games, used to exercise the whole lane end to end — draw generation, bet
| placement, result ingestion, publication, settlement, payout, reports — on a
| machine where no official rules document has been supplied yet.
|
| WHAT A PROFILE IS NOT
| It is NOT the operator's rule set, and it is NOT evidence of anything. The
| numbers below are reference values chosen so that the arithmetic is
| self-consistent and every theoretical RTP lands under the ceiling; they are
| NOT the published multipliers of Speed 88 or Lao Hanoi 4D. Shipping them as
| production rules would be inventing official rules, which batch-2 rule §0.3
| forbids.
|
| THE THREE GUARDS THAT KEEP THIS FILE HONEST
|
|   1  RulesProfileResolver REFUSES every profile when the application
|      environment is 'production'. Not "warns", not "prefers the override":
|      the layer is dropped and a critical line is logged. A production
|      deployment therefore cannot sell a profile rule set by accident.
|
|   2  A profile can never claim verification. Whatever 'rules_status' a
|      profile writes is overwritten with 'unverified' when it is applied, so
|      the only way a profile-driven game becomes sellable is an operator
|      stamping the checksum through the audited Filament action — which is a
|      deliberate human act against a visible rule set.
|
|   3  The operator's own values (storage/app/lottery_games/rules.override.json,
|      written by `lottery-games:rules:import`) are applied AFTER a profile and
|      win over it, so the day the real document arrives no code changes.
|
| HOW TO TURN ONE ON
|   LOTTERY_GAMES_PROFILE=uat php artisan lottery-games:validate-rules
| With no profile named — which is the default, and the only production shape —
| config/lottery_games.php is used exactly as written, REQUIRED_INPUT included.
|
*/

return [

    'uat' => [

        'label' => 'UAT reference ruleset (not official — non-production only)',

        'notes' => [
            'Reference arithmetic only: every market is priced so the theoretical RTP is under the ceiling.',
            'Multipliers, cutoffs, stakes, caps and the result endpoint are NOT the published rules of these games.',
            'Applied with rules_status forced to "unverified": an operator must still verify through the audited action.',
            'Refused entirely when APP_ENV=production (see RulesProfileResolver).',
        ],

        'games' => [

            'speed_88' => [
                'code' => 'speed_88',
                'name' => 'Speed 88',
                'digits' => 4,
                'timezone' => 'Asia/Bangkok',

                // One daily draw. The cutoff is well inside the cadence, so betting
                // is open for almost the whole day — deliberately unremarkable.
                'draw_cron' => ['15 21 * * *'],
                'cutoff_seconds' => 120,

                'bet_types' => [
                    // p = 1/10000  ->  0.0001 * 7000 = 0.7000
                    '4d_direct' => [
                        'selection_digits' => 4,
                        'match_mode' => 'exact',
                        'payout_multiplier' => '7000',
                    ],
                    // p = 24/10000 ->  0.0024 * 240  = 0.5760
                    '4d_any_order' => [
                        'selection_digits' => 4,
                        'match_mode' => 'any_order',
                        'payout_multiplier' => '240',
                    ],
                    // p = 1/1000   ->  0.0010 * 900  = 0.9000
                    '3d_direct' => [
                        'selection_digits' => 3,
                        'match_mode' => 'exact',
                        'payout_multiplier' => '900',
                    ],
                    // p = 1/100    ->  0.0100 * 90   = 0.9000
                    '2d_direct' => [
                        'selection_digits' => 2,
                        'match_mode' => 'suffix',
                        'payout_multiplier' => '90',
                    ],
                ],

                'min_stake' => '10.00',
                'max_stake' => '1000.00',
                'max_liability_per_draw' => '500000.00',
                'max_liability_per_number' => '50000.00',

                'rules_status' => 'unverified',

                'settlement_market_key' => '3d_direct',

                // A 4-digit result cannot become the boundary's six-digit first
                // prize without a stated projection: left-pad it, and take the
                // last two digits as the bottom two.
                'publication_bridge' => [
                    'first_prize' => 'digits_left_padded',
                    'bottom_two' => 'last:2',
                ],

                'result_source' => [
                    'driver' => 'official',
                    'base_url' => 'https://uat-results.invalid/api',
                    'latest_path' => '/latest',
                    'by_draw_no_path' => '/draw/{draw_no}',
                    'draw_no_field' => 'draw_no',
                    'digits_field' => 'digits',
                    'drawn_at_field' => 'drawn_at',
                    'schema_version' => 'v1',
                ],
            ],

            'lao_hanoi_4d' => [
                'code' => 'lao_hanoi_4d',
                'name' => 'Lao Hanoi 4D',
                'digits' => 4,
                'timezone' => 'Asia/Bangkok',

                'draw_cron' => ['0 16 * * *'],
                'cutoff_seconds' => 300,

                'bet_types' => [
                    // p = 1/10000  ->  0.0001 * 6500 = 0.6500
                    '4d_direct' => [
                        'selection_digits' => 4,
                        'match_mode' => 'exact',
                        'payout_multiplier' => '6500',
                    ],
                    // p = 24/10000 ->  0.0024 * 220  = 0.5280
                    '4d_any_order' => [
                        'selection_digits' => 4,
                        'match_mode' => 'any_order',
                        'payout_multiplier' => '220',
                    ],
                    // p = 1/1000   ->  0.0010 * 850  = 0.8500
                    '3d_direct' => [
                        'selection_digits' => 3,
                        'match_mode' => 'exact',
                        'payout_multiplier' => '850',
                    ],
                    // p = 1/100    ->  0.0100 * 85   = 0.8500
                    '2d_direct' => [
                        'selection_digits' => 2,
                        'match_mode' => 'suffix',
                        'payout_multiplier' => '85',
                    ],
                ],

                'min_stake' => '10.00',
                'max_stake' => '1000.00',
                'max_liability_per_draw' => '500000.00',
                'max_liability_per_number' => '50000.00',

                'rules_status' => 'unverified',

                'settlement_market_key' => '3d_direct',

                'publication_bridge' => [
                    'first_prize' => 'digits_left_padded',
                    'bottom_two' => 'last:2',
                ],

                'result_source' => [
                    'driver' => 'official',
                    'base_url' => 'https://uat-results.invalid/api',
                    'latest_path' => '/latest',
                    'by_draw_no_path' => '/draw/{draw_no}',
                    'draw_no_field' => 'draw_no',
                    'digits_field' => 'digits',
                    'drawn_at_field' => 'drawn_at',
                    'schema_version' => 'v1',
                ],
            ],
        ],
    ],
];
