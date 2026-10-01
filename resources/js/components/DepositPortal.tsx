import React, { useState, useEffect } from 'react';

export const DepositPortal: React.FC = () => {
    const [selectedCategory, setSelectedCategory] = useState<'promptpay' | 'banking' | 'crypto' | 'mfs' | 'card'>('promptpay');
    const [amount, setAmount] = useState<number>(1000);
    const [bonusRate] = useState<number>(0.10);
    const [secondsLeft, setSecondsLeft] = useState<number>(900);
    const [refCode] = useState<string>('PP-984210');

    useEffect(() => {
        const timer = setInterval(() => {
            setSecondsLeft((prev) => (prev > 0 ? prev - 1 : 0));
        }, 1000);
        return () => clearInterval(timer);
    }, []);

    const bonusAmount = Math.round(amount * bonusRate);
    const totalCredited = amount + bonusAmount;

    const formatTime = (secs: number) => {
        const m = Math.floor(secs / 60);
        const s = secs % 60;
        const pad = (n: number) => n.toString().padStart(2, '0');
        return `${pad(m)}:${pad(s)}`;
    };

    const handleCopy = (text: string) => {
        navigator.clipboard.writeText(text);
        alert('Copied to clipboard: ' + text);
    };

    const handleConfirmDeposit = () => {
        alert(`Deposit request for ${amount.toLocaleString()} THB via ${selectedCategory.toUpperCase()} submitted.\nRef Code: ${refCode}`);
    };

    return (
        <div className="min-h-screen bg-[#0b0f17] text-slate-100 font-sans selection:bg-emerald-500 selection:text-white">
            {/* TOP HEADER */}
            <header className="h-[72px] bg-[#0f1522] border-b border-[#1f2b3e] px-8 flex items-center justify-between sticky top-0 z-50">
                <a href="/deposit" className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-gradient-to-tr from-emerald-500 to-teal-400 flex items-center justify-center text-slate-950 font-black text-xl shadow-[0_0_15px_rgba(16,185,129,0.4)]">
                        ⚡
                    </div>
                    <div className="flex flex-col">
                        <span className="text-base font-black text-white tracking-wide">THAI LOTTO</span>
                        <span className="text-[10px] font-bold text-emerald-400 uppercase tracking-widest">Deposit Gateway</span>
                    </div>
                </a>

                <div className="flex items-center gap-6">
                    <div className="bg-[#08120e] border border-emerald-500 rounded-full px-5 py-2 text-xs font-black text-emerald-400 shadow-[0_0_15px_rgba(16,185,129,0.25)] flex items-center gap-2">
                        <span className="text-slate-400 font-normal">Wallet Balance:</span>
                        <span>5,450.00 THB</span>
                    </div>

                    <a href="/wallet" className="text-slate-400 hover:text-white text-xs font-bold transition">
                        Transaction History →
                    </a>
                </div>
            </header>

            {/* MAIN CONTENT CONTAINER */}
            <main className="max-w-7xl mx-auto p-8 grid grid-cols-1 lg:grid-cols-12 gap-8">
                {/* LEFT WORKFLOW PANEL */}
                <div className="lg:col-span-8 space-y-6">
                    {/* PROMO BANNER */}
                    <div className="bg-gradient-to-r from-[#06241a] via-[#0d1e2e] to-[#151128] border border-emerald-500 rounded-2xl p-6 shadow-[0_0_25px_rgba(16,185,129,0.2)]">
                        <h1 className="text-xl font-black text-white">
                            Instant Deposit &amp; <span className="text-amber-400">+10% Bonus</span> Active!
                        </h1>
                        <p className="text-xs text-slate-400 mt-1">
                            Choose from 5 direct deposit methods with 0% transaction fee and instant automated crediting.
                        </p>
                        <div className="flex items-center gap-3 mt-4 text-xs font-bold">
                            <span className="bg-emerald-500/15 border border-emerald-500 text-emerald-400 px-3 py-1 rounded-lg">
                                ⚡ Instant &lt; 30s
                            </span>
                            <span className="bg-amber-500/15 border border-amber-500 text-amber-400 px-3 py-1 rounded-lg">
                                0% Processing Fee
                            </span>
                            <span className="bg-sky-500/15 border border-sky-500 text-sky-400 px-3 py-1 rounded-lg">
                                256-Bit SSL Encrypted
                            </span>
                        </div>
                    </div>

                    {/* 5 DEPOSIT CATEGORIES */}
                    <div>
                        <span className="text-xs font-extrabold text-slate-300 block mb-3 uppercase tracking-wider">
                            1. Select Deposit Method
                        </span>
                        <div className="grid grid-cols-5 gap-3">
                            {[
                                { key: 'promptpay', icon: '📱', name: 'PromptPay QR', tag: 'Fastest' },
                                { key: 'banking', icon: '🏛', name: 'Thai Banking', tag: 'Bank Wire' },
                                { key: 'crypto', icon: '₮', name: 'Crypto / USDT', tag: 'TRC-20' },
                                { key: 'mfs', icon: '👛', name: 'TrueMoney / MFS', tag: 'e-Wallet' },
                                { key: 'card', icon: '💳', name: 'Credit Cards', tag: 'Visa / MC' },
                            ].map((cat) => {
                                const isActive = selectedCategory === cat.key;
                                return (
                                    <button
                                        key={cat.key}
                                        onClick={() => setSelectedCategory(cat.key as any)}
                                        className={`py-3.5 px-2 rounded-xl flex flex-col items-center justify-center gap-1.5 border transition-all ${
                                            isActive
                                                ? 'bg-[#0d1e19] border-emerald-500 text-white shadow-[0_0_18px_rgba(16,185,129,0.4)]'
                                                : 'bg-[#131b29] border-[#1f2b3e] text-slate-400 hover:bg-[#172233]'
                                        }`}
                                    >
                                        <span className="text-2xl">{cat.icon}</span>
                                        <span className="text-xs font-bold text-white text-center leading-tight">{cat.name}</span>
                                        <span className="text-[10px] font-bold text-emerald-400 bg-emerald-500/15 px-2 py-0.5 rounded">
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
                            2. Choose Deposit Amount
                        </span>

                        <div className="grid grid-cols-4 gap-3">
                            {[100, 300, 500, 1000, 3000, 5000, 10000, 50000].map((amt) => (
                                <button
                                    key={amt}
                                    onClick={() => setAmount(amt)}
                                    className={`py-3 rounded-xl font-black text-sm border transition ${
                                        amount === amt
                                            ? 'bg-[#0d1e19] border-emerald-500 text-emerald-400 shadow-[0_0_14px_rgba(16,185,129,0.3)]'
                                            : 'bg-[#172233] border-[#1f2b3e] text-slate-300 hover:text-white'
                                    }`}
                                >
                                    {amt.toLocaleString()} THB
                                </button>
                            ))}
                        </div>

                        {/* Custom Input */}
                        <div className="flex items-center bg-[#0c121d] border border-[#1f2b3e] rounded-xl px-4 py-3 gap-3 focus-within:border-emerald-500">
                            <span className="text-emerald-400 font-black text-base">THB</span>
                            <input
                                type="number"
                                value={amount}
                                onChange={(e) => setAmount(parseFloat(e.target.value) || 0)}
                                className="w-full bg-transparent border-none text-white font-mono font-bold text-lg outline-none"
                                placeholder="Enter custom amount..."
                            />
                        </div>
                    </div>

                    {/* METHOD-SPECIFIC DISPLAY */}
                    <div className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-6 space-y-4">
                        <span className="text-xs font-extrabold text-slate-300 block uppercase tracking-wider">
                            3. Payment Execution
                        </span>

                        {selectedCategory === 'promptpay' && (
                            <div className="bg-[#0c121d] border border-emerald-500 rounded-2xl p-6 flex flex-col items-center gap-4 text-center">
                                <div className="bg-white p-3 rounded-2xl inline-flex items-center justify-center shadow-2xl">
                                    <svg className="w-48 h-48" viewBox="0 0 100 100" fill="none">
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
                                        <rect x="42" y="10" width="15" height="80" fill="#064e3b" opacity="0.85" />
                                    </svg>
                                </div>

                                <div className="bg-amber-500/15 border border-amber-500 text-amber-400 text-xs font-black px-4 py-1.5 rounded-full">
                                    ⏱ QR Valid For: {formatTime(secondsLeft)}
                                </div>

                                <div className="text-xs text-slate-400">
                                    Ref: <span className="font-mono text-white font-bold">{refCode}</span>
                                </div>
                            </div>
                        )}

                        {selectedCategory === 'banking' && (
                            <div className="space-y-3">
                                <div className="bg-[#172233] border border-[#1f2b3e] rounded-xl p-4 flex items-center justify-between">
                                    <div className="flex items-center gap-3">
                                        <div className="w-10 h-10 rounded-xl bg-purple-700 text-white font-black flex items-center justify-center text-xs">
                                            SCB
                                        </div>
                                        <div>
                                            <div className="text-xs font-bold text-white">Siam Commercial Bank</div>
                                            <div className="font-mono text-xs text-slate-300">Acc: 408-123456-7 (Thai Lotto Co.)</div>
                                        </div>
                                    </div>
                                    <button
                                        onClick={() => handleCopy('408-123456-7')}
                                        className="bg-[#1f2b3e] hover:bg-[#334155] text-xs font-bold px-3 py-1.5 rounded-lg text-slate-200"
                                    >
                                        Copy
                                    </button>
                                </div>

                                {/* Slip upload dropzone */}
                                <div className="border-2 border-dashed border-slate-700 hover:border-emerald-500 rounded-xl p-6 text-center cursor-pointer transition">
                                    <span className="text-2xl block mb-1">📄</span>
                                    <span className="text-xs font-bold text-white block">Upload Bank Transfer Slip</span>
                                    <span className="text-[10px] text-slate-500">Supports JPG, PNG (Instant Verification)</span>
                                </div>
                            </div>
                        )}

                        {selectedCategory === 'crypto' && (
                            <div className="space-y-3">
                                <div className="bg-[#172233] border border-[#1f2b3e] rounded-xl p-4 flex items-center justify-between">
                                    <div>
                                        <div className="text-xs font-bold text-white">USDT Deposit Address (TRC-20)</div>
                                        <div className="font-mono text-xs text-emerald-400">TQn9Y2khEsLJW1ChVWFMSMeRDow5KcbLSE</div>
                                    </div>
                                    <button
                                        onClick={() => handleCopy('TQn9Y2khEsLJW1ChVWFMSMeRDow5KcbLSE')}
                                        className="bg-[#1f2b3e] hover:bg-[#334155] text-xs font-bold px-3 py-1.5 rounded-lg text-slate-200"
                                    >
                                        Copy
                                    </button>
                                </div>
                                <div className="text-xs text-slate-400">
                                    Rate: <span className="font-bold text-white">1 USDT ≈ 35.80 THB</span> | Amount: <span className="font-bold text-emerald-400">{(amount / 35.80).toFixed(2)} USDT</span>
                                </div>
                            </div>
                        )}
                    </div>
                </div>

                {/* RIGHT ORDER SUMMARY PANEL */}
                <div className="lg:col-span-4 bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-6 flex flex-col justify-between shadow-2xl h-fit space-y-6">
                    <div>
                        <h2 className="text-base font-black text-white pb-3 border-b border-[#1f2b3e]">
                            Deposit Summary
                        </h2>

                        <div className="space-y-3 mt-4 text-xs text-slate-400">
                            <div className="flex items-center justify-between">
                                <span>Deposit Amount:</span>
                                <span className="font-mono font-bold text-white">{amount.toLocaleString()} THB</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span>VIP Bonus (+10%):</span>
                                <span className="font-mono font-bold text-emerald-400">+{bonusAmount.toLocaleString()} THB</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span>Processing Fee:</span>
                                <span className="font-mono font-bold text-emerald-400">0.00 THB (Free)</span>
                            </div>
                            <div className="flex items-center justify-between pt-3 border-t border-slate-800 text-sm font-black text-white">
                                <span>Total Credited:</span>
                                <span className="font-mono text-emerald-400 text-base">{totalCredited.toLocaleString()} THB</span>
                            </div>
                        </div>
                    </div>

                    <button
                        onClick={handleConfirmDeposit}
                        className="w-full bg-gradient-to-r from-emerald-500 via-teal-400 to-emerald-500 text-slate-950 font-black text-sm py-3.5 px-4 rounded-xl shadow-[0_0_20px_rgba(16,185,129,0.45)] hover:shadow-[0_0_30px_rgba(16,185,129,0.6)] transition active:scale-95"
                    >
                        Confirm &amp; Proceed to Pay
                    </button>
                </div>
            </main>
        </div>
    );
};
