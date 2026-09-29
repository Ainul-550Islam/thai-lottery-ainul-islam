<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Player area (authenticated surface) — EN
|--------------------------------------------------------------------------
|
| Copy for the authenticated player views: dashboard, draws, draw detail,
| bets, wallet, deposit, withdraw, profile. Values like money, references
| and dates are always interpolated, never baked into strings.
|
*/

return [
    // Dashboard
    'dash_hero_subtitle' => 'Thai Lottery Wagering & Instant Payout System',
    'available_balance' => 'Available Balance',
    'next_official_draw' => 'Next Official Draw',
    'betting_closes_in' => 'Betting Closes In',
    'payout_rate_3d_top' => '3-Digit Top Payout Rate:',
    'payout_rate_2d' => '2-Digit Top/Bottom Payout Rate:',
    'payout_rate_3d_tod' => '3-Digit Tod Payout Rate:',
    'enter_bet_slip' => 'Enter Bet Slip',
    'latest_results' => 'Latest Results',
    'view_all' => 'View All',
    'first_prize_6' => 'First Prize (6 Digits)',
    'digit_3d_top' => '3-Digit Top',
    'digit_2d_bottom' => '2-Digit Bottom',
    'my_recent_wagers' => 'My Recent Wagers',
    'recent_wagers_lead' => 'Track current draw tickets, pending status, and winning payouts.',
    'view_all_wagers' => 'View All Wagers',
    'col_ticket_ref' => 'Ticket Ref',
    'col_draw' => 'Draw',
    'col_items_numbers' => 'Items / Numbers',
    'col_stake' => 'Stake',
    'col_potential_payout' => 'Potential Payout',
    'col_status' => 'Status',
    'status_won' => 'WON',
    'status_lost' => 'LOST',
    'status_cancelled' => 'CANCELLED',
    'status_pending' => 'PENDING',
    'place_first_bet' => 'Place your first lottery bet today!',
    'no_recent_wagers' => 'No recent wagers found.',

    // Draws list
    'draws_title' => 'Thai Lottery Draws & Results',
    'draws_lead' => 'Official draw schedule, countdowns, and published winning numbers.',
    'status_open' => 'OPEN',
    'status_settled' => 'SETTLED',
    'scheduled_label' => 'Scheduled:',
    'closes_label' => 'Closes:',
    'digit_3d_top_short' => '3D Top',
    'digit_2d_bottom_short' => '2D Bottom',

    // Draw detail
    'first_prize_1' => 'First Prize',
    'digit_3d_tod' => '3-Digit Tod',
    'all_permutations' => 'All Permutations',
    'digit_2d_top' => '2-Digit Top',
    'results_pending' => 'Results are pending publication.',
    'results_pending_lead' => 'Official winning numbers are announced immediately following the government draw ceremony.',

    // Bets
    'bets_title' => 'My Lottery Bets',
    'bets_lead' => 'Audit log of all submitted lottery tickets, item selections, and settlement statuses.',
    'col_ticket_number' => 'Ticket Number',
    'col_markets_numbers' => 'Markets & Numbers',
    'col_total_stake' => 'Total Stake',

    // Wallet
    'wallet_title' => 'Wallet & Accounting Ledger',
    'wallet_lead' => 'Real-time balance, double-entry transaction history, and funds disbursement.',
    'available_balance_hint' => 'Unrestricted funds ready for betting',
    'total_deposited' => 'Total Deposited',
    'total_deposited_hint' => 'Lifetime confirmed deposits',
    'total_prizes' => 'Total Prizes Won',
    'total_prizes_hint' => 'Lifetime settled payouts',
    'journal_title' => 'Double-Entry Transaction Journal',
    'col_transaction_ref' => 'Transaction Ref',
    'col_type' => 'Type',
    'col_description' => 'Description',
    'col_amount' => 'Amount',
    'col_date' => 'Date',

    // Deposit
    'deposit_title' => 'Deposit Funds',
    'deposit_lead' => 'Top up your wallet using the payment methods enabled for your account.',
    'select_payment_method' => 'Select Payment Method',
    'deposit_amount_thb' => 'Deposit Amount (THB)',
    'recent_deposits' => 'Recent Deposits',

    // Withdraw
    'withdraw_title' => 'Withdraw Funds',
    'withdraw_lead' => 'Request a payout of your settled balance to your verified destination account.',
    'available_to_withdraw' => 'Available to Withdraw',
    'min_withdrawal' => 'Min Withdrawal:',
    'max_withdrawal' => 'Max Withdrawal:',
    'processing_time' => 'Processing Time:',
    'within_hours' => 'within :hours hours',
    'payout_method' => 'Payout Method',
    'bank_name' => 'Bank Name',
    'bank_transfer_only' => '(bank transfer only)',
    'account_label' => 'Account / Mobile / Wallet Address',
    'account_holder_name' => 'Account Holder Name',
    'kyc_name_match' => 'Account holder name must match your KYC verified identity.',
    'withdrawal_amount_thb' => 'Withdrawal Amount (THB)',
    'recent_withdrawals' => 'Recent Withdrawals',

    // Profile
    'profile_title' => 'Player Profile & Limits',
    'profile_lead' => 'Manage personal details, KYC identity verification, and responsible gaming limits.',
    'link_account_verification' => 'Account verification',
    'link_account_grade' => 'Account grade',
    'link_fees' => 'Fees',
    'personal_details' => 'Personal Details',
    'full_name' => 'Full Name',
    'phone_number' => 'Phone Number',
];
