<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Lottery Games — Operator Registry for the Digit Game Framework
|--------------------------------------------------------------------------
|
| WHAT THIS FILE IS
| The ONLY source of official rules for the batch-2 digit games
| (speed_88, lao_hanoi_4d). Nothing in app/ may hardcode a digit count, a
| bet type, a match mode, a payout multiplier, a draw time, a cutoff or a
| liability cap: every one of those values is read from here.
|
| WHAT IT IS NOT
| It is NOT documentation of the real games. The values below are
| placeholders. Every value an operator must obtain from the official rules
| document, the regulator, or the licensed result provider is the literal
| string REQUIRED_INPUT. The framework is built to FAIL CLOSED on those:
|
|   - GameRulesValidator reports every remaining REQUIRED_INPUT
|   - lottery-games:validate-rules exits 1 while any remain
|   - GameAvailabilityService reports NOT_CONFIGURED and the game is not
|     sellable, no matter what else is configured
|   - the Filament "Verify rules" action refuses to stamp a checksum
|
| WHY PLACEHOLDERS INSTEAD OF GUESSES
| A guessed multiplier is a silent liability and a guessed draw time is a
| silent missed draw. A REQUIRED_INPUT string cannot be mistaken for a
| decision, cannot be parsed as a number, and cannot reach a wallet.
|
| Images and mockups are NOT a rules source. Their sample numbers are
| internally inconsistent, so they are never read by any code path here.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Framework-wide switches
    |--------------------------------------------------------------------------
    |
    | master_enabled     the deployment-level kill switch for the whole batch-2
    |                    lane. false here and in production it stays closed
    |                    even if every individual game is enabled.
    | rtp_ceiling        maximum acceptable theoretical return-to-player for a
    |                    single bet type. RtpAnalyzer computes the real RTP from
    |                    the multiplier table and the selection combinatorics;
    |                    ValidateGameRulesCommand fails (exit 1) when any bet
    |                    type exceeds this ceiling. The ceiling is an operator
    |                    risk decision, so it is an explicit number, not a
    |                    placeholder.
    | allow_fixture_provider_outside_production
    |                    the fixture result provider is a development aid. It is
    |                    always refused when app()->environment('production').
    |
    */
    'master_enabled' => (bool) env('LOTTERY_GAMES_ENABLED', false),

    /*
    | profile
    | A NAMED, COMPLETE reference rule set from config/lottery_games.profiles.php,
    | used to exercise the lane end to end before an official rules document
    | exists. NULL here — the default, and the only shape a production
    | deployment may run — means the games below are used exactly as written,
    | REQUIRED_INPUT included. RulesProfileResolver refuses any profile when
    | APP_ENV=production, and forces a profile-driven game to 'unverified', so
    | selecting one can never make a game sellable on its own.
    |
    | The operator's own imported values (lottery-games:rules:import) live in
    | storage/app/lottery_games/rules.override.json and are applied on top of a
    | profile. See RulesProfileResolver for why layers replace whole games
    | rather than being merged field by field.
    */
    'profile' => env('LOTTERY_GAMES_PROFILE'),

    'rtp_ceiling' => env('LOTTERY_GAMES_RTP_CEILING', '0.98'),

    'allow_fixture_provider_outside_production' => true,

    /*
    |--------------------------------------------------------------------------
    | Funding policy
    |--------------------------------------------------------------------------
    |
    | free_bet_returns_stake  whether a free-bet funded winning selection returns
    |                         the stake as well as the winnings. The default is
    |                         the common operator convention (stake not returned)
    |                         but it is a POLICY decision, so it is configurable
    |                         and read by PayoutCalculator — never assumed inline.
    |
    | cashback_basis_*        Batch-1 cashback basis rule. Bonus and free-bet
    |                         funded stakes are excluded from the cashback basis;
    |                         this is read by the cashback listener, not guessed.
    |
    */
    'funding' => [
        'free_bet_returns_stake' => (bool) env('LOTTERY_GAMES_FREE_BET_RETURNS_STAKE', false),

        'cashback_basis_excludes_funding_sources' => ['bonus', 'free_bet'],

        // The Batch-1 free-bet voucher lane, named as a container class that
        // implements App\Contracts\LotteryGames\FreeBetVoucherProvider. NULL on
        // this deployment because that lane does not exist here yet: a placement
        // that names a voucher is then refused with game_funding_unavailable
        // instead of accepting a bet nobody funded. Point this at the real
        // service when it lands — no code change is needed.
        'free_bet_provider' => env('LOTTERY_GAMES_FREE_BET_PROVIDER'),

        // The Batch-1 cashback service, if any. NULL means a cancellation does
        // not attempt a reversal (and logs that it did not). Its reverse()
        // signature receives (userId, betId, reason).
        'cashback_service' => env('LOTTERY_GAMES_CASHBACK_SERVICE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Result ingestion
    |--------------------------------------------------------------------------
    |
    | staging_retention_days  how long a refused/unusable import row is kept
    |                         before the purge command may delete it.
    |
    */
    'ingestion' => [
        'staging_retention_days' => 90,

        // Automated ingestion must be attributable to a real operator seat: the
        // existing confirmation boundary enforces four eyes, and a record with no
        // ingested_by cannot be confirmed. NULL therefore means "stage the row and
        // stop", which is what happens until an operator is appointed here.
        'maker_user_id' => env('LOTTERY_GAMES_MAKER_USER_ID'),

        'http_timeout_seconds' => (int) env('LOTTERY_GAMES_HTTP_TIMEOUT', 10),

        // The scheduled import runs every minute but only asks the feed about a game
        // that has a draw due and unfinished (ImportWindowService). Both bounds are
        // operator decisions and neither default encodes an official fact:
        //   lead   ask this many seconds BEFORE a draw's scheduled time (0 = not before)
        //   after  stop asking this many seconds AFTER it (NULL = until the draw is
        //          completed or cancelled)
        'import_window_lead_seconds' => (int) env('LOTTERY_GAMES_IMPORT_LEAD_SECONDS', 0),

        'import_window_after_seconds' => env('LOTTERY_GAMES_IMPORT_AFTER_SECONDS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator console
    |--------------------------------------------------------------------------
    |
    | verify_enables_game  whether "Verify rules" also switches selling on. The
    |                      default keeps the one-click behaviour the console has always
    |                      had; set false when verifying a payout table and opening a
    |                      game to players should be two separate, separately audited
    |                      decisions (the "Enable selling" action then does the second).
    |
    */
    'admin' => [
        'verify_enables_game' => (bool) env('LOTTERY_GAMES_VERIFY_ENABLES_GAME', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | THE GAMES
    |--------------------------------------------------------------------------
    |
    | Each entry is one game. Keys and their meaning:
    |
    |   code                 stable machine identity. Never change it: it is
    |                        stored on draws.game_type and bets.game_code.
    |   name                 display name (operator supplied).
    |   digits               number of digits in a selection/draw result.
    |   timezone             IANA timezone the draw calendar is expressed in.
    |   draw_cron            list of cron expressions, one per daily draw.
    |   cutoff_seconds       betting closes this many seconds before the draw.
    |   bet_types            per bet type: selection_digits, match_mode
    |                        (exact|any_order|prefix|suffix), payout_multiplier.
    |   min_stake/max_stake  per-selection stake bounds, exact decimal strings.
    |   max_liability_per_draw   cap on total potential payout for one draw.
    |   max_liability_per_number cap on potential payout for one exact number.
    |   rules_status         unverified|verified|suspended. The DB row
    |                        (lottery_games) is authoritative at runtime; this
    |                        value seeds it and is compared for revocation.
    |   result_source        driver + endpoint contract for the official feed.
    |   settlement_market_key  the existing config('lottery.markets') key each
    |                        bet type settles through, so the EXISTING
    |                        SelectionSettlementResolver/settlement pipeline is
    |                        reused rather than reimplemented.
    |   publication_bridge   how this game's digits project onto the existing
    |                        draw result confirmation boundary
    |                        (first_prize / bottom_two). REQUIRED_INPUT until an
    |                        operator states the mapping; without it ingestion
    |                        stages the row but never claims publication.
    |
    */
    'games' => [

        'speed_88' => [
            'code' => 'speed_88',
            'name' => 'Speed 88',
            'digits' => 'REQUIRED_INPUT',
            'timezone' => 'Asia/Bangkok',

            // One entry per daily draw. The mockups show 21:15 / 21:30 / 21:45
            // as display hints only; they are NOT a schedule source.
            'draw_cron' => ['REQUIRED_INPUT'],

            'cutoff_seconds' => 'REQUIRED_INPUT',

            'bet_types' => [
                // 4D-style markets are declared as placeholders with no
                // multiplier: an operator must supply the real numbers.
                '4d_direct' => [
                    'selection_digits' => 'REQUIRED_INPUT',
                    'match_mode' => 'exact',
                    'payout_multiplier' => 'REQUIRED_INPUT',
                ],
                '4d_any_order' => [
                    'selection_digits' => 'REQUIRED_INPUT',
                    'match_mode' => 'any_order',
                    'payout_multiplier' => 'REQUIRED_INPUT',
                ],
                '3d_direct' => [
                    'selection_digits' => 'REQUIRED_INPUT',
                    'match_mode' => 'exact',
                    'payout_multiplier' => 'REQUIRED_INPUT',
                ],
                '2d_direct' => [
                    'selection_digits' => 'REQUIRED_INPUT',
                    'match_mode' => 'suffix',
                    'payout_multiplier' => 'REQUIRED_INPUT',
                ],
            ],

            'min_stake' => 'REQUIRED_INPUT',
            'max_stake' => 'REQUIRED_INPUT',
            'max_liability_per_draw' => 'REQUIRED_INPUT',
            'max_liability_per_number' => 'REQUIRED_INPUT',

            'rules_status' => 'unverified',

            'settlement_market_key' => 'REQUIRED_INPUT',

            'publication_bridge' => [
                'first_prize' => 'REQUIRED_INPUT',
                'bottom_two' => 'REQUIRED_INPUT',
            ],

            'result_source' => [
                'driver' => 'official',
                'base_url' => 'REQUIRED_INPUT',
                'latest_path' => 'REQUIRED_INPUT',
                'by_draw_no_path' => 'REQUIRED_INPUT',
                'draw_no_field' => 'REQUIRED_INPUT',
                'digits_field' => 'REQUIRED_INPUT',
                'drawn_at_field' => 'REQUIRED_INPUT',
                'schema_version' => 'REQUIRED_INPUT',
            ],
        ],

        'lao_hanoi_4d' => [
            'code' => 'lao_hanoi_4d',
            'name' => 'Lao Hanoi 4D',
            'digits' => 'REQUIRED_INPUT',
            'timezone' => 'Asia/Bangkok',

            'draw_cron' => ['REQUIRED_INPUT'],

            'cutoff_seconds' => 'REQUIRED_INPUT',

            'bet_types' => [
                '4d_direct' => [
                    'selection_digits' => 'REQUIRED_INPUT',
                    'match_mode' => 'exact',
                    'payout_multiplier' => 'REQUIRED_INPUT',
                ],
                '4d_any_order' => [
                    'selection_digits' => 'REQUIRED_INPUT',
                    'match_mode' => 'any_order',
                    'payout_multiplier' => 'REQUIRED_INPUT',
                ],
                '3d_direct' => [
                    'selection_digits' => 'REQUIRED_INPUT',
                    'match_mode' => 'exact',
                    'payout_multiplier' => 'REQUIRED_INPUT',
                ],
                '2d_direct' => [
                    'selection_digits' => 'REQUIRED_INPUT',
                    'match_mode' => 'suffix',
                    'payout_multiplier' => 'REQUIRED_INPUT',
                ],
            ],

            'min_stake' => 'REQUIRED_INPUT',
            'max_stake' => 'REQUIRED_INPUT',
            'max_liability_per_draw' => 'REQUIRED_INPUT',
            'max_liability_per_number' => 'REQUIRED_INPUT',

            'rules_status' => 'unverified',

            'settlement_market_key' => 'REQUIRED_INPUT',

            'publication_bridge' => [
                'first_prize' => 'REQUIRED_INPUT',
                'bottom_two' => 'REQUIRED_INPUT',
            ],

            'result_source' => [
                'driver' => 'official',
                'base_url' => 'REQUIRED_INPUT',
                'latest_path' => 'REQUIRED_INPUT',
                'by_draw_no_path' => 'REQUIRED_INPUT',
                'draw_no_field' => 'REQUIRED_INPUT',
                'digits_field' => 'REQUIRED_INPUT',
                'drawn_at_field' => 'REQUIRED_INPUT',
                'schema_version' => 'REQUIRED_INPUT',
            ],
        ],
    ],
];
