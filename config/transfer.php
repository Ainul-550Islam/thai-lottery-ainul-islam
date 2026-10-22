<?php

use App\Enums\TransferDirection;
use App\Enums\WalletType;

/*
|--------------------------------------------------------------------------
| Balance transfers (Cash <-> Win)
|--------------------------------------------------------------------------
|
| Everything the transfer lane is allowed to do, and how much it costs.
|
| THE FEE IS NOT PRICED HERE.
| Each direction names a `fee_category` that already exists in config/fees.php
| and is already published on the public Fees page. TransferFeeCalculator calls
| FeesPageService::calculate() on that category, so the number the player reads
| on /fees and the number the engine charges come from ONE row through ONE
| calculator. Duplicating the rate here would create exactly the drift the
| public schedule is supposed to make impossible, so this file holds limits and
| switches only — never a rate.
|
| CASH IS `primary`.
| WalletType::Primary is the wallet every deposit already lands in and every
| existing balance already sits on. Introducing a separate `cash` type would
| create a second wallet meaning "main money" and orphan real balances, so the
| cash pocket IS primary and the mapping is declared once, here.
|
| AMOUNTS ARE DECIMAL STRINGS.
| Never floats. The engine parses them through App\Services\Finance\Money, which
| rejects anything that is not a plain decimal string.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | Off means the lane refuses with 503 and changes no wallet state. It does
    | not hide the history routes: a player must still be able to see transfers
    | that already happened.
    */

    'enabled' => (bool) env('BALANCE_TRANSFER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | The two pockets
    |--------------------------------------------------------------------------
    | Resolved to WalletType cases by TransferWalletResolver. Kept as config so
    | the mapping is inspectable in one place and so a test can prove the
    | resolver refuses any wallet type that is not one of these two.
    */

    'wallets' => [
        'cash' => WalletType::Primary->value,
        'win' => WalletType::Win->value,
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    | A transfer never converts currency: both pockets must already be
    | denominated in this currency, and a mismatch is refused rather than
    | silently exchanged at an invented rate.
    */

    'currency' => (string) env('BALANCE_TRANSFER_CURRENCY', 'THB'),

    /*
    |--------------------------------------------------------------------------
    | Fee model
    |--------------------------------------------------------------------------
    | 'on_top'  : the player asks to move A; the source is debited A + fee and
    |             the destination is credited A. The amount they typed is the
    |             amount that arrives, which is what the quote shows.
    |
    | This is the ONLY supported value. A 'deducted' mode would credit A - fee,
    | which means the arriving amount depends on a rate the player cannot see
    | change between quoting and submitting. The option is deliberately absent
    | rather than present-and-disabled.
    */

    'fee_model' => 'on_top',

    /*
    |--------------------------------------------------------------------------
    | Per-direction rules
    |--------------------------------------------------------------------------
    | Keyed by TransferDirection value. A direction missing from this array is
    | refused as unconfigured: the lane fails closed rather than defaulting to
    | permissive limits.
    */

    'directions' => [

        TransferDirection::CashToWin->value => [
            'enabled' => (bool) env('BALANCE_TRANSFER_CASH_TO_WIN_ENABLED', true),

            // The published fee row this direction is priced from.
            'fee_category' => 'cash_to_win',

            'min_amount' => (string) env('BALANCE_TRANSFER_CASH_TO_WIN_MIN', '20.00'),
            'max_amount' => (string) env('BALANCE_TRANSFER_CASH_TO_WIN_MAX', '500000.00'),

            // Rolling 24h window, counted per player per direction.
            'daily_count_limit' => (int) env('BALANCE_TRANSFER_CASH_TO_WIN_DAILY_COUNT', 50),
            'daily_amount_limit' => (string) env('BALANCE_TRANSFER_CASH_TO_WIN_DAILY_AMOUNT', '1000000.00'),

            // Minimum seconds between two accepted transfers in this direction.
            // Stops an accidental double-submit from becoming two real moves
            // even when the client failed to reuse its idempotency key.
            'cooldown_seconds' => (int) env('BALANCE_TRANSFER_CASH_TO_WIN_COOLDOWN', 5),
        ],

        TransferDirection::WinToCash->value => [
            'enabled' => (bool) env('BALANCE_TRANSFER_WIN_TO_CASH_ENABLED', true),

            'fee_category' => 'win_to_cash',

            'min_amount' => (string) env('BALANCE_TRANSFER_WIN_TO_CASH_MIN', '20.00'),
            'max_amount' => (string) env('BALANCE_TRANSFER_WIN_TO_CASH_MAX', '500000.00'),

            'daily_count_limit' => (int) env('BALANCE_TRANSFER_WIN_TO_CASH_DAILY_COUNT', 50),
            'daily_amount_limit' => (string) env('BALANCE_TRANSFER_WIN_TO_CASH_DAILY_AMOUNT', '1000000.00'),

            'cooldown_seconds' => (int) env('BALANCE_TRANSFER_WIN_TO_CASH_COOLDOWN', 5),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    | A submitted key is remembered for this long. Inside the window a repeat
    | returns the ORIGINAL outcome instead of moving money twice; a repeat with
    | a different payload is refused as a conflict rather than guessed at.
    */

    'idempotency' => [
        'header' => (string) env('BALANCE_TRANSFER_IDEMPOTENCY_HEADER', 'Idempotency-Key'),
        'ttl_seconds' => (int) env('BALANCE_TRANSFER_IDEMPOTENCY_TTL', 86400),
        'max_key_length' => 128,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (the OUTER wall)
    |--------------------------------------------------------------------------
    | Keyed on the authenticated user and enforced in middleware, before any
    | controller code runs. These are cost/abuse limits, not business rules:
    | the daily counts, daily totals and cooldowns under `directions` above are
    | the business rules and live in TransferLimitService.
    |
    | `submit` is tight because every submit takes two wallet row locks. `quote`
    | is loose because it reads and prices only — a player dragging an amount
    | slider must not be throttled into a broken UI.
    */

    'rate_limits' => [
        'submit_per_minute' => (int) env('BALANCE_TRANSFER_SUBMIT_PER_MINUTE', 10),
        'quote_per_minute' => (int) env('BALANCE_TRANSFER_QUOTE_PER_MINUTE', 60),

        // Operator desk. The read ceiling is loose - an operator triaging a dispute
        // pages through a lot of rows - and the reversal ceiling is the tightest number
        // in this file, because every hit moves money and a legitimate correction is a
        // handful per shift, not per minute.
        'desk_per_minute' => (int) env('BALANCE_TRANSFER_DESK_PER_MINUTE', 120),
        'reverse_per_minute' => (int) env('BALANCE_TRANSFER_REVERSE_PER_MINUTE', 3),

        // The checker's half of the four-eyes lane. Looser than the maker's ceiling
        // because one checker may be clearing requests several makers raised, but still
        // tight: approving is the act that moves the money, so a leaked staff session
        // should not be able to clear a queue quickly.
        'reverse_decide_per_minute' => (int) env('BALANCE_TRANSFER_REVERSE_DECIDE_PER_MINUTE', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    'reference_prefix' => (string) env('BALANCE_TRANSFER_REFERENCE_PREFIX', 'BT'),

    // How many rows the player-facing history page returns per page.
    'history_per_page' => (int) env('BALANCE_TRANSFER_HISTORY_PER_PAGE', 20),

    // How many rows the OPERATOR desk returns per page. Larger than the player's
    // history because an operator is triaging, not reviewing their own account, and
    // the desk is gated by transfer.view_any. Still hard-capped in the controller.
    'desk_per_page' => (int) env('BALANCE_TRANSFER_DESK_PER_PAGE', 25),

    /*
    |--------------------------------------------------------------------------
    | Four-eyes on reversals
    |--------------------------------------------------------------------------
    | A reversal is the one money movement in this application with no player
    | request behind it: an operator decides unilaterally that a completed
    | transfer was wrong and undoes it. That is the shape of action a second
    | pair of eyes exists for, and the payout lane already applies the same
    | control to prize disbursements - see config/finance.php's
    | auto_approve_below / require_approval_at_or_above and
    | PayoutApprovalException::CODE_MAKER_EQUALS_CHECKER.
    |
    | This mirrors that lane's shape rather than inventing a new one:
    |
    |   - Below the threshold the maker's POST executes immediately, exactly as
    |     it always has. Small corrections stay one-operator.
    |   - At or above it the POST opens a PENDING request and moves nothing; a
    |     second operator holding transfer.approve_reverse must approve before
    |     BalanceTransferReversalService runs.
    |   - '0.00' means EVERY reversal requires four-eyes, the same convention
    |     config/finance.php documents for prizes.
    |
    | WHY A THRESHOLD CANNOT BE GAMED HERE
    | The amount compared is the ORIGINAL transfer's amount, which the maker does
    | not choose - a reversal always undoes the whole of a specific completed
    | transfer. So there is no way to split a large reversal into small ones to
    | slip under the line, which is the usual way a threshold like this is
    | defeated.
    |
    | Independence is per ACTOR, not per role: admin holds both transfer.reverse
    | and transfer.approve_reverse, exactly as it holds both GLO freeze request
    | and review. What the service refuses is one human being on both sides.
    */

    'reversal_approval' => [
        'enabled' => (bool) env('BALANCE_TRANSFER_REVERSAL_APPROVAL_ENABLED', true),

        // 50,000.00 is one tenth of the single-transfer ceiling: directions.*.max_amount
        // is 500000.00 in both directions, so the line sits inside the range this lane
        // can actually produce rather than above it. A threshold copied from the prize
        // lane's 1,000,000.00 would never fire here, because no balance transfer can be
        // that large - dead config that reads like a control.
        'require_at_or_above' => (string) env('BALANCE_TRANSFER_REVERSAL_APPROVAL_AT', '50000.00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    | A player may always transfer their own balance; these gate the OPERATOR
    | surfaces (viewing any player's transfers, reversing one, deciding a
    | reversal that needs four-eyes).
    */

    'permissions' => [
        'view_any' => 'transfer.view_any',
        'reverse' => 'transfer.reverse',
        'approve_reverse' => 'transfer.approve_reverse',
    ],
];
