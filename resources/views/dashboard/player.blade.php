<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Player Dashboard | THAI LOTTERY</title>

    <!-- Tailwind CSS (CDN for standalone preview, plus project stylesheet) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="{{ asset('css/player-dashboard.css') }}">
    <style>
        :root {
            --pd-bg-page: #0b1017;
            --pd-bg-header: #0e141e;
            --pd-bg-card: #121926;
            --pd-border-main: #1f2b3e;
            --pd-border-gold: #eab308;
            --pd-gold-text: #facc15;
            --pd-green-accent: #10b981;
        }
        body {
            background-color: #0b1017;
            color: #f1f5f9;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
    </style>
</head>
<body class="pd-body">

    <!-- TOP HEADER -->
    <header class="pd-header">
        <div class="flex items-center gap-10">
            <!-- Brand Logo -->
            <a href="/dashboard" class="pd-brand-logo">
                <div class="pd-logo-icon">
                    🐘
                </div>
                <div class="pd-logo-text">
                    <span class="pd-logo-text-thai">THAI</span>
                    <span class="pd-logo-text-lottery">LOTTERY</span>
                </div>
            </a>

            <!-- Navigation Links -->
            <nav class="pd-nav-menu">
                <a href="/dashboard" class="pd-nav-link active">Dashboard</a>
                <a href="/betting" class="pd-nav-link">Bet Slip</a>
                <a href="/results" class="pd-nav-link">Results</a>
                <a href="#history" class="pd-nav-link">History</a>
                <a href="#payments" class="pd-nav-link">Payments</a>
                <a href="/player/security" class="pd-nav-link">Account</a>
            </nav>
        </div>

        <!-- Top Right Controls -->
        <div class="pd-top-right">
            <!-- User Avatar -->
            <div class="pd-user-avatar">
                {{ $userInitials ?? 'SK' }}
                <span class="pd-user-status-dot"></span>
            </div>

            <!-- Notification Bell -->
            <button type="button" class="pd-notification-btn" onclick="alert('No new unread notifications');">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <span class="pd-notification-dot"></span>
            </button>

            <!-- Wallet Pill -->
            <div class="pd-top-wallet-chip">
                <span>**{{ number_format($userBalance ?? 5450, 2) }} THB</span>
            </div>
        </div>
    </header>

    <!-- MAIN DASHBOARD WRAPPER -->
    <main class="pd-main">
        <!-- WELCOME BANNER -->
        <div class="pd-welcome-row">
            <h1 class="pd-welcome-title">
                Welcome back, <span class="pd-welcome-name">{{ $userName ?? 'Sorn' }}!</span>
            </h1>

            <button type="button" id="walletDepositBtn" class="pd-deposit-cta">
                <span>Wallet Balance</span>
                <span class="text-amber-400">🪙</span>
                <span class="pd-deposit-highlight">+ Deposit</span>
            </button>
        </div>

        <!-- HERO SECTION: 2 CARDS -->
        <div class="pd-hero-grid">
            <!-- LEFT CARD: Draw #128 Countdown -->
            <section class="pd-countdown-card">
                <div>
                    <div class="pd-draw-header">
                        <span class="pd-draw-title">Draw #{{ $activeDraw['number'] ?? 128 }}</span>
                        <span class="pd-badge-open">{{ $activeDraw['status'] ?? 'OPEN' }}</span>
                    </div>

                    <div id="countdownTimerDisplay" class="pd-timer-display">
                        {{ $activeDraw['formatted_time'] ?? '14:48:02' }}
                    </div>

                    <div class="pd-timer-labels">
                        <span>Hours</span>
                        <span>Mins</span>
                        <span>Secs</span>
                    </div>

                    <div class="pd-next-draw-date">
                        {{ $activeDraw['next_draw_text'] ?? 'Next Draw: July 16, 2024, 4:00 PM ICT' }}
                    </div>
                </div>

                <div class="pd-draw-actions">
                    <a href="/betting" class="pd-wager-btn">Wager Now</a>
                    <a href="#history" class="pd-tickets-btn">View Tickets</a>
                </div>
            </section>

            <!-- RIGHT CARD: Latest Winning Numbers -->
            <section class="pd-winning-card">
                <div class="pd-winning-header">
                    <span class="pd-winning-title">LATEST WINNING NUMBERS</span>
                    <span class="pd-draw-subhead">Draw #{{ $latestWinning['draw_number'] ?? 127 }}, {{ $latestWinning['draw_date'] ?? 'July 1' }}</span>
                </div>

                <!-- 6 3D Hexagonal Gold Number Balls -->
                <div class="pd-hex-row">
                    @foreach($latestWinning['numbers'] ?? ['7', '2', '4', '6', '0', '5'] as $digit)
                        <div class="pd-hex-ball">{{ $digit }}</div>
                    @endforeach
                </div>

                <div class="pd-winning-footer">
                    <span class="pd-prize-text">
                        1st Prize: <span class="pd-prize-highlight">{{ $latestWinning['first_prize'] ?? '6,000,000 THB' }}</span>
                    </span>
                    <span class="pd-next-draw-tag">
                        {{ $latestWinning['next_draw_tag'] ?? 'Next Draw: Draw #128' }}
                    </span>
                </div>
            </section>
        </div>

        <!-- RECENT WAGERS SECTION -->
        <section class="pd-wagers-section">
            <h2 class="pd-section-heading">RECENT WAGERS</h2>

            <div class="pd-table-card">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Ticket ID</th>
                            <th>Date</th>
                            <th>Numbers</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentWagers as $wager)
                            <tr>
                                <td>
                                    <div class="pd-ticket-id-cell">
                                        <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        <span>{{ $wager['ticket_id'] }}</span>
                                    </div>
                                </td>
                                <td>{{ $wager['date'] }}</td>
                                <td>
                                    @if($wager['is_winner'])
                                        <span class="pd-ticket-num-gold">{{ $wager['numbers'] }}</span>
                                    @else
                                        <span class="pd-ticket-num-white">{{ $wager['numbers'] }}</span>
                                    @endif
                                </td>
                                <td>{{ $wager['type'] }}</td>
                                <td style="font-family: ui-monospace, monospace;">{{ $wager['amount'] }}</td>
                                <td>
                                    @if($wager['status'] === 'WON')
                                        <span class="pd-badge-won">WON</span>
                                    @elseif($wager['status'] === 'PENDING')
                                        <span class="pd-badge-pending">PENDING</span>
                                    @elseif($wager['status'] === 'LOST')
                                        <span class="pd-badge-lost">LOST</span>
                                    @endif
                                </td>
                                <td style="text-align: right;">
                                    @if($wager['status'] === 'PENDING')
                                        <a href="#modify" class="pd-action-link" onclick="alert('Modify wager modal'); return false;">Modify</a>
                                        <span style="color: #475569; margin: 0 4px;">/</span>
                                        <a href="#cancel" class="pd-action-cancel" data-cancel-ticket="{{ $wager['ticket_id'] }}">Cancel</a>
                                    @elseif($wager['status'] === 'LOST')
                                        <a href="#details" class="pd-action-link" onclick="alert('Details for ticket {{ $wager['ticket_id'] }}'); return false;">Details ⓘ</a>
                                    @else
                                        <a href="#view" class="pd-action-link" onclick="alert('Viewing ticket {{ $wager['ticket_id'] }}'); return false;">{{ $wager['action_label'] ?? 'View / History' }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <!-- Floating Help Button -->
    <button type="button" class="pd-floating-help" onclick="alert('Thai Lottery 24/7 Live Support Desk');" title="Support">
        ?
    </button>

    <!-- Interactive script bundle -->
    <script src="{{ asset('js/player-dashboard.js') }}"></script>
</body>
</html>
