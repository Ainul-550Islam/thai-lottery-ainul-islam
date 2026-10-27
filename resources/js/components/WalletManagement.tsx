import React, { useState } from 'react';

export interface TransactionItem {
    id: string;
    refId: string;
    date: string;
    type: 'deposit' | 'wager' | 'payout';
    typeLabel: string;
    description: string;
    amount: string;
    fee: string;
    status: 'Completed' | 'Processing' | 'Failed';
}

export const WalletManagement: React.FC = () => {
    const [activeTab, setActiveTab] = useState<'deposit' | 'withdraw'>('deposit');
    const [selectedGateway, setSelectedGateway] = useState<string>('stripe');
    const [selectedAmount, setSelectedAmount] = useState<number>(500);
    const [selectedMethod, setSelectedMethod] = useState<string>('USDT');

    const [availableBalance, setAvailableBalance] = useState<number>(5450.00);
    const lockedBalance = 250.00;
    const lifetimeWinnings = 94800.00;

    const [transactions] = useState<TransactionItem[]>([
        {
            id: '1',
            refId: 'TX-29831',
            date: '26 Oct 2023 14:35',
            type: 'deposit',
            typeLabel: '+ Deposit',
            description: 'USDT Wallet Top-up',
            amount: '1,750.00',
            fee: '8.75',
            status: 'Completed',
        },
        {
            id: '2',
            refId: 'TX-29832',
            date: '26 Oct 2023 14:35',
            type: 'wager',
            typeLabel: '- Bet Wager',
            description: 'Draw #94 Entry',
            amount: '700.00',
            fee: '0.75',
            status: 'Processing',
        },
        {
            id: '3',
            refId: 'TX-29831',
            date: '26 Oct 2023 14:35',
            type: 'payout',
            typeLabel: '+ Prize Payout',
            description: 'Draw #93 Winnings',
            amount: '94,800.00',
            fee: '8.75',
            status: 'Completed',
        },
        {
            id: '4',
            refId: 'TX-29834',
            date: '26 Oct 2023 14:35',
            type: 'wager',
            typeLabel: '- Bet Wager',
            description: 'Draw #93 Entry',
            amount: '300.00',
            fee: '0.75',
            status: 'Completed',
        },
    ]);

    const handleProceed = () => {
        const input = prompt(`Enter ${activeTab} amount in THB:`, selectedAmount.toString());
        if (input) {
            const parsed = parseFloat(input);
            if (!isNaN(parsed) && parsed > 0) {
                if (activeTab === 'deposit') {
                    setAvailableBalance((prev) => prev + parsed);
                    alert(`Deposit of ${parsed.toLocaleString()} THB via ${selectedGateway.toUpperCase()} successful.\nRef ID: TX-${Math.floor(10000 + Math.random() * 90000)}`);
                } else {
                    if (parsed > availableBalance) {
                        alert('Insufficient available balance.');
                        return;
                    }
                    setAvailableBalance((prev) => prev - parsed);
                    alert(`Withdrawal of ${parsed.toLocaleString()} THB requested successfully.`);
                }
            }
        }
    };

    return (
        <div className="min-h-screen bg-[#0d121b] text-slate-100 font-sans selection:bg-amber-500 selection:text-white">
            {/* Window Traffic Dots */}
            <div className="flex items-center gap-2 px-6 pt-3">
                <span className="w-3 h-3 rounded-full bg-rose-500"></span>
                <span className="w-3 h-3 rounded-full bg-amber-500"></span>
                <span className="w-3 h-3 rounded-full bg-emerald-500"></span>
            </div>

            {/* TOP HEADER */}
            <header className="h-[70px] bg-[#101622] border-b border-[#1f2b3e] px-8 flex items-center justify-between sticky top-0 z-50">
                <div className="flex items-center gap-10">
                    <a href="/wallet" className="flex items-center gap-2 text-amber-500 font-black text-xl tracking-wide">
                        <span>🪷</span>
                        <span>FortuneLotto</span>
                    </a>

                    <nav className="flex items-center gap-6 text-sm text-slate-400 font-semibold">
                        <a href="/dashboard" className="flex items-center gap-1.5 hover:text-white transition">
                            <span>⊞</span>
                            <span>Dashboard</span>
                        </a>
                        <a href="#tickets" className="flex items-center gap-1.5 hover:text-white transition">
                            <span>🎫</span>
                            <span>My Tickets</span>
                        </a>
                        <a href="/wallet" className="flex items-center gap-1.5 text-amber-500 font-bold relative py-2 after:content-[''] after:absolute after:-bottom-4.5 after:left-0 after:right-0 after:h-0.5 after:bg-amber-500 after:shadow-[0_0_8px_rgba(245,158,11,0.8)]">
                            <span>👛</span>
                            <span>Wallet</span>
                        </a>
                        <a href="/betting" className="flex items-center gap-1.5 hover:text-white transition">
                            <span>🌐</span>
                            <span>Lottery</span>
                        </a>
                        <a href="/player/security" className="flex items-center gap-1.5 hover:text-white transition">
                            <span>👤</span>
                            <span>Profile</span>
                        </a>
                    </nav>
                </div>

                <div className="flex items-center gap-6">
                    <div className="flex items-center gap-2 text-sm font-bold text-white">
                        <div className="w-8 h-8 rounded-full overflow-hidden border border-amber-500">
                            <img
                                src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=100&auto=format&fit=crop&q=80"
                                alt="User Avatar"
                                className="w-full h-full object-cover"
                            />
                        </div>
                        <span>Arjun J.</span>
                        <span className="text-xs text-slate-500">▼</span>
                    </div>

                    <a href="/player/security" className="flex items-center gap-1.5 text-slate-400 hover:text-white text-sm font-semibold transition">
                        <span>⚙</span>
                        <span>Settings</span>
                    </a>
                </div>
            </header>

            {/* MAIN CONTAINER */}
            <main className="max-w-7xl mx-auto p-8 space-y-8">
                {/* TITLE */}
                <div>
                    <h1 className="text-2xl font-black text-amber-500">Wallet Management</h1>
                    <span className="text-xs font-semibold text-slate-400">Overview</span>
                </div>

                {/* 3 STAT CARDS */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Card 1: Available Balance */}
                    <div className="bg-[#131a27] border-1.5 border-cyan-500 rounded-2xl p-6 flex items-center justify-between shadow-[0_0_20px_rgba(6,182,212,0.25)]">
                        <div className="space-y-1">
                            <span className="text-xs font-bold text-slate-300">Available Balance</span>
                            <div className="text-2xl font-black text-white font-mono">
                                {availableBalance.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} THB
                            </div>
                            <span className="text-[11px] text-slate-400">Primary Funds for Play</span>
                        </div>
                        <div className="w-12 h-12 rounded-xl bg-cyan-500/15 border border-cyan-500 flex items-center justify-center text-cyan-400 text-xl">
                            👛
                        </div>
                    </div>

                    {/* Card 2: Locked In-Play */}
                    <div className="bg-[#131a27] border-1.5 border-amber-500 rounded-2xl p-6 flex items-center justify-between shadow-[0_0_20px_rgba(245,158,11,0.25)]">
                        <div className="space-y-1">
                            <span className="text-xs font-bold text-slate-300">Locked In-Play</span>
                            <div className="text-2xl font-black text-white font-mono">
                                {lockedBalance.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} THB
                            </div>
                            <span className="text-[11px] text-slate-400">Active Tickets in Draws</span>
                        </div>
                        <div className="w-12 h-12 rounded-xl bg-amber-500/15 border border-amber-500 flex items-center justify-center text-amber-400 text-xl">
                            🔒
                        </div>
                    </div>

                    {/* Card 3: Lifetime Winnings */}
                    <div className="bg-[#131a27] border-1.5 border-emerald-500 rounded-2xl p-6 flex items-center justify-between shadow-[0_0_20px_rgba(16,185,129,0.25)]">
                        <div className="space-y-1">
                            <span className="text-xs font-bold text-slate-300">Lifetime Winnings</span>
                            <div className="text-2xl font-black text-white font-mono">
                                {lifetimeWinnings.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} THB
                            </div>
                            <span className="text-[11px] text-slate-400">Total Prize Money Won</span>
                        </div>
                        <div className="w-12 h-12 rounded-xl bg-emerald-500/15 border border-emerald-500 flex items-center justify-center text-emerald-400 text-xl">
                            🏆
                        </div>
                    </div>
                </div>

                {/* DEPOSIT & WITHDRAW SECTION */}
                <div className="space-y-4">
                    <h2 className="text-lg font-extrabold text-white">Deposit &amp; Withdraw</h2>

                    {/* Tabs */}
                    <div className="flex gap-6 border-b border-[#1f2b3e] pb-2 text-sm font-bold">
                        <button
                            onClick={() => setActiveTab('deposit')}
                            className={`pb-1 relative transition ${activeTab === 'deposit' ? 'text-amber-500 after:content-[\'\'] after:absolute after:-bottom-2.5 after:left-0 after:right-0 after:h-0.5 after:bg-amber-500' : 'text-slate-400 hover:text-white'}`}
                        >
                            Deposit
                        </button>
                        <button
                            onClick={() => setActiveTab('withdraw')}
                            className={`pb-1 relative transition ${activeTab === 'withdraw' ? 'text-amber-500 after:content-[\'\'] after:absolute after:-bottom-2.5 after:left-0 after:right-0 after:h-0.5 after:bg-amber-500' : 'text-slate-400 hover:text-white'}`}
                        >
                            Withdraw
                        </button>
                    </div>

                    {/* Payment Gateways Bar */}
                    <div className="bg-[#131a27] border border-[#1f2b3e] rounded-2xl p-5 flex items-center justify-between gap-4 flex-wrap">
                        {/* Gateways */}
                        <div className="flex items-center gap-3">
                            {[
                                { key: 'stripe', name: 'Stripe', logo: 'stripe', sub: 'Stripe' },
                                { key: 'bkash', name: 'bKash', logo: '🦩', sub: 'bKash' },
                                { key: 'nagad', name: 'Nagad', logo: '🔥', sub: 'Nagad' },
                                { key: 'usdt', name: 'USDT (TRC-20)', logo: '₮', sub: 'USDT (TRC-20)' },
                                { key: 'thai_wire', name: 'Thai Bank Wire', logo: '🏛', sub: 'Thai Bank Wire' },
                            ].map((gw) => (
                                <button
                                    key={gw.key}
                                    onClick={() => setSelectedGateway(gw.key)}
                                    className={`px-3 py-2 rounded-xl flex flex-col items-center justify-center min-w-[76px] h-14 transition ${
                                        selectedGateway === gw.key
                                            ? 'bg-[#1b2433] border-1.5 border-amber-500 shadow-[0_0_12px_rgba(245,158,11,0.3)]'
                                            : 'bg-[#172132] border border-[#1f2b3e] text-slate-400 hover:bg-[#1d2b40]'
                                    }`}
                                >
                                    <span className="font-bold text-xs text-white">{gw.logo}</span>
                                    <span className="text-[10px] text-slate-400 mt-0.5">{gw.sub}</span>
                                </button>
                            ))}
                        </div>

                        {/* Amount presets */}
                        <div className="space-y-1">
                            <span className="text-[11px] text-slate-400 block font-semibold">Amount</span>
                            <div className="flex items-center gap-2">
                                {[500, 1000].map((amt) => (
                                    <button
                                        key={amt}
                                        onClick={() => setSelectedAmount(amt)}
                                        className={`px-3 py-1.5 rounded-lg text-xs font-bold transition ${
                                            selectedAmount === amt
                                                ? 'bg-[#1e2c42] border border-amber-500 text-white'
                                                : 'bg-[#172132] border border-[#1f2b3e] text-slate-300 hover:bg-[#1e2c42]'
                                        }`}
                                    >
                                        +{amt} THB
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Payment Method Select */}
                        <div className="space-y-1">
                            <span className="text-[11px] text-slate-400 block font-semibold">Payment Method</span>
                            <select
                                value={selectedMethod}
                                onChange={(e) => setSelectedMethod(e.target.value)}
                                className="bg-[#172132] border border-[#1f2b3e] rounded-xl px-4 py-2 text-xs font-bold text-white outline-none cursor-pointer"
                            >
                                <option value="USDT">USDT</option>
                                <option value="Credit Card">Credit Card</option>
                                <option value="PromptPay">PromptPay</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                            </select>
                        </div>

                        {/* CTA Proceed Button */}
                        <div className="space-y-1">
                            <span className="text-[11px] text-slate-400 block font-semibold">
                                {activeTab === 'deposit' ? 'Proceed to Deposit' : 'Proceed to Withdraw'}
                            </span>
                            <button
                                onClick={handleProceed}
                                className="bg-gradient-to-r from-amber-500 to-emerald-500 hover:from-amber-400 hover:to-emerald-400 text-slate-950 font-black text-xs py-2.5 px-6 rounded-xl shadow-[0_0_20px_rgba(16,185,129,0.4)] transition active:scale-95"
                            >
                                {activeTab === 'deposit' ? 'Proceed to Deposit' : 'Proceed to Withdraw'}
                            </button>
                        </div>
                    </div>
                </div>

                {/* RECENT TRANSACTIONS TABLE */}
                <div className="space-y-4">
                    <h2 className="text-lg font-extrabold text-white">Recent Transactions</h2>

                    <div className="bg-[#131a27] border border-[#1f2b3e] rounded-2xl overflow-hidden shadow-2xl">
                        <table className="w-full text-left text-xs">
                            <thead className="bg-[#0f1622] text-slate-400 font-bold border-b border-[#1f2b3e]">
                                <tr>
                                    <th className="py-3.5 px-5">Ref ID</th>
                                    <th className="py-3.5 px-5">Date</th>
                                    <th className="py-3.5 px-5">Type</th>
                                    <th className="py-3.5 px-5">Description</th>
                                    <th className="py-3.5 px-5">Amount (THB)</th>
                                    <th className="py-3.5 px-5">Fee</th>
                                    <th className="py-3.5 px-5">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[#162030] text-slate-300">
                                {transactions.map((tx) => (
                                    <tr key={tx.id} className="hover:bg-[#172132]/60 transition">
                                        <td className="py-3.5 px-5 font-mono font-bold text-slate-200">{tx.refId}</td>
                                        <td className="py-3.5 px-5 text-slate-400">{tx.date}</td>
                                        <td className="py-3.5 px-5 font-bold">
                                            <span className={tx.type === 'wager' ? 'text-amber-400' : 'text-emerald-400'}>
                                                {tx.typeLabel}
                                            </span>
                                        </td>
                                        <td className="py-3.5 px-5">{tx.description}</td>
                                        <td className="py-3.5 px-5 font-mono font-bold text-white">{tx.amount}</td>
                                        <td className="py-3.5 px-5 font-mono text-slate-400">{tx.fee}</td>
                                        <td className="py-3.5 px-5">
                                            {tx.status === 'Completed' ? (
                                                <span className="bg-emerald-500/15 border border-emerald-500 text-emerald-400 text-[10px] font-black px-2.5 py-0.5 rounded-full">
                                                    Completed
                                                </span>
                                            ) : (
                                                <span className="bg-amber-500/15 border border-amber-500 text-amber-400 text-[10px] font-black px-2.5 py-0.5 rounded-full">
                                                    Processing
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    <div className="flex items-center justify-between text-xs text-slate-400 pt-2">
                        <span>Showing 1-10 of 312</span>
                        <div className="flex items-center gap-1.5">
                            <button className="px-2.5 py-1 rounded bg-[#172132] border border-[#1f2b3e] hover:text-white">Prev</button>
                            <button className="px-2.5 py-1 rounded bg-blue-600 text-white font-bold">1</button>
                            <button className="px-2.5 py-1 rounded bg-[#172132] border border-[#1f2b3e] hover:text-white">2</button>
                            <button className="px-2.5 py-1 rounded bg-[#172132] border border-[#1f2b3e] hover:text-white">3</button>
                            <span>...</span>
                            <button className="px-2.5 py-1 rounded bg-[#172132] border border-[#1f2b3e] hover:text-white">32</button>
                            <button className="px-2.5 py-1 rounded bg-[#172132] border border-[#1f2b3e] hover:text-white">Next</button>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    );
};
