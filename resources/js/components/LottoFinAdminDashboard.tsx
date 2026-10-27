import React, { useState, useEffect } from 'react';

interface ReconciliationRow {
    dateTime: string;
    transactionId: string;
    sourceAccount: string;
    ledgerAccount: string;
    debit: string;
    credit: string;
    systemBal: string;
    bankBal: string;
    discrepancy: string;
    status: 'Balanced' | 'Unbalanced' | 'Pending';
}

const INITIAL_ROWS: ReconciliationRow[] = [
    {
        dateTime: 'Aug 21, 15:42:01',
        transactionId: '#78912',
        sourceAccount: 'Bets Pool',
        ledgerAccount: 'Wallet A',
        debit: '1,420,500.00',
        credit: '-',
        systemBal: '1,420,500.00',
        bankBal: '1,420,500.00',
        discrepancy: '0.00',
        status: 'Balanced',
    },
    {
        dateTime: 'Aug 21, 15:39:12',
        transactionId: '#78911',
        sourceAccount: 'User Wallets',
        ledgerAccount: 'Withdrawal',
        debit: '-',
        credit: '10,500.00',
        systemBal: '1,410,000.00',
        bankBal: '1,410,000.00',
        discrepancy: '0.00',
        status: 'Balanced',
    },
    {
        dateTime: 'Aug 21, 15:35:55',
        transactionId: '#78910',
        sourceAccount: 'Draw Payouts',
        ledgerAccount: 'Payout',
        debit: '-',
        credit: '75,000.00',
        systemBal: '1,335,000.00',
        bankBal: '1,335,000.00',
        discrepancy: '0.00',
        status: 'Balanced',
    },
    {
        dateTime: 'Aug 21, 15:28:44',
        transactionId: '#78909',
        sourceAccount: 'GL Account',
        ledgerAccount: 'Adjustment',
        debit: '250.00',
        credit: '-',
        systemBal: '1,335,250.00',
        bankBal: '1,335,250.00',
        discrepancy: '0.00',
        status: 'Balanced',
    },
];

export const LottoFinAdminDashboard: React.FC = () => {
    const [searchQuery, setSearchQuery] = useState('');
    const [rows, setRows] = useState<ReconciliationRow[]>(INITIAL_ROWS);
    const [kpis, setKpis] = useState({
        totalWagered: '1,420,500 THB',
        totalWageredTrend: '↑ +8.2%',
        houseGrossProfit: '348,200 THB',
        houseGrossProfitTrend: '↑ +12.5%',
        activeInPlayBets: 4821,
        activeInPlayBetsNew: '+21 new',
        pendingWithdrawals: 12,
        pendingWithdrawalsTrend: '↓ -2',
    });

    const filteredRows = rows.filter(
        (r) =>
            r.transactionId.toLowerCase().includes(searchQuery.toLowerCase()) ||
            r.sourceAccount.toLowerCase().includes(searchQuery.toLowerCase()) ||
            r.ledgerAccount.toLowerCase().includes(searchQuery.toLowerCase()) ||
            r.status.toLowerCase().includes(searchQuery.toLowerCase())
    );

    return (
        <div className="flex flex-col gap-6 w-full text-slate-100 font-sans">
            {/* Page Header */}
            <h1 className="text-xl sm:text-2xl font-black text-white uppercase tracking-wide">
                Executive Analytics Dashboard
            </h1>

            {/* 4 KPI Cards Grid */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {/* Total Wagered */}
                <div className="bg-[#131c28] border border-[#1f2d3d] rounded-2xl p-5 flex flex-col gap-1.5 shadow-lg">
                    <span className="text-xs font-semibold text-slate-400">Total Wagered</span>
                    <div className="text-2xl font-black text-[#10b981]">{kpis.totalWagered}</div>
                    <div className="text-xs font-bold text-[#10b981] flex items-center gap-1">
                        <span>{kpis.totalWageredTrend}</span>
                    </div>
                </div>

                {/* House Gross Profit */}
                <div className="bg-[#131c28] border border-[#1f2d3d] rounded-2xl p-5 flex flex-col gap-1.5 shadow-lg">
                    <span className="text-xs font-semibold text-slate-400">House Gross Profit</span>
                    <div className="text-2xl font-black text-[#10b981]">{kpis.houseGrossProfit}</div>
                    <div className="text-xs font-bold text-[#10b981] flex items-center gap-1">
                        <span>{kpis.houseGrossProfitTrend}</span>
                    </div>
                </div>

                {/* Active In-Play Bets */}
                <div className="bg-[#131c28] border border-[#1f2d3d] rounded-2xl p-5 flex flex-col gap-1.5 shadow-lg">
                    <span className="text-xs font-semibold text-slate-400">Active In-Play Bets</span>
                    <div className="text-2xl font-black text-[#10b981]">{kpis.activeInPlayBets.toLocaleString()}</div>
                    <div className="text-xs font-bold text-slate-400 flex items-center gap-1">
                        <span>{kpis.activeInPlayBetsNew}</span>
                    </div>
                </div>

                {/* Pending Withdrawals */}
                <div className="bg-[#131c28] border border-[#1f2d3d] rounded-2xl p-5 flex flex-col gap-1.5 shadow-lg">
                    <span className="text-xs font-semibold text-slate-400">Pending Withdrawals</span>
                    <div className="text-2xl font-black text-white">{kpis.pendingWithdrawals}</div>
                    <div className="text-xs font-bold text-[#f43f5e] flex items-center gap-1">
                        <span>{kpis.pendingWithdrawalsTrend}</span>
                    </div>
                </div>
            </div>

            {/* Live Financial Reconciliation Monitor Panel */}
            <div className="bg-[#131c28] border border-[#1f2d3d] rounded-2xl p-5 sm:p-6 flex flex-col gap-4 shadow-xl">
                {/* Header Actions */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <h2 className="text-base sm:text-lg font-bold text-white">
                        Live Financial Reconciliation Monitor
                    </h2>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            className="bg-[#0b1118] border border-[#1f2d3d] hover:border-slate-600 text-white text-xs font-bold px-3.5 py-2 rounded-xl flex items-center gap-1.5 transition"
                        >
                            <span>⚙ Actions</span>
                            <span className="text-[10px]">⌄</span>
                        </button>
                        <button
                            type="button"
                            className="bg-[#10b981] hover:bg-[#059669] text-[#020617] text-xs font-black px-4 py-2 rounded-xl flex items-center gap-1.5 shadow-lg shadow-emerald-500/20 transition"
                        >
                            <span>Actions</span>
                            <span className="text-[10px]">⌄</span>
                        </button>
                    </div>
                </div>

                {/* Toolbar */}
                <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-1">
                    <div className="flex items-center gap-2 w-full sm:w-auto flex-1 max-w-md">
                        <div className="relative w-full">
                            <span className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs">🔍</span>
                            <input
                                type="text"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                placeholder="Search"
                                className="w-full bg-[#0b1118] border border-[#1f2d3d] rounded-xl pl-8 pr-4 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-[#10b981]"
                            />
                        </div>
                        <button
                            type="button"
                            className="bg-[#0b1118] border border-[#1f2d3d] text-slate-300 text-xs font-bold px-3 py-1.5 rounded-xl flex items-center gap-1 flex-shrink-0"
                        >
                            <span>≡ Actions</span>
                            <span className="text-[10px]">⌄</span>
                        </button>
                    </div>

                    <div className="flex items-center gap-2 w-full sm:w-auto justify-end">
                        <button
                            type="button"
                            className="bg-[#0b1118] border border-[#1f2d3d] text-slate-300 text-xs font-bold px-3 py-1.5 rounded-xl flex items-center gap-1"
                        >
                            <span>Filter</span>
                            <span className="text-[10px]">⌄</span>
                        </button>
                        <button type="button" className="w-8 h-8 rounded-lg bg-[#0b1118] border border-[#1f2d3d] text-slate-400 hover:text-white flex items-center justify-center text-xs">
                            ≡
                        </button>
                        <button type="button" className="w-8 h-8 rounded-lg bg-[#0b1118] border border-[#1f2d3d] text-slate-400 hover:text-white flex items-center justify-center text-xs">
                            𝄃
                        </button>
                        <button type="button" className="w-8 h-8 rounded-lg bg-[#0b1118] border border-[#1f2d3d] text-slate-400 hover:text-rose-400 flex items-center justify-center text-xs">
                            🗑
                        </button>
                    </div>
                </div>

                {/* Table */}
                <div className="overflow-x-auto border border-[#1f2d3d] rounded-xl bg-[#0b1118]">
                    <table className="w-full text-left text-xs text-slate-300">
                        <thead className="bg-[#0d1520] text-slate-400 uppercase font-bold border-b border-[#1f2d3d]">
                            <tr>
                                <th className="p-3 whitespace-nowrap">Date/Time ⇅</th>
                                <th className="p-3 whitespace-nowrap">Transaction ID</th>
                                <th className="p-3 whitespace-nowrap">Source Account</th>
                                <th className="p-3 whitespace-nowrap">Ledger Account</th>
                                <th className="p-3 whitespace-nowrap">Debit (THB)</th>
                                <th className="p-3 whitespace-nowrap">Credit (THB)</th>
                                <th className="p-3 whitespace-nowrap">System Bal. (THB)</th>
                                <th className="p-3 whitespace-nowrap">Bank Bal. (THB)</th>
                                <th className="p-3 whitespace-nowrap">Discrepancy (THB)</th>
                                <th className="p-3 whitespace-nowrap">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[#192534]">
                            {filteredRows.map((row, idx) => (
                                <tr key={idx} className="hover:bg-white/[0.02] transition">
                                    <td className="p-3 whitespace-nowrap text-slate-400">{row.dateTime}</td>
                                    <td className="p-3 whitespace-nowrap font-mono font-bold text-white">{row.transactionId}</td>
                                    <td className="p-3 whitespace-nowrap">{row.sourceAccount}</td>
                                    <td className="p-3 whitespace-nowrap">{row.ledgerAccount}</td>
                                    <td className="p-3 whitespace-nowrap font-mono">{row.debit}</td>
                                    <td className="p-3 whitespace-nowrap font-mono">{row.credit}</td>
                                    <td className="p-3 whitespace-nowrap font-mono">{row.systemBal}</td>
                                    <td className="p-3 whitespace-nowrap font-mono">{row.bankBal}</td>
                                    <td className="p-3 whitespace-nowrap font-mono">{row.discrepancy}</td>
                                    <td className="p-3 whitespace-nowrap">
                                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-[#064e3b] text-[#34d399] border border-[#10b981]/30">
                                            {row.status}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                <div className="flex items-center justify-between text-xs text-slate-400 pt-2">
                    <div className="flex items-center gap-1">
                        <button className="w-7 h-7 rounded border border-[#1f2d3d] bg-[#0b1118] flex items-center justify-center">&lt;</button>
                        <button className="w-7 h-7 rounded bg-[#059669] text-white font-bold flex items-center justify-center">1</button>
                        <span className="px-1">...</span>
                        <button className="w-7 h-7 rounded border border-[#1f2d3d] bg-[#0b1118] flex items-center justify-center">&gt;</button>
                    </div>
                    <div className="flex items-center gap-1">
                        <button className="w-7 h-7 rounded border border-[#1f2d3d] bg-[#0b1118] flex items-center justify-center">&lt;</button>
                        <button className="w-7 h-7 rounded border border-[#1f2d3d] bg-[#0b1118] flex items-center justify-center">&gt;</button>
                    </div>
                </div>
            </div>
        </div>
    );
};
