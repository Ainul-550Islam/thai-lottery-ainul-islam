<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Universal Withdrawal Portal | Thai Lotto</title>

    <!-- Tailwind CSS (CDN for standalone preview, plus project stylesheet) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="{{ asset('css/withdrawal-portal.css') }}">
    <style>
        :root {
            --wp-bg-page: #0b0f17;
            --wp-bg-header: #0f1522;
            --wp-bg-card: #131b29;
            --wp-bg-card-sub: #172233;
            --wp-border-main: #1f2b3e;
            --wp-blue-accent: #38bdf8;
            --wp-green-accent: #10b981;
        }
        body {
            background-color: #0b0f17;
            color: #f1f5f9;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
    </style>
</head>
<body class="wp-body">

    <!-- TOP HEADER -->
    <header class="wp-header">
        <a href="/withdrawal" class="wp-brand-box">
            <div class="wp-brand-icon">💸</div>
            <div>
                <div class="wp-brand-title">THAI LOTTO</div>
                <div class="wp-brand-sub">Withdrawal Portal</div>
            </div>
        </a>

        <div class="wp-user-stats">
            <div class="wp-stat-chip wp-stat-chip-green">
                <span class="text-slate-400 font-normal">Withdrawable:</span>
                <span id="headerAvailableBalance" class="font-bold">{{ number_format($withdrawableBalance ?? 5200, 2) }} THB</span>
            </div>

            <a href="/wallet" class="text-slate-400 hover:text-white text-xs font-bold transition">
                Wallet Overview →
            </a>
        </div>
    </header>

    <!-- MAIN CONTAINER -->
    <main class="wp-container">
        <!-- LEFT WORKFLOW PANEL -->
        <div class="wp-workflow-panel">
            <!-- SECURITY BANNER -->
            <div class="wp-security-banner">
                <div>
                    <h1 class="wp-security-title">
                        Automated Instant Payouts <span>&lt; 60 Seconds</span>
                    </h1>
                    <p class="wp-security-desc">
                        Funds are transferred directly to your verified bank account, PromptPay, or crypto wallet with 0% withdrawal fee.
                    </p>
                    <div class="wp-security-tags">
                        <span class="wp-security-pill">🔒 2FA Protected</span>
                        <span class="wp-security-pill" style="border-color: #10b981; color: #34d399; background: rgba(16,185,129,0.15);">0% Payout Fee</span>
                        <span class="wp-security-pill" style="border-color: #f59e0b; color: #fbbf24; background: rgba(245,158,11,0.15);">Daily Limit: 50,000 THB</span>
                    </div>
                </div>
            </div>

            <!-- STEP 1: CATEGORY SELECTOR -->
            <div>
                <span class="text-xs font-extrabold text-slate-300 block mb-3 uppercase tracking-wider">
                    1. Select Payout Method
                </span>
                <div class="wp-categories-grid">
                    <button type="button" class="wp-category-card active" data-withdraw-cat="banking">
                        <span class="wp-category-icon">🏛</span>
                        <span class="wp-category-name">Thai Banking</span>
                        <span class="wp-category-tag">Direct Wire</span>
                    </button>
                    <button type="button" class="wp-category-card" data-withdraw-cat="promptpay">
                        <span class="wp-category-icon">📱</span>
                        <span class="wp-category-name">PromptPay</span>
                        <span class="wp-category-tag">Fastest</span>
                    </button>
                    <button type="button" class="wp-category-card" data-withdraw-cat="crypto">
                        <span class="wp-category-icon">₮</span>
                        <span class="wp-category-name">Crypto / USDT</span>
                        <span class="wp-category-tag">TRC-20</span>
                    </button>
                    <button type="button" class="wp-category-card" data-withdraw-cat="mfs">
                        <span class="wp-category-icon">👛</span>
                        <span class="wp-category-name">TrueMoney</span>
                        <span class="wp-category-tag">e-Wallet</span>
                    </button>
                    <button type="button" class="wp-category-card" data-withdraw-cat="vip">
                        <span class="wp-category-icon">👑</span>
                        <span class="wp-category-name">VIP Cashout</span>
                        <span class="wp-category-tag">&gt; 100K</span>
                    </button>
                </div>
            </div>

            <!-- STEP 2: AMOUNT SELECTION -->
            <div class="wp-amount-section">
                <span class="text-xs font-extrabold text-slate-300 block uppercase tracking-wider">
                    2. Choose Withdrawal Amount
                </span>

                <div class="wp-quick-amounts-grid">
                    <button type="button" class="wp-amount-btn" data-withdraw-amount="300">300 THB</button>
                    <button type="button" class="wp-amount-btn" data-withdraw-amount="500">500 THB</button>
                    <button type="button" class="wp-amount-btn active" data-withdraw-amount="1000">1,000 THB</button>
                    <button type="button" class="wp-amount-btn" data-withdraw-amount="2000">2,000 THB</button>
                    <button type="button" class="wp-amount-btn" data-withdraw-amount="3000">3,000 THB</button>
                    <button type="button" class="wp-amount-btn" data-withdraw-amount="5000">5,000 THB</button>
                    <button type="button" class="wp-amount-btn col-span-2" data-withdraw-amount="all">
                        All Withdrawable ({{ number_format($withdrawableBalance ?? 5200, 2) }} THB)
                    </button>
                </div>

                <!-- Custom Amount Input -->
                <div class="wp-custom-amount-box">
                    <span class="wp-currency-prefix">THB</span>
                    <input
                        type="text"
                        id="customWithdrawAmountInput"
                        class="wp-amount-input"
                        value="1000"
                        placeholder="Enter custom withdrawal amount..."
                    >
                </div>
            </div>

            <!-- STEP 3: DESTINATION ACCOUNT DETAILS -->
            <div class="wp-channel-card">
                <span class="text-xs font-extrabold text-slate-300 block uppercase tracking-wider">
                    3. Destination Account Details
                </span>

                <!-- Banking -->
                <div id="withdrawBankingSection" class="space-y-3">
                    <div class="wp-saved-account-box">
                        <div class="flex items-center gap-3">
                            <div class="wp-bank-logo wp-bank-scb">SCB</div>
                            <div>
                                <div class="text-xs font-bold text-white">Siam Commercial Bank (Primary)</div>
                                <div class="font-mono text-xs text-slate-300">Acc: {{ $savedBank['account_number'] ?? '•••• •••• 4210' }} ({{ $userName ?? 'Alex Thompson' }})</div>
                            </div>
                        </div>
                        <span class="bg-emerald-500/15 border border-emerald-500 text-emerald-400 text-[10px] font-black px-2.5 py-0.5 rounded">
                            KYC Verified
                        </span>
                    </div>
                </div>

                <!-- PromptPay -->
                <div id="withdrawPromptpaySection" class="space-y-3" style="display: none;">
                    <div class="wp-saved-account-box" style="border-color: #38bdf8;">
                        <div>
                            <div class="text-xs font-bold text-white">PromptPay ID (Registered Phone)</div>
                            <div class="font-mono text-xs text-sky-400">{{ $savedPromptPay['phone'] ?? '081-•••-5678' }} ({{ $userName ?? 'Alex Thompson' }})</div>
                        </div>
                        <span class="bg-sky-500/15 border border-sky-500 text-sky-400 text-[10px] font-black px-2.5 py-0.5 rounded">
                            Instant Payout
                        </span>
                    </div>
                </div>

                <!-- Crypto -->
                <div id="withdrawCryptoSection" class="space-y-2" style="display: none;">
                    <label class="text-[11px] text-slate-400 block font-semibold">Recipient USDT Address (TRC-20)</label>
                    <input
                        type="text"
                        value="TQn9Y2khEsLJW1ChVWFMSMeRDow5KcbLSE"
                        class="w-full bg-[#0c121d] border border-slate-700 rounded-xl px-4 py-2.5 text-xs font-mono text-emerald-400 outline-none"
                    >
                    <span class="text-[10px] text-slate-500 block">
                        Estimated Crypto: <span id="cryptoEstAmount">27.93</span> USDT (Rate: 1 USDT ≈ 35.80 THB)
                    </span>
                </div>

                <!-- MFS -->
                <div id="withdrawMfsSection" class="space-y-3" style="display: none;">
                    <div class="wp-saved-account-box" style="border-color: #f59e0b;">
                        <div>
                            <div class="text-xs font-bold text-white">TrueMoney Wallet Phone Number</div>
                            <div class="font-mono text-xs text-amber-400">081-•••-5678</div>
                        </div>
                        <span class="bg-amber-500/15 border border-amber-500 text-amber-400 text-[10px] font-black px-2.5 py-0.5 rounded">
                            TrueMoney Ready
                        </span>
                    </div>
                </div>

                <!-- VIP -->
                <div id="withdrawVipSection" class="space-y-3" style="display: none;">
                    <div class="bg-[#172233] border border-amber-500 rounded-xl p-4 text-xs space-y-1">
                        <div class="font-bold text-amber-400">👑 Dedicated VIP Concierge Cashout</div>
                        <p class="text-slate-300">
                            For withdrawals exceeding 100,000 THB, a personal account manager facilitates bank-branch OTC cashier check or private secure transfer.
                        </p>
                    </div>
                </div>
            </div>

            <!-- RECENT WITHDRAWALS TABLE -->
            <div class="wp-channel-card">
                <span class="text-xs font-extrabold text-slate-300 block uppercase tracking-wider">
                    Recent Withdrawal Requests
                </span>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="text-slate-400 border-b border-[#1f2b3e]">
                            <tr>
                                <th class="py-2.5">Ref ID</th>
                                <th class="py-2.5">Date</th>
                                <th class="py-2.5">Method</th>
                                <th class="py-2.5">Amount</th>
                                <th class="py-2.5">Fee</th>
                                <th class="py-2.5">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#172233] text-slate-300">
                            @foreach($recentWithdrawals as $item)
                                <tr>
                                    <td class="py-3 font-mono font-bold text-slate-200">{{ $item['ref_id'] }}</td>
                                    <td class="py-3 text-slate-400">{{ $item['date'] }}</td>
                                    <td class="py-3">{{ $item['method'] }}</td>
                                    <td class="py-3 font-mono font-bold text-white">{{ $item['amount'] }}</td>
                                    <td class="py-3 font-mono text-slate-400">{{ $item['fee'] }}</td>
                                    <td class="py-3">
                                        <span class="bg-emerald-500/15 border border-emerald-500 text-emerald-400 text-[10px] font-black px-2 py-0.5 rounded-full">
                                            {{ $item['status'] }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- RIGHT: ORDER SUMMARY & CONFIRMATION -->
        <aside class="wp-summary-card">
            <div>
                <h2 class="wp-summary-title">Payout Summary</h2>

                <div class="wp-summary-rows">
                    <div class="wp-summary-row">
                        <span>Requested Payout:</span>
                        <span id="summaryRequestedAmount" class="font-mono font-bold text-white">1,000 THB</span>
                    </div>
                    <div class="wp-summary-row">
                        <span>Processing Fee:</span>
                        <span class="font-mono font-bold text-emerald-400">0.00 THB (Free)</span>
                    </div>
                    <div class="wp-summary-row">
                        <span>Estimated Arrival:</span>
                        <span class="font-bold text-sky-400">Instant (&lt; 60s)</span>
                    </div>
                    <div class="wp-summary-row total">
                        <span>Net Received:</span>
                        <span id="summaryNetPayout" class="font-mono text-sky-400 text-lg">1,000 THB</span>
                    </div>
                    <div class="wp-summary-row" style="padding-top: 0.35rem; font-size: 0.75rem;">
                        <span>Remaining Balance:</span>
                        <span id="summaryRemainingBalance" class="font-mono font-bold text-slate-400">4,200 THB</span>
                    </div>
                </div>
            </div>

            <div class="pt-4">
                <button type="button" id="confirmWithdrawalBtn" class="wp-confirm-btn">
                    Confirm &amp; Withdraw Funds
                </button>
            </div>
        </aside>
    </main>

    <!-- Browser Runtime Script -->
    <script src="{{ asset('js/withdrawal-portal.js') }}"></script>
</body>
</html>
