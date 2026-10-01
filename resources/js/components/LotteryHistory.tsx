import React, { useState, useEffect } from 'react';

export interface BetItem {
    id: string;
    play_type: string;
    play_label: string;
    number: string;
    stake: number;
    rate: number;
    potential_payout: number;
    payout?: number;
    status: 'pending' | 'won' | 'lost' | 'cancelled';
    winning_number?: string;
}

export interface WagerSlip {
    slip_id: string;
    market_id: string;
    market_name: string;
    draw_date: string;
    draw_time: string;
    created_at: string;
    status: 'pending' | 'won' | 'lost' | 'cancelled';
    status_label: string;
    total_stake: number;
    total_payout: number;
    potential_win: number;
    can_cancel: boolean;
    items: BetItem[];
}

export const LotteryHistory: React.FC = () => {
    const [slips, setSlips] = useState<WagerSlip[]>([]);
    const [statusFilter, setStatusFilter] = useState<string>('all');
    const [marketFilter, setMarketFilter] = useState<string>('all');
    const [drawDateFilter, setDrawDateFilter] = useState<string>('');
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [selectedSlip, setSelectedSlip] = useState<WagerSlip | null>(null);
    const [loading, setLoading] = useState<boolean>(false);

    const fetchSlips = async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (statusFilter !== 'all') params.append('status', statusFilter);
            if (marketFilter !== 'all') params.append('market', marketFilter);
            if (drawDateFilter) params.append('draw_date', drawDateFilter);
            if (searchQuery) params.append('q', searchQuery);

            const res = await fetch(`/api/v1/player/history?${params.toString()}`);
            const data = await res.json();
            if (data.success && data.data) {
                setSlips(data.data);
            }
        } catch (e) {
            console.error('Failed to load lottery history', e);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchSlips();
    }, [statusFilter, marketFilter, drawDateFilter]);

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        fetchSlips();
    };

    const handleCancelSlip = async (slipId: string) => {
        if (!window.confirm(`Are you sure you want to cancel slip ${slipId}?`)) return;
        try {
            const res = await fetch(`/api/v1/player/history/cancel/${slipId}`, { method: 'POST' });
            const data = await res.json();
            alert(data.message || 'Slip cancelled.');
            fetchSlips();
        } catch (e) {
            alert('Failed to cancel slip.');
        }
    };

    return (
        <div className="w-full max-w-[1320px] mx-auto p-4 md:p-8 flex flex-col gap-6 text-slate-100 font-sans">
            {/* STATS HEADER */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-5 shadow-xl relative overflow-hidden">
                    <span className="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-1">Total Wagers</span>
                    <span className="text-2xl font-black font-mono text-white">148 Tickets</span>
                    <span className="text-[11px] text-slate-500 block mt-1">Across 6 lottery markets</span>
                </div>
                <div className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-5 shadow-xl">
                    <span className="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-1">Total Turnover</span>
                    <span className="text-2xl font-black font-mono text-amber-400">45,200.00 THB</span>
                    <span className="text-[11px] text-slate-500 block mt-1">Lifetime bet stakes</span>
                </div>
                <div className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-5 shadow-xl">
                    <span className="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-1">Total Prize Wins</span>
                    <span className="text-2xl font-black font-mono text-emerald-400">94,800.00 THB</span>
                    <span className="text-[11px] text-emerald-500/80 block mt-1">Win Rate 32.4%</span>
                </div>
                <div className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-5 shadow-xl">
                    <span className="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-1">Pending Slips</span>
                    <span className="text-2xl font-black font-mono text-amber-300">3 Slips</span>
                    <span className="text-[11px] text-amber-500/80 block mt-1">Next draw today 15:30</span>
                </div>
            </div>

            {/* FILTER PANEL */}
            <div className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-6 shadow-xl flex flex-col gap-4">
                {/* Status Pills */}
                <div className="flex items-center gap-2 flex-wrap border-b border-[#1f2b3e] pb-4">
                    {['all', 'pending', 'won', 'lost'].map((st) => (
                        <button
                            key={st}
                            type="button"
                            onClick={() => setStatusFilter(st)}
                            className={`px-4 py-1.5 rounded-full text-xs font-bold capitalize transition ${
                                statusFilter === st
                                    ? 'bg-amber-500/20 border border-amber-500 text-amber-400 shadow-[0_0_10px_rgba(245,158,11,0.2)]'
                                    : 'bg-[#172233] border border-[#1f2b3e] text-slate-400 hover:text-white'
                            }`}
                        >
                            {st === 'all' ? 'All Slips (ทั้งหมด)' : st === 'won' ? 'Won (ถูกรางวัล)' : st === 'lost' ? 'Lost (ไม่ถูกรางวัล)' : 'Pending (รอออกผล)'}
                        </button>
                    ))}
                </div>

                {/* Filter Controls Grid */}
                <form onSubmit={handleSearchSubmit} className="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                    <input
                        type="text"
                        placeholder="Search Slip ID (TL-BET...) or Number..."
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        className="bg-[#0c121d] border border-[#1f2b3e] rounded-xl px-4 py-2.5 text-xs text-white font-medium outline-none focus:border-amber-500"
                    />

                    <select
                        value={marketFilter}
                        onChange={(e) => setMarketFilter(e.target.value)}
                        className="bg-[#0c121d] border border-[#1f2b3e] rounded-xl px-4 py-2.5 text-xs text-white outline-none cursor-pointer"
                    >
                        <option value="all">All Lottery Markets</option>
                        <option value="glo_thai">Thai Government Lottery</option>
                        <option value="lao_star">Lao Star Lottery</option>
                        <option value="hanoi_vip">Hanoi VIP Lottery</option>
                        <option value="yeekee_vip">Yeekee 88 Rounds</option>
                    </select>

                    <select
                        value={drawDateFilter}
                        onChange={(e) => setDrawDateFilter(e.target.value)}
                        className="bg-[#0c121d] border border-[#1f2b3e] rounded-xl px-4 py-2.5 text-xs text-white outline-none cursor-pointer"
                    >
                        <option value="">All Draw Dates (ทุกงวด)</option>
                        <option value="2026-10-01">1 October 2026</option>
                        <option value="2026-09-16">16 September 2026</option>
                        <option value="2026-09-01">1 September 2026</option>
                    </select>

                    <button
                        type="button"
                        onClick={fetchSlips}
                        className="bg-[#172233] hover:bg-[#1e2c42] border border-[#1f2b3e] hover:border-amber-500 text-amber-400 font-bold text-xs py-2.5 px-4 rounded-xl transition"
                    >
                        🔄 Refresh History
                    </button>
                </form>
            </div>

            {/* SLIPS LIST */}
            <div className="flex flex-col gap-4">
                {loading ? (
                    <div className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-12 text-center text-slate-400 text-xs">
                        Loading wager records...
                    </div>
                ) : slips.length === 0 ? (
                    <div className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-12 text-center">
                        <div className="text-3xl mb-2">📜</div>
                        <div className="text-white font-bold text-base">No Lottery Wager Records Found</div>
                        <div className="text-slate-400 text-xs mt-1">Try adjusting your status or market filter.</div>
                    </div>
                ) : (
                    slips.map((slip) => (
                        <div
                            key={slip.slip_id}
                            className={`bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-6 shadow-xl flex flex-col gap-4 transition hover:border-slate-700 ${
                                slip.status === 'won' ? 'border-l-4 border-l-emerald-500' : slip.status === 'lost' ? 'border-l-4 border-l-rose-500' : 'border-l-4 border-l-amber-500'
                            }`}
                        >
                            {/* Card Top */}
                            <div className="flex items-center justify-between flex-wrap gap-2 pb-3 border-b border-[#1f2b3e]">
                                <div className="flex items-center gap-3">
                                    <span className="font-mono text-sm font-black text-white">{slip.slip_id}</span>
                                    <span
                                        className={`text-[10px] font-black uppercase px-2.5 py-0.5 rounded-full border ${
                                            slip.status === 'won'
                                                ? 'bg-emerald-500/15 border-emerald-500 text-emerald-400'
                                                : slip.status === 'lost'
                                                ? 'bg-rose-500/15 border-rose-500 text-rose-400'
                                                : 'bg-amber-500/15 border-amber-500 text-amber-400'
                                        }`}
                                    >
                                        {slip.status_label}
                                    </span>
                                </div>
                                <div className="text-xs text-slate-400 font-mono">
                                    Draw Date: <span className="font-bold text-white">{slip.draw_date} ({slip.draw_time})</span>
                                </div>
                            </div>

                            <div className="text-xs font-bold text-amber-400">{slip.market_name}</div>

                            {/* Items Table */}
                            <table className="w-full text-left text-xs">
                                <thead>
                                    <tr className="border-b border-[#1f2b3e] text-slate-400 text-[10px] uppercase">
                                        <th className="py-2">Play Type</th>
                                        <th className="py-2">Number</th>
                                        <th className="py-2">Stake</th>
                                        <th className="py-2">Odds</th>
                                        <th className="py-2">Result / Payout</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {slip.items.map((it) => (
                                        <tr key={it.id} className="border-b border-[#1f2b3e]/40">
                                            <td className="py-2.5 font-bold text-white">{it.play_label}</td>
                                            <td className="py-2.5">
                                                <span className="font-mono font-black text-amber-400 bg-[#080c14] border border-[#1f2b3e] px-2 py-0.5 rounded">
                                                    {it.number}
                                                </span>
                                            </td>
                                            <td className="py-2.5 font-mono text-slate-300">{it.stake.toFixed(2)} ฿</td>
                                            <td className="py-2.5 font-mono text-amber-400">x{it.rate}</td>
                                            <td className="py-2.5 font-mono">
                                                {it.status === 'won' ? (
                                                    <span className="font-bold text-emerald-400">+{(it.payout || 0).toLocaleString()} ฿</span>
                                                ) : it.status === 'lost' ? (
                                                    <span className="text-slate-500">0.00 ฿</span>
                                                ) : (
                                                    <span className="text-amber-400 font-bold">Pending</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>

                            {/* Card Footer */}
                            <div className="flex items-center justify-between flex-wrap gap-4 pt-2">
                                <div className="flex items-center gap-6 text-xs font-mono">
                                    <div>Total Stake: <span className="font-bold text-white">{slip.total_stake.toFixed(2)} THB</span></div>
                                    <div>
                                        Total Payout:{' '}
                                        <span className={`font-bold ${slip.total_payout > 0 ? 'text-emerald-400' : 'text-slate-400'}`}>
                                            {slip.total_payout.toFixed(2)} THB
                                        </span>
                                    </div>
                                </div>

                                <div className="flex items-center gap-3">
                                    {slip.can_cancel && (
                                        <button
                                            type="button"
                                            onClick={() => handleCancelSlip(slip.slip_id)}
                                            className="bg-rose-500/10 hover:bg-rose-500/20 border border-rose-500/40 text-rose-400 text-xs font-bold px-3 py-1.5 rounded-xl transition"
                                        >
                                            Cancel Slip
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        onClick={() => (window.location.href = `/betting?rebet=${slip.slip_id}`)}
                                        className="bg-[#172233] hover:bg-[#1e2c42] border border-[#1f2b3e] text-white text-xs font-bold px-3 py-1.5 rounded-xl transition"
                                    >
                                        🔁 Re-Bet
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setSelectedSlip(slip)}
                                        className="bg-gradient-to-r from-amber-500 to-amber-600 text-slate-950 text-xs font-black px-4 py-1.5 rounded-xl shadow-[0_0_12px_rgba(245,158,11,0.3)] transition"
                                    >
                                        View Official Slip
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))
                )}
            </div>

            {/* MODAL */}
            {selectedSlip && (
                <div className="fixed inset-0 bg-slate-950/85 backdrop-blur-md flex items-center justify-center p-4 z-50">
                    <div className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl max-w-lg w-full p-6 shadow-2xl flex flex-col gap-4">
                        <div className="flex items-center justify-between border-b border-[#1f2b3e] pb-3">
                            <div>
                                <h3 className="font-black text-white text-base">Official Lottery Slip</h3>
                                <span className="font-mono text-xs text-amber-400">{selectedSlip.slip_id}</span>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedSlip(null)}
                                className="text-slate-400 hover:text-white text-lg font-bold"
                            >
                                ✕
                            </button>
                        </div>

                        <div className="bg-[#0c121d] p-4 rounded-xl border border-[#1f2b3e] space-y-2 text-xs">
                            <div className="flex justify-between">
                                <span className="text-slate-400">Market</span>
                                <span className="font-bold text-white">{selectedSlip.market_name}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-slate-400">Draw Date</span>
                                <span className="font-mono font-bold text-white">{selectedSlip.draw_date}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-slate-400">Status</span>
                                <span className="font-bold text-amber-400">{selectedSlip.status_label}</span>
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 pt-2">
                            <button
                                type="button"
                                onClick={() => window.print()}
                                className="bg-[#172233] border border-[#1f2b3e] text-white text-xs font-bold px-4 py-2 rounded-xl"
                            >
                                🖨 Print / PDF
                            </button>
                            <button
                                type="button"
                                onClick={() => setSelectedSlip(null)}
                                className="bg-amber-500 text-slate-950 text-xs font-black px-4 py-2 rounded-xl"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};
