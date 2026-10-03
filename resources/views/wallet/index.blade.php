<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Wallet Management | FortuneLotto</title>

    <!-- Tailwind CSS (CDN for standalone preview, plus project stylesheet) -->
    <script src="https://cdn.tailwindcss.com"></script>
    @vite(['resources/css/wallet-management.css'])
    <style>
        :root {
            --wm-bg-page: #0d121b;
            --wm-bg-header: #101622;
            --wm-bg-card: #131a27;
            --wm-bg-subcard: #172132;
            --wm-border-main: #1f2b3e;
            --wm-gold-main: #f59e0b;
            --wm-green-main: #10b981;
            --wm-cyan-main: #06b6d4;
        }
        body {
            background-color: #0d121b;
            color: #f1f5f9;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
    </style>
</head>
<body class="wm-body">

    <!-- WINDOW SHELL (macOS window traffic dots) -->
    <div class="wm-window-bar">
        <span class="wm-traffic-dot wm-dot-red"></span>
        <span class="wm-traffic-dot wm-dot-yellow"></span>
        <span class="wm-traffic-dot wm-dot-green"></span>
    </div>

    <!-- TOP HEADER -->
    <header class="wm-header">
        <div class="flex items-center gap-10">
            <!-- Brand Logo -->
            <a href="/wallet" class="wm-brand-logo">
                <span class="wm-brand-icon">🪷</span>
                <span>FortuneLotto</span>
            </a>

            <!-- Navigation Links -->
            <nav class="wm-nav-links">
                <a href="/dashboard" class="wm-nav-link">
                    <svg class="w-4 h-4 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                    <span>Dashboard</span>
                </a>
                <a href="#tickets" class="wm-nav-link">
                    <svg class="w-4 h-4 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/></svg>
                    <span>My Tickets</span>
                </a>
                <a href="/wallet" class="wm-nav-link active">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    <span>Wallet</span>
                </a>
                <a href="/betting" class="wm-nav-link">
                    <svg class="w-4 h-4 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                    <span>Lottery</span>
                </a>
                <a href="/player/security" class="wm-nav-link">
                    <svg class="w-4 h-4 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    <span>Profile</span>
                </a>
            </nav>
        </div>

        <!-- Top Right User & Settings -->
        <div class="flex items-center gap-6">
            <div class="wm-user-badge">
                <div class="wm-user-avatar">
                    <img
                        src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=100&auto=format&fit=crop&q=80"
                        alt="User"
                        class="w-full h-full object-cover"
                    />
                </div>
                <span>{{ $userName ?? 'Arjun J.' }}</span>
                <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </div>

            <a href="/player/security" class="wm-settings-link">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span>Settings</span>
            </a>
        </div>
    </header>

    <!-- MAIN CONTENT -->
    <main class="wm-main">
        <!-- TITLE -->
        <div class="wm-title-box">
            <h1 class="wm-title-main">Wallet Management</h1>
            <span class="wm-title-sub">Overview</span>
        </div>

        <!-- 3 OVERVIEW STAT CARDS -->
        <div class="wm-stat-grid">
            <!-- Card 1: Available Balance -->
            <div class="wm-stat-card wm-stat-card-cyan">
                <div class="wm-stat-info">
                    <span class="wm-stat-label">Available Balance</span>
                    <div id="availableBalanceDisplay" class="wm-stat-value">
                        {{ number_format($availableBalance ?? 5450, 2) }} THB
                    </div>
                    <span class="wm-stat-desc">Primary Funds for Play</span>
                </div>
                <div class="wm-stat-icon-badge wm-badge-cyan">
                    👛
                </div>
            </div>

            <!-- Card 2: Locked In-Play -->
            <div class="wm-stat-card wm-stat-card-gold">
                <div class="wm-stat-info">
                    <span class="wm-stat-label">Locked In-Play</span>
                    <div class="wm-stat-value">
                        {{ number_format($lockedBalance ?? 250, 2) }} THB
                    </div>
                    <span class="wm-stat-desc">Active Tickets in Draws</span>
                </div>
                <div class="wm-stat-icon-badge wm-badge-gold">
                    🔒
                </div>
            </div>

            <!-- Card 3: Lifetime Winnings -->
            <div class="wm-stat-card wm-stat-card-green">
                <div class="wm-stat-info">
                    <span class="wm-stat-label">Lifetime Winnings</span>
                    <div class="wm-stat-value">
                        {{ number_format($lifetimeWinnings ?? 94800, 2) }} THB
                    </div>
                    <span class="wm-stat-desc">Total Prize Money Won</span>
                </div>
                <div class="wm-stat-icon-badge wm-badge-green">
                    🏆
                </div>
            </div>
        </div>

        <!-- DEPOSIT & WITHDRAW SECTION -->
        <div class="wm-section-box">
            <div class="wm-section-header">
                <h2 class="wm-section-title">Deposit &amp; Withdraw</h2>
            </div>

            <!-- Tabs -->
            <div class="wm-tabs-row">
                <button type="button" class="wm-tab-btn active" data-tab-name="deposit">Deposit</button>
                <button type="button" class="wm-tab-btn" data-tab-name="withdraw">Withdraw</button>
            </div>

            <!-- Gateways Bar -->
            <div class="wm-payments-bar">
                <!-- Gateways List -->
                <div class="wm-gateways-list">
                    <button type="button" class="wm-gateway-btn active" data-gateway="stripe">
                        <span class="wm-gateway-logo text-indigo-400 font-extrabold">stripe</span>
                        <span class="wm-gateway-name">Stripe</span>
                    </button>
                    <button type="button" class="wm-gateway-btn" data-gateway="bkash">
                        <span class="wm-gateway-logo text-pink-500 font-extrabold">🦩</span>
                        <span class="wm-gateway-name">bKash</span>
                    </button>
                    <button type="button" class="wm-gateway-btn" data-gateway="nagad">
                        <span class="wm-gateway-logo text-amber-500 font-extrabold">🔥</span>
                        <span class="wm-gateway-name">Nagad</span>
                    </button>
                    <button type="button" class="wm-gateway-btn" data-gateway="usdt">
                        <span class="wm-gateway-logo text-emerald-400 font-extrabold">₮</span>
                        <span class="wm-gateway-name">USDT (TRC-20)</span>
                    </button>
                    <button type="button" class="wm-gateway-btn" data-gateway="thai_wire">
                        <span class="wm-gateway-logo text-blue-400 font-extrabold">🏛</span>
                        <span class="wm-gateway-name">Thai Bank Wire</span>
                    </button>
                </div>

                <!-- Amount Presets -->
                <div class="wm-amount-presets">
                    <span class="text-[11px] text-slate-400 font-semibold">Amount</span>
                    <div class="wm-amount-pills">
                        <button type="button" class="wm-amount-pill active" data-preset-amount="500">+500 THB</button>
                        <button type="button" class="wm-amount-pill" data-preset-amount="1000">+1000 THB</button>
                    </div>
                </div>

                <!-- Payment Method Select -->
                <div class="flex flex-col gap-1">
                    <span class="text-[11px] text-slate-400 font-semibold">Payment Method</span>
                    <select id="paymentMethodSelect" class="wm-dropdown-select">
                        <option value="USDT">USDT</option>
                        <option value="Credit Card">Credit Card</option>
                        <option value="PromptPay">PromptPay</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                    </select>
                </div>

                <!-- Action Button -->
                <div class="flex flex-col gap-1">
                    <span id="proceedBtnLabel" class="text-[11px] text-slate-400 font-semibold">Proceed to Deposit</span>
                    <button type="button" id="proceedPaymentBtn" class="wm-proceed-btn">
                        <span id="proceedBtnText">Proceed to Deposit</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- RECENT TRANSACTIONS TABLE -->
        <div class="wm-section-box">
            <h2 class="wm-section-title">Recent Transactions</h2>

            <div class="wm-table-card">
                <table class="wm-table">
                    <thead>
                        <tr>
                            <th>Ref ID</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Amount (THB)</th>
                            <th>Fee</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transactions as $tx)
                            <tr>
                                <td style="font-family: ui-monospace, monospace; font-weight: 700;">{{ $tx['ref_id'] }}</td>
                                <td style="color: #94a3b8;">{{ $tx['date'] }}</td>
                                <td>
                                    @if($tx['type'] === 'wager')
                                        <span class="wm-type-wager">{{ $tx['type_label'] }}</span>
                                    @elseif($tx['type'] === 'payout')
                                        <span class="wm-type-payout">{{ $tx['type_label'] }}</span>
                                    @else
                                        <span class="wm-type-deposit">{{ $tx['type_label'] }}</span>
                                    @endif
                                </td>
                                <td>{{ $tx['description'] }}</td>
                                <td style="font-family: ui-monospace, monospace; font-weight: 800; color: #ffffff;">{{ $tx['amount'] }}</td>
                                <td style="font-family: ui-monospace, monospace; color: #94a3b8;">{{ $tx['fee'] }}</td>
                                <td>
                                    @if($tx['status'] === 'Completed')
                                        <span class="wm-pill-completed">Completed</span>
                                    @else
                                        <span class="wm-pill-processing">Processing</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="wm-pagination-row">
                <span>Showing 1-10 of 312</span>
                <div class="wm-pagination-controls">
                    <button type="button" class="wm-page-btn" onclick="alert('Previous page');">Prev</button>
                    <button type="button" class="wm-page-btn active">1</button>
                    <button type="button" class="wm-page-btn" onclick="alert('Page 2');">2</button>
                    <button type="button" class="wm-page-btn" onclick="alert('Page 3');">3</button>
                    <span style="color: #64748b; padding: 0 4px;">...</span>
                    <button type="button" class="wm-page-btn" onclick="alert('Page 32');">32</button>
                    <button type="button" class="wm-page-btn" onclick="alert('Next page');">Next</button>
                </div>
            </div>
        </div>
    </main>

    <!-- Browser Runtime Script -->
    @vite(['resources/js/wallet-management.js'])
</body>
</html>
