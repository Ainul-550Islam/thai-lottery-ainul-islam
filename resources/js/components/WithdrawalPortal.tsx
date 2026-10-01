import React, { useState } from 'react';

export const WithdrawalPortal: React.FC = () => {
    const [selectedCategory, setSelectedCategory] = useState<'banking' | 'promptpay' | 'crypto' | 'mfs' | 'vip'>('banking');
    const [amount, setAmount] = useState<number>(1000);
    const [availableBalance, setAvailableBalance] = useState<number>(5450.00);
    const [withdrawableBalance, setWithdrawableBalance] = useState<number>(5200.00);

    const remainingBalance = Math.max(0, withdrawableBalance - amount);

    const handleConfirm = () => {
        if (amount < 300) {
            alert('Minimum withdrawal amount is 300 THB.');
            return;
        }
        if (amount > withdrawableBalance) {
            alert(`Insufficient withdrawable balance (${withdrawableBalance.toLocaleString()} THB).`);
            return;
        }

        const pin = prompt('Security Verification: Enter your 4-digit PIN:');
        if (pin && pin.length >= 4) {
            setWithdrawableBalance((prev) => prev - amount);
            setAvailableBalance((prev) => prev - amount);
            alert(`Withdrawal of ${amount.toLocaleString()} THB via ${selectedCategory.toUpperCase()} approved.\nRef ID: WD-${Math.floor(100000 + Math.random() * 900000)}`);
        }
    };

    return (
        <div className="min-h-screen bg-[#0b0f17] text-slate-100 font-sans selection:bg-sky-500 selection:text-white">
            {/* TOP HEADER */}
            <header className="h-[72px] bg-[#0f1522] border-b border-[#1f2b3e] px-8 flex items-center justify-between sticky top-0 z-50">
                <a href="/withdrawal" className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-gradient-to-tr from-sky-500 to-indigo-500 flex items-center justify-center text-white font-black text-xl shadow-[0_0_15px_rgba(56,189,248,0.4)]">
                        💸
                    </div>
                    <div className="flex flex-col">
                        <span className="text-base font-black text-white tracking-wide">THAI LOTTO</span>
                        <span className="text-[10px] font-bold text-sky-400 uppercase tracking-widest">Withdrawal Portal</span>
                    </div>
                </a>

                <div className="flex items-center gap-4">
                    <div className="bg-[#08121a] border border-emerald-500 rounded-full px-4 py-1.5 text-xs font-black text-emerald-400 shadow-[0_0_12px_rgba(16,185,129,0.2)]">
                        Withdrawable: {withdrawableBalance.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} THB
                    </div>
                    <a href="/wallet" className="text-slate-400 hover:text-white text-xs font-bold transition">
                        Wallet Overview →
                    </a>
                </div>
            </header>

            {/* MAIN CONTAINER */}
            <main className="max-w-7xl mx-auto p-8 grid grid-cols-1 lg:grid-cols-12 gap-8">
                {/* LEFT WORKFLOW PANEL */}
                <div className="lg:col-span-8 space-y-6">
                    {/* SECURITY BANNER */}
                    <div className="bg-gradient-to-r from-[#081a28] via-[#0d1e2e] to-[#091a1e] border border-sky-500 rounded-2xl p-6 shadow-[0_0_25px_rgba(56,189,248,0.2)]">
                        <h1 className="text-xl font-black text-white">
                            Automated Instant Payouts <span>&lt; 60 Seconds</span>
                        </h1>
                        <p className="text-xs text-slate-400 mt-1">
                            Funds are transferred directly to your verified bank account, PromptPay, or crypto wallet with 0% withdrawal fee.
                        </p>
                        <div className="flex items-center gap-3 mt-4 text-xs font-bold">
                            <span className="bg-sky-500/15 border border-sky-500 text-sky-400 px-3 py-1 rounded-lg">
                                🔒 Bank Grade 2FA Protected
                            </span>
                            <span className="bg-emerald-500/15 border border-emerald-500 text-emerald-400 px-3 py-1 rounded-lg">
                                0% Payout Fee
                            </span>
                            <span className="bg-amber-500/15 border border-amber-500 text-amber-400 px-3 py-1 rounded-lg">
                                Daily Limit: 50,000 THB
                            </span>
                        </div>
                    </div>

                    {/* 5 WITHDRAWAL CATEGORIES */}
                    <div>
                        <span className="text-xs font-extrabold text-slate-300 block mb-3 uppercase tracking-wider">
                            1. Select Payout Method
                        </span>
                        <div className="grid grid-cols-5 gap-3">
                            {[
                                { key: 'banking', icon: '🏛', name: 'Thai Banking', tag: 'Direct Wire' },
                                { key: 'promptpay', icon: '📱', name: 'PromptPay', tag: 'Fastest' },
                                { key: 'crypto', icon: '₮', name: 'Crypto / USDT', tag: 'TRC-20' },
                                { key: 'mfs', icon: '👛', name: 'TrueMoney', tag: 'e-Wallet' },
                                { key: 'vip', icon: '👑', name: 'VIP High-Roller', tag: '> 100K' },
                            ].map((cat) => {
                                const isActive = selectedCategory === cat.key;
                                return (
                                    <button
                                        key={cat.key}
                                        onClick={() => setSelectedCategory(cat.key as any)}
                                        className={`py-3.5 px-2 rounded-xl flex flex-col items-center justify-center gap-1.5 border transition-all ${
                                            isActive
                                                ? 'bg-[#081a28] border-sky-500 text-white shadow-[0_0_18px_rgba(56,189,248,0.4)]'
                                                : 'bg-[#131b29] border-[#1f2b3e] text-slate-400 hover:bg-[#172233]'
                                        }`}
                                    >
                                        <span className="text-2xl">{cat.icon}</span>
                                        <span className="text-xs font-bold text-white text-center leading-tight">{cat.name}</span>
                                        <span className="text-[10px] font-bold text-sky-400 bg-sky-500/15 px-2 py-0.5 rounded">
                                            {cat.tag}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {/* AMOUNT SELECTION */}
                    <div className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-6 space-y-4">
                        <span className="text-xs font-extrabold text-slate-300 block uppercase tracking-wider">
                            2. Choose Withdrawal Amount
                        </span>

                        <div className="grid grid-cols-4 gap-3">
                            {[300, 500, 1000, 2000, 3000, 5000].map((amt) => (
                                <button
                                    key={amt}
                                    onClick={() => setAmount(amt)}
                                    className={`py-3 rounded-xl font-black text-sm border transition ${
                                        amount === amt
                                            ? 'bg-[#081a28] border-sky-500 text-sky-400 shadow-[0_0_14px_rgba(56,189,248,0.3)]'
                                            : 'bg-[#172233] border-[#1f2b3e] text-slate-300 hover:text-white'
                                    }`}
                                >
                                    {amt.toLocaleString()} THB
                                </button>
                            ))}
                            <button
                                onClick={() => setAmount(withdrawableBalance)}
                                className={`col-span-2 py-3 rounded-xl font-black text-sm border transition ${
                                    amount === withdrawableBalance
                                        ? 'bg-[#081a28] border-sky-500 text-sky-400 shadow-[0_0_14px_rgba(56,189,248,0.3)]'
                                        : 'bg-[#172233] border-[#1f2b3e] text-slate-300 hover:text-white'
                                }`}
                            >
                                All Withdrawable ({withdrawableBalance.toLocaleString()} THB)
                            </button>
                        </div>

                        {/* Custom Input */}
                        <div className="flex items-center bg-[#0c121d] border border-[#1f2b3e] rounded-xl px-4 py-3 gap-3 focus-within:border-sky-500">
                            <span className="text-sky-400 font-black text-base">THB</span>
                            <input
                                type="number"
                                value={amount}
                                onChange={(e) => setAmount(parseFloat(e.target.value) || 0)}
                                className="w-full bg-transparent border-none text-white font-mono font-bold text-lg outline-none"
                                placeholder="Enter custom withdrawal amount..."
                            />
                        </div>
                    </div>

                    {/* METHOD-SPECIFIC ACCOUNT DETAILS */}
                    <div className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-6 space-y-4">
                        <span className="text-xs font-extrabold text-slate-300 block uppercase tracking-wider">
                            3. Destination Account Details
                        </span>

                        {selectedCategory === 'banking' && (
                            <div className="bg-[#172233] border border-emerald-500 rounded-xl p-4 flex items-center justify-between">
                                <div className="flex items-center gap-3">
                                    <div className="w-10 h-10 rounded-xl bg-purple-700 text-white font-black flex items-center justify-center text-xs">
                                        SCB
                                    </div>
                                    <div>
                                        <div className="text-xs font-bold text-white">Siam Commercial Bank (Primary)</div>
                                        <div className="font-mono text-xs text-slate-300">Acc: •••• •••• 4210 (Alex Thompson)</div>
                                    </div>
                                </div>
                                <span className="bg-emerald-500/15 border border-emerald-500 text-emerald-400 text-[10px] font-black px-2.5 py-0.5 rounded">
                                    KYC Verified
                                </span>
                            </div>
                        )}

                        {selectedCategory === 'promptpay' && (
                            <div className="bg-[#172233] border border-sky-500 rounded-xl p-4 flex items-center justify-between">
                                <div>
                                    <div className="text-xs font-bold text-white">PromptPay ID (Registered Phone)</div>
                                    <div className="font-mono text-xs text-sky-400">081-•••-5678 (Alex Thompson)</div>
                                </div>
                                <span className="bg-sky-500/15 border border-sky-500 text-sky-400 text-[10px] font-black px-2.5 py-0.5 rounded">
                                    Instant Payout
                                </span>
                            </div>
                        )}

                        {selectedCategory === 'crypto' && (
                            <div className="space-y-2">
                                <label className="text-[11px] text-slate-400 block">Recipient USDT Address (TRC-20)</label>
                                <input
                                    type="text"
                                    defaultValue="TQn9Y2khEsLJW1ChVWFMSMeRDow5KcbLSE"
                                    className="w-full bg-[#172233] border border-slate-700 rounded-xl px-4 py-2.5 text-xs font-mono text-emerald-400 outline-none"
                                />
                                <span className="text-[10px] text-slate-500 block">
                                    Estimated Crypto: {(amount / 35.80).toFixed(2)} USDT (Rate: 1 USDT ≈ 35.80 THB)
                                </span>
                            </div>
                        )}
                    </div>
                </div>

                {/* RIGHT SUMMARY CARD */}
                <div className="lg:col-span-4 bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-6 flex flex-col justify-between shadow-2xl h-fit space-y-6">
                    <div>
                        <h2 className="text-base font-black text-white pb-3 border-b border-[#1f2b3e]">
                            Payout Summary
                        </h2>

                        <div className="space-y-3 mt-4 text-xs text-slate-400">
                            <div className="flex items-center justify-between">
                                <span>Requested Payout:</span>
                                <span className="font-mono font-bold text-white">{amount.toLocaleString()} THB</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span>Processing Fee:</span>
                                <span className="font-mono font-bold text-emerald-400">0.00 THB (Free)</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span>Estimated Arrival:</span>
                                <span className="font-bold text-sky-400">Instant (&lt; 60s)</span>
                            </div>
                            <div className="flex items-center justify-between pt-3 border-t border-slate-800 text-sm font-black text-white">
                                <span>Net Received:</span>
                                <span className="font-mono text-sky-400 text-base">{amount.toLocaleString()} THB</span>
                            </div>
                            <div className="flex items-center justify-between text-[11px] text-slate-500 pt-1">
                                <span>Remaining Balance:</span>
                                <span className="font-mono">{remainingBalance.toLocaleString()} THB</span>
                            </div>
                        </div>
                    </div>

                    <button
                        onClick={handleConfirm}
                        className="w-full bg-gradient-to-r from-sky-600 via-sky-500 to-indigo-600 text-white font-black text-sm py-3.5 px-4 rounded-xl shadow-[0_0_20px_rgba(56,189,248,0.45)] hover:shadow-[0_0_30px_rgba(56,189,248,0.6)] transition active:scale-95"
                    >
                        Confirm &amp; Withdraw Funds
                    </button>
                </div>
            </main>
        </div>
    );
};
