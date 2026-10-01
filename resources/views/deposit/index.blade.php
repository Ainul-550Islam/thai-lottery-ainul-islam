<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Universal Deposit Portal | Thai Lotto</title>

    <!-- Tailwind CSS (CDN for standalone preview, plus project stylesheet) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="{{ asset('css/deposit-portal.css') }}">
    <style>
        :root {
            --dp-bg-page: #0b0f17;
            --dp-bg-header: #0f1522;
            --dp-bg-card: #131b29;
            --dp-bg-card-sub: #172233;
            --dp-border-main: #1f2b3e;
            --dp-green-accent: #10b981;
            --dp-gold-accent: #f59e0b;
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
<body class="dp-body">

    <!-- TOP HEADER -->
    <header class="dp-header">
        <a href="/deposit" class="dp-brand-box">
            <div class="dp-brand-logo-icon">⚡</div>
            <div>
                <div class="dp-brand-title">THAI LOTTO</div>
                <div class="dp-brand-sub">Deposit Gateway</div>
            </div>
        </a>

        <div class="dp-user-summary">
            <div class="dp-balance-badge">
                <span class="text-slate-400 font-normal">Wallet Balance:</span>
                <span>{{ number_format($userBalance ?? 5450, 2) }} THB</span>
            </div>

            <a href="/wallet" class="text-slate-400 hover:text-white text-xs font-bold transition">
                Transaction History →
            </a>
        </div>
    </header>

    <!-- MAIN CONTAINER -->
    <main class="dp-container">
        <!-- LEFT WORKFLOW PANEL -->
        <div class="dp-workflow-panel">
            <!-- PROMO BANNER -->
            <div class="dp-promo-banner">
                <div>
                    <h1 class="dp-promo-title">
                        Instant Deposit &amp; <span>+10% VIP Bonus</span> Active!
                    </h1>
                    <p class="dp-promo-desc">
                        Direct automated deposit integration with 0% processing fee and instant balance crediting.
                    </p>
                    <div class="dp-features-row">
                        <span class="dp-feature-pill">⚡ Instant &lt; 30s</span>
                        <span class="dp-feature-pill" style="border-color: #f59e0b; color: #fbbf24; background: rgba(245,158,11,0.15);">0% Processing Fee</span>
                        <span class="dp-feature-pill" style="border-color: #38bdf8; color: #38bdf8; background: rgba(56,189,248,0.15);">256-Bit SSL Encrypted</span>
                    </div>
                </div>
            </div>

            <!-- STEP 1: CATEGORY SELECTOR -->
            <div>
                <span class="text-xs font-extrabold text-slate-300 block mb-3 uppercase tracking-wider">
                    1. Select Deposit Method
                </span>
                <div class="dp-categories-grid">
                    <button type="button" class="dp-category-card active" data-deposit-cat="promptpay">
                        <span class="dp-category-icon">📱</span>
                        <span class="dp-category-name">PromptPay QR</span>
                        <span class="dp-category-tag">Fastest</span>
                    </button>
                    <button type="button" class="dp-category-card" data-deposit-cat="banking">
                        <span class="dp-category-icon">🏛</span>
                        <span class="dp-category-name">Thai Banking</span>
                        <span class="dp-category-tag">Bank Wire</span>
                    </button>
                    <button type="button" class="dp-category-card" data-deposit-cat="crypto">
                        <span class="dp-category-icon">₮</span>
                        <span class="dp-category-name">Crypto / USDT</span>
                        <span class="dp-category-tag">TRC-20</span>
                    </button>
                    <button type="button" class="dp-category-card" data-deposit-cat="mfs">
                        <span class="dp-category-icon">👛</span>
                        <span class="dp-category-name">TrueMoney</span>
                        <span class="dp-category-tag">e-Wallet</span>
                    </button>
                    <button type="button" class="dp-category-card" data-deposit-cat="card">
                        <span class="dp-category-icon">💳</span>
                        <span class="dp-category-name">Credit Cards</span>
                        <span class="dp-category-tag">Visa / MC</span>
                    </button>
                </div>
            </div>

            <!-- STEP 2: AMOUNT SELECTION -->
            <div class="dp-amount-section">
                <span class="text-xs font-extrabold text-slate-300 block uppercase tracking-wider">
                    2. Choose Deposit Amount
                </span>

                <div class="dp-quick-amounts-grid">
                    <button type="button" class="dp-amount-btn" data-amount-val="100">100 THB</button>
                    <button type="button" class="dp-amount-btn" data-amount-val="300">300 THB</button>
                    <button type="button" class="dp-amount-btn" data-amount-val="500">500 THB</button>
                    <button type="button" class="dp-amount-btn active" data-amount-val="1000">1,000 THB</button>
                    <button type="button" class="dp-amount-btn" data-amount-val="3000">3,000 THB</button>
                    <button type="button" class="dp-amount-btn" data-amount-val="5000">5,000 THB</button>
                    <button type="button" class="dp-amount-btn" data-amount-val="10000">10,000 THB</button>
                    <button type="button" class="dp-amount-btn" data-amount-val="50000">50,000 THB</button>
                </div>

                <!-- Custom Amount Input -->
                <div class="dp-custom-amount-box">
                    <span class="dp-currency-prefix">THB</span>
                    <input
                        type="text"
                        id="customAmountInput"
                        class="dp-amount-input"
                        value="1000"
                        placeholder="Enter custom deposit amount..."
                    >
                </div>
            </div>

            <!-- STEP 3: PAYMENT EXECUTION CHANNELS -->
            <div class="dp-channel-container">
                <span class="text-xs font-extrabold text-slate-300 block uppercase tracking-wider">
                    3. Payment Execution
                </span>

                <!-- Channel 1: PromptPay -->
                <div id="promptpaySection" class="dp-qr-card">
                    <div class="dp-qr-image-wrapper">
                        <!-- Dynamic PromptPay QR Vector -->
                        <svg class="w-48 h-48" viewBox="0 0 100 100" fill="none">
                            <rect width="100" height="100" fill="white" />
                            <rect x="10" y="10" width="25" height="25" fill="#064e3b" />
                            <rect x="15" y="15" width="15" height="15" fill="white" />
                            <rect x="18" y="18" width="9" height="9" fill="#064e3b" />
                            <rect x="65" y="10" width="25" height="25" fill="#064e3b" />
                            <rect x="70" y="15" width="15" height="15" fill="white" />
                            <rect x="73" y="18" width="9" height="9" fill="#064e3b" />
                            <rect x="10" y="65" width="25" height="25" fill="#064e3b" />
                            <rect x="15" y="70" width="15" height="15" fill="white" />
                            <rect x="18" y="73" width="9" height="9" fill="#064e3b" />
                            <rect x="42" y="10" width="16" height="80" fill="#064e3b" opacity="0.85" />
                        </svg>
                    </div>

                    <div class="dp-qr-timer-pill">
                        <span>⏱ QR Valid For:</span>
                        <span id="qrCountdownTimer" class="font-mono font-black">15:00</span>
                    </div>

                    <div class="text-xs text-slate-400">
                        Reference Code: <span id="promptpayRefCode" class="font-mono text-white font-bold">{{ $initialRef ?? 'PP-984210' }}</span>
                    </div>
                </div>

                <!-- Channel 2: Thai Banking -->
                <div id="bankingSection" class="space-y-3" style="display: none;">
                    <div class="dp-bank-item">
                        <div class="dp-bank-info">
                            <div class="dp-bank-logo dp-bank-scb">SCB</div>
                            <div>
                                <div class="text-xs font-bold text-white">Siam Commercial Bank</div>
                                <div class="font-mono text-xs text-slate-300" id="bankAccScb">408-123456-7 (Thai Lotto Co., Ltd.)</div>
                            </div>
                        </div>
                        <button type="button" class="dp-copy-btn" data-copy-target="bankAccScb">Copy</button>
                    </div>

                    <div class="dp-bank-item">
                        <div class="dp-bank-info">
                            <div class="dp-bank-logo dp-bank-kbank">KBANK</div>
                            <div>
                                <div class="text-xs font-bold text-white">Kasikornbank</div>
                                <div class="font-mono text-xs text-slate-300" id="bankAccKbank">789-987654-3 (Thai Lotto Co., Ltd.)</div>
                            </div>
                        </div>
                        <button type="button" class="dp-copy-btn" data-copy-target="bankAccKbank">Copy</button>
                    </div>

                    <!-- Slip Upload Zone -->
                    <div id="slipDropzone" class="dp-dropzone">
                        <span class="text-2xl">📄</span>
                        <span class="text-xs font-bold text-white">Click or Drop Bank Slip Here</span>
                        <span class="text-[10px] text-slate-500">Supports JPG, PNG (Automated Instant OCR Match)</span>
                        <input type="file" id="slipFileInput" accept="image/*" style="display: none;">
                    </div>
                </div>

                <!-- Channel 3: Crypto / USDT -->
                <div id="cryptoSection" class="space-y-3" style="display: none;">
                    <div class="dp-bank-item">
                        <div>
                            <div class="text-xs font-bold text-white">USDT Deposit Address (TRC-20)</div>
                            <div class="font-mono text-xs text-emerald-400" id="cryptoTrc20Addr">TQn9Y2khEsLJW1ChVWFMSMeRDow5KcbLSE</div>
                        </div>
                        <button type="button" class="dp-copy-btn" data-copy-target="cryptoTrc20Addr">Copy</button>
                    </div>
                    <div class="text-xs text-slate-400">
                        Exchange Rate: <span class="font-bold text-white">1 USDT ≈ 35.80 THB</span> | Confirmations: <span class="text-emerald-400 font-bold">12 Blocks</span>
                    </div>
                </div>

                <!-- Channel 4: TrueMoney MFS -->
                <div id="mfsSection" class="space-y-3" style="display: none;">
                    <div class="dp-bank-item">
                        <div>
                            <div class="text-xs font-bold text-white">TrueMoney Wallet Phone Number</div>
                            <div class="font-mono text-xs text-amber-400" id="mfsPhoneNo">081-234-5678</div>
                        </div>
                        <button type="button" class="dp-copy-btn" data-copy-target="mfsPhoneNo">Copy</button>
                    </div>
                    <div class="text-xs text-slate-400">
                        Direct PUSH top-up will be sent to your TrueMoney App immediately upon confirmation.
                    </div>
                </div>

                <!-- Channel 5: Credit Cards -->
                <div id="cardSection" class="space-y-3" style="display: none;">
                    <div class="bg-[#0c121d] border border-slate-700 rounded-xl p-4 space-y-3">
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1">Card Number</label>
                            <input type="text" placeholder="4242 •••• •••• 4242" class="w-full bg-[#172233] border border-slate-700 rounded-lg px-3 py-2 text-xs font-mono text-white outline-none">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-[11px] text-slate-400 block mb-1">Expiry Date</label>
                                <input type="text" placeholder="MM/YY" class="w-full bg-[#172233] border border-slate-700 rounded-lg px-3 py-2 text-xs font-mono text-white outline-none">
                            </div>
                            <div>
                                <label class="text-[11px] text-slate-400 block mb-1">CVC / CVV</label>
                                <input type="password" placeholder="•••" class="w-full bg-[#172233] border border-slate-700 rounded-lg px-3 py-2 text-xs font-mono text-white outline-none">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: ORDER SUMMARY & CONFIRMATION -->
        <aside class="dp-summary-card">
            <div>
                <h2 class="dp-summary-title">Deposit Summary</h2>

                <div class="dp-summary-rows">
                    <div class="dp-summary-row">
                        <span>Base Deposit:</span>
                        <span id="summaryBaseAmount" class="font-mono font-bold text-white">1,000 THB</span>
                    </div>
                    <div class="dp-summary-row">
                        <span>VIP Bonus (+10%):</span>
                        <span id="summaryBonusAmount" class="font-mono font-bold text-emerald-400">+100 THB</span>
                    </div>
                    <div class="dp-summary-row">
                        <span>Processing Fee:</span>
                        <span class="font-mono font-bold text-emerald-400">0.00 THB (Free)</span>
                    </div>
                    <div class="dp-summary-row total">
                        <span>Total Credited:</span>
                        <span id="summaryTotalCredited" class="font-mono text-emerald-400 text-lg">1,100 THB</span>
                    </div>
                </div>
            </div>

            <div class="pt-4">
                <button type="button" id="initiateDepositBtn" class="dp-confirm-btn">
                    Confirm &amp; Proceed to Pay
                </button>
            </div>
        </aside>
    </main>

    <!-- Browser Runtime Script -->
    <script src="{{ asset('js/deposit-portal.js') }}"></script>
</body>
</html>
