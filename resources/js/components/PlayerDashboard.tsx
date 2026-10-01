import React, { useState, useEffect } from 'react';

export interface WagerRecord {
    id: string;
    ticketId: string;
    date: string;
    numbers: string;
    type: string;
    amount: string;
    status: 'WON' | 'PENDING' | 'LOST';
    isWinnerMatch?: boolean;
}

export const PlayerDashboard: React.FC = () => {
    const [secondsLeft, setSecondsLeft] = useState<number>(14 * 3600 + 48 * 60 + 2);
    const [wagers, setWagers] = useState<WagerRecord[]>([
        {
            id: '1',
            ticketId: 'TKT-B98103',
            date: 'Jul 15, 14:12',
            numbers: '724605',
            type: 'Straight x5',
            amount: '500.00 THB',
            status: 'WON',
            isWinnerMatch: true,
        },
        {
            id: '2',
            ticketId: 'TKT-A72460',
            date: 'Jul 15, 14:12',
            numbers: '183922',
            type: '3D Direct',
            amount: '100.00 THB',
            status: 'PENDING',
        },
        {
            id: '3',
            ticketId: 'TKT-E05122',
            date: 'Jul 15, 14:12',
            numbers: '052187',
            type: '2D Rear',
            amount: '250.00 THB',
            status: 'LOST',
        },
        {
            id: '4',
            ticketId: 'TKT-C34991',
            date: 'Jul 15, 14:12',
            numbers: '440911',
            type: 'Straight x2',
            amount: '200.00 THB',
            status: 'WON',
        },
    ]);

    useEffect(() => {
        const timer = setInterval(() => {
            setSecondsLeft((prev) => (prev > 0 ? prev - 1 : 0));
        }, 1000);
        return () => clearInterval(timer);
    }, []);

    const formatTimer = (totalSecs: number) => {
        const h = Math.floor(totalSecs / 3600);
        const m = Math.floor((totalSecs % 3600) / 60);
        const s = totalSecs % 60;
        const pad = (n: number) => n.toString().padStart(2, '0');
        return `${pad(h)}:${pad(m)}:${pad(s)}`;
    };

    const handleCancel = (ticketId: string) => {
        if (confirm(`Are you sure you want to cancel ticket ${ticketId}?`)) {
            setWagers((prev) => prev.filter((w) => w.ticketId !== ticketId));
            alert(`Ticket ${ticketId} cancelled. Refund credited to wallet.`);
        }
    };

    return (
        <div className="min-h-screen bg-[#0b1017] text-slate-100 font-sans selection:bg-emerald-500 selection:text-white">
            {/* TOP HEADER */}
            <header className="h-[70px] bg-[#0e141e] border-b border-[#1f2b3e] px-8 flex items-center justify-between sticky top-0 z-50">
                <div className="flex items-center gap-10">
                    {/* Brand */}
                    <a href="/dashboard" className="flex items-center gap-3">
                        <div className="w-10 h-10 bg-radial from-[#2d2305] to-[#151102] border border-[#eab308] [clip-path:polygon(50%_0%,93%_25%,93%_75%,50%_100%,7%_75%,7%_25%)] flex items-center justify-center text-amber-400 font-black text-lg shadow-[0_0_10px_rgba(234,179,8,0.3)]">
                            🐘
                        </div>
                        <div className="flex flex-col">
                            <span className="text-sm font-black text-amber-400 tracking-widest uppercase leading-none">Thai</span>
                            <span className="text-xs font-bold text-slate-200 tracking-wider uppercase leading-tight">Lottery</span>
                        </div>
                    </a>

                    {/* Nav Links */}
                    <nav className="flex items-center gap-7">
                        <a href="/dashboard" className="text-emerald-400 font-bold text-sm relative py-2 after:content-[''] after:absolute after:-bottom-4.5 after:left-0 after:right-0 after:h-1 after:bg-emerald-500 after:rounded-full after:shadow-[0_0_8px_rgba(16,185,129,0.8)]">
                            Dashboard
                        </a>
                        <a href="/betting" className="text-slate-400 hover:text-white font-semibold text-sm transition">
                            Bet Slip
                        </a>
                        <a href="/results" className="text-slate-400 hover:text-white font-semibold text-sm transition">
                            Results
                        </a>
                        <a href="#history" className="text-slate-400 hover:text-white font-semibold text-sm transition">
                            History
                        </a>
                        <a href="#payments" className="text-slate-400 hover:text-white font-semibold text-sm transition">
                            Payments
                        </a>
                        <a href="/player/security" className="text-slate-400 hover:text-white font-semibold text-sm transition">
                            Account
                        </a>
                    </nav>
                </div>

                {/* Right Profile & Wallet */}
                <div className="flex items-center gap-4">
                    <div className="relative w-9 h-9 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center font-bold text-xs text-white">
                        SK
                        <span className="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-emerald-500 border-2 border-[#0e141e]"></span>
                    </div>

                    <button className="relative w-9 h-9 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center text-slate-400 hover:text-white transition">
                        🔔
                        <span className="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-emerald-500"></span>
                    </button>

                    <div className="bg-[#08120e] border border-emerald-500 rounded-full px-4 py-1.5 text-xs font-black text-amber-400 shadow-[0_0_12px_rgba(16,185,129,0.25)] flex items-center gap-1">
                        <span>**5,450.00 THB</span>
                    </div>
                </div>
            </header>

            {/* MAIN CONTENT */}
            <main className="max-w-7xl mx-auto p-8 space-y-8">
                {/* WELCOME BANNER */}
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-black text-white">
                        Welcome back, <span className="text-amber-400">Sorn!</span>
                    </h1>

                    <button
                        onClick={() => alert('Deposit modal opened. Fast QR PromptPay available.')}
                        className="bg-[#061f16] hover:bg-[#0a2d21] border-1.5 border-emerald-500 rounded-full px-5 py-2 text-xs font-extrabold text-white shadow-[0_0_15px_rgba(16,185,129,0.35)] flex items-center gap-2 transition active:scale-95"
                    >
                        <span>Wallet Balance 🪙</span>
                        <span className="text-emerald-400">+ Deposit</span>
                    </button>
                </div>

                {/* HERO 2 CARDS */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    {/* LEFT CARD: Countdown */}
                    <div className="lg:col-span-5 bg-[#121926] border border-[#1f2b3e] rounded-2xl p-6 flex flex-col justify-between shadow-2xl">
                        <div>
                            <div className="flex items-center justify-between">
                                <span className="text-base font-black text-white">Draw #128</span>
                                <span className="bg-emerald-500/15 border border-emerald-500 text-emerald-400 text-[10px] font-black px-2.5 py-0.5 rounded-lg tracking-wider">
                                    OPEN
                                </span>
                            </div>

                            <div className="text-4xl font-black text-amber-400 tracking-wider font-mono my-4 drop-shadow-[0_0_15px_rgba(250,204,21,0.4)]">
                                {formatTimer(secondsLeft)}
                            </div>

                            <div className="flex gap-7 text-xs text-slate-400 font-semibold mb-4">
                                <span>Hours</span>
                                <span>Mins</span>
                                <span>Secs</span>
                            </div>

                            <p className="text-xs text-slate-300">
                                Next Draw: July 16, 2024, 4:00 PM ICT
                            </p>
                        </div>

                        <div className="flex gap-3 mt-6">
                            <a
                                href="/betting"
                                className="flex-1 bg-emerald-500 hover:bg-emerald-400 text-emerald-950 font-black text-xs py-3 rounded-xl text-center shadow-[0_0_15px_rgba(16,185,129,0.4)] transition"
                            >
                                Wager Now
                            </a>
                            <a
                                href="#history"
                                className="flex-1 bg-[#172133] hover:bg-[#1e2c45] text-slate-200 border border-[#1f2b3e] font-bold text-xs py-3 rounded-xl text-center transition"
                            >
                                View Tickets
                            </a>
                        </div>
                    </div>

                    {/* RIGHT CARD: Winning Numbers */}
                    <div className="lg:col-span-7 bg-[radial-gradient(ellipse_at_center,#0f1c19_0%,#0d151c_100%)] border-1.5 border-amber-500 rounded-2xl p-6 flex flex-col justify-between shadow-[0_0_25px_rgba(234,179,8,0.2)]">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-black text-amber-400 tracking-wider uppercase">
                                LATEST WINNING NUMBERS
                            </span>
                            <span className="text-xs font-bold text-slate-400">
                                Draw #127, July 1
                            </span>
                        </div>

                        {/* 6 3D Golden Hexagon Balls */}
                        <div className="flex items-center justify-center gap-3 my-5">
                            {['7', '2', '4', '6', '0', '5'].map((digit, idx) => (
                                <div
                                    key={idx}
                                    className="w-14 h-16 bg-[radial-gradient(circle_at_40%_35%,#2a220a_0%,#161204_100%)] border-2 border-amber-500 [clip-path:polygon(50%_0%,93%_25%,93%_75%,50%_100%,7%_75%,7%_25%)] flex items-center justify-center text-3xl font-black text-amber-400 drop-shadow-[0_0_12px_rgba(250,204,21,0.8)] shadow-[0_0_15px_rgba(234,179,8,0.4)] transition-transform hover:scale-105"
                                >
                                    {digit}
                                </div>
                            ))}
                        </div>

                        <div className="flex items-center justify-between text-xs">
                            <span className="text-slate-300">
                                1st Prize: <span className="font-black text-amber-400">6,000,000 THB</span>
                            </span>
                            <span className="text-slate-400 font-semibold">
                                Next Draw: Draw #128
                            </span>
                        </div>
                    </div>
                </div>

                {/* RECENT WAGERS TABLE */}
                <div className="space-y-3">
                    <h2 className="text-sm font-black text-white tracking-wider uppercase">
                        RECENT WAGERS
                    </h2>

                    <div className="bg-[#121926] border border-[#1f2b3e] rounded-2xl overflow-hidden shadow-2xl">
                        <table className="w-full text-left text-xs">
                            <thead className="bg-[#0f1622] text-slate-400 font-bold border-b border-[#1f2b3e]">
                                <tr>
                                    <th className="py-3.5 px-5">Ticket ID</th>
                                    <th className="py-3.5 px-5">Date</th>
                                    <th className="py-3.5 px-5">Numbers</th>
                                    <th className="py-3.5 px-5">Type</th>
                                    <th className="py-3.5 px-5">Amount</th>
                                    <th className="py-3.5 px-5">Status</th>
                                    <th className="py-3.5 px-5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[#162030] text-slate-300">
                                {wagers.map((row) => (
                                    <tr key={row.id} className="hover:bg-[#172133]/60 transition">
                                        <td className="py-3.5 px-5 font-mono font-bold text-slate-200">
                                            📄 {row.ticketId}
                                        </td>
                                        <td className="py-3.5 px-5 text-slate-400">{row.date}</td>
                                        <td className="py-3.5 px-5">
                                            <span className={`font-mono font-black text-sm ${row.isWinnerMatch ? 'text-amber-400 drop-shadow-[0_0_8px_rgba(250,204,21,0.5)]' : 'text-white'}`}>
                                                {row.numbers}
                                            </span>
                                        </td>
                                        <td className="py-3.5 px-5">{row.type}</td>
                                        <td className="py-3.5 px-5 font-mono">{row.amount}</td>
                                        <td className="py-3.5 px-5">
                                            {row.status === 'WON' && (
                                                <span className="bg-emerald-500/15 border border-emerald-500 text-emerald-400 text-[10px] font-black px-2 py-0.5 rounded">
                                                    WON
                                                </span>
                                            )}
                                            {row.status === 'PENDING' && (
                                                <span className="bg-amber-500/15 border border-amber-500 text-amber-400 text-[10px] font-black px-2 py-0.5 rounded">
                                                    PENDING
                                                </span>
                                            )}
                                            {row.status === 'LOST' && (
                                                <span className="bg-rose-500/15 border border-rose-500 text-rose-400 text-[10px] font-black px-2 py-0.5 rounded">
                                                    LOST
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-3.5 px-5 text-right space-x-2">
                                            {row.status === 'PENDING' ? (
                                                <>
                                                    <button
                                                        onClick={() => alert(`Modify ticket ${row.ticketId}`)}
                                                        className="text-slate-400 hover:text-white"
                                                    >
                                                        Modify
                                                    </button>
                                                    <span className="text-slate-600">/</span>
                                                    <button
                                                        onClick={() => handleCancel(row.ticketId)}
                                                        className="text-rose-400 hover:text-rose-300"
                                                    >
                                                        Cancel
                                                    </button>
                                                </>
                                            ) : row.status === 'LOST' ? (
                                                <button
                                                    onClick={() => alert(`Details for ${row.ticketId}`)}
                                                    className="text-slate-400 hover:text-sky-400"
                                                >
                                                    Details ⓘ
                                                </button>
                                            ) : (
                                                <button
                                                    onClick={() => alert(`Viewing ticket ${row.ticketId}`)}
                                                    className="text-slate-400 hover:text-sky-400"
                                                >
                                                    View / History
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>

            {/* Floating Help Button */}
            <button
                onClick={() => alert('Live 24/7 Support Desk')}
                className="fixed bottom-6 right-6 w-10 h-10 rounded-full bg-slate-800 border border-slate-700 text-slate-300 hover:text-white hover:bg-slate-700 flex items-center justify-center font-black shadow-2xl transition"
            >
                ?
            </button>
        </div>
    );
};
