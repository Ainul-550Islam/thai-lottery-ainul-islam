import React, { useState } from 'react';

export const PlayerProfile: React.FC = () => {
    const [activeTab, setActiveTab] = useState<'personal' | 'security' | 'bank' | 'transfer' | 'notifications' | 'sessions'>('personal');
    const [transferDirection, setTransferDirection] = useState<'cash_to_win' | 'win_to_cash'>('cash_to_win');
    const [transferAmount, setTransferAmount] = useState<number>(500);

    const [user] = useState({
        memberId: 'TL-894120',
        name: 'Alex Thompson',
        username: 'alex_thai88',
        email: 'alex.thompson@example.com',
        phone: '081-234-5678',
        lineId: '@alex_lottery',
        vipTier: 'Gold VIP',
        cashBalance: 5450.00,
        winBalance: 94800.00,
    });

    const handleSaveProfile = (e: React.FormEvent) => {
        e.preventDefault();
        alert('Profile details saved successfully.');
    };

    const handlePasswordUpdate = (e: React.FormEvent) => {
        e.preventDefault();
        alert('Security password updated successfully.');
    };

    const handleTransfer = () => {
        alert(`Balance transfer of ${transferAmount.toLocaleString()} THB (${transferDirection === 'cash_to_win' ? 'Cash to Win' : 'Win to Cash'}) executed.`);
    };

    return (
        <div className="min-h-screen bg-[#0b0f17] text-slate-100 font-sans selection:bg-amber-500 selection:text-white">
            {/* TOP HEADER */}
            <header className="h-[72px] bg-[#0f1522] border-b border-[#1f2b3e] px-8 flex items-center justify-between sticky top-0 z-50">
                <a href="/profile" className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-gradient-to-tr from-amber-500 to-amber-600 flex items-center justify-center text-slate-950 font-black text-xl shadow-[0_0_15px_rgba(245,158,11,0.4)]">
                        🪷
                    </div>
                    <div className="flex flex-col">
                        <span className="text-base font-black text-white tracking-wide">THAILOTTO.CLUB</span>
                        <span className="text-[10px] font-bold text-amber-500 uppercase tracking-widest">Member Area</span>
                    </div>
                </a>

                <div className="flex items-center gap-6">
                    <div className="bg-[#08121a] border border-amber-500/40 rounded-full px-4 py-1.5 text-xs font-bold text-amber-400">
                        Tier: <span className="font-black text-white">{user.vipTier}</span>
                    </div>
                    <a href="/dashboard" className="text-slate-400 hover:text-white text-xs font-bold transition">
                        Back to Dashboard →
                    </a>
                </div>
            </header>

            {/* MAIN CONTENT */}
            <main className="max-w-7xl mx-auto p-8 grid grid-cols-1 lg:grid-cols-12 gap-8">
                {/* LEFT SIDEBAR PROFILE CARD */}
                <div className="lg:col-span-4 bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-6 flex flex-col gap-6 shadow-2xl h-fit">
                    <div className="flex flex-col items-center text-center relative">
                        <div className="w-24 h-24 rounded-full border-3 border-amber-500 overflow-hidden shadow-[0_0_20px_rgba(245,158,11,0.3)]">
                            <img
                                src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=200&auto=format&fit=crop&q=80"
                                alt="User"
                                className="w-full h-full object-cover"
                            />
                        </div>
                        <span className="mt-2 text-xs font-black bg-amber-500 text-slate-950 px-3 py-0.5 rounded-full uppercase tracking-wider">
                            {user.vipTier}
                        </span>
                        <h2 className="text-lg font-black text-white mt-2">{user.name}</h2>
                        <span className="font-mono text-xs text-amber-400 font-bold">{user.memberId}</span>
                    </div>

                    {/* Quick Wallet Stats */}
                    <div className="grid grid-cols-2 gap-4 py-3 border-y border-[#1f2b3e] text-xs">
                        <div>
                            <span className="text-slate-400 block text-[11px]">Cash Wallet</span>
                            <span className="font-mono font-bold text-white text-sm">{user.cashBalance.toLocaleString()} THB</span>
                        </div>
                        <div>
                            <span className="text-slate-400 block text-[11px]">Win Wallet</span>
                            <span className="font-mono font-bold text-emerald-400 text-sm">{user.winBalance.toLocaleString()} THB</span>
                        </div>
                    </div>

                    {/* Navigation Tabs */}
                    <nav className="flex flex-col gap-1.5 text-xs font-bold">
                        {[
                            { key: 'personal', icon: '👤', label: 'Personal Details (ข้อมูลส่วนตัว)' },
                            { key: 'security', icon: '🔒', label: 'Security & Password (รหัสผ่าน)' },
                            { key: 'bank', icon: '🏛', label: 'Bank Accounts (ผูกบัญชีธนาคาร)' },
                            { key: 'transfer', icon: '🔄', label: 'Balance Transfer (โอนย้ายเงิน)' },
                            { key: 'notifications', icon: '🔔', label: 'Notifications & Alerts' },
                            { key: 'sessions', icon: '📱', label: 'Active Devices & Sessions' },
                        ].map((t) => (
                            <button
                                key={t.key}
                                onClick={() => setActiveTab(t.key as any)}
                                className={`flex items-center gap-2.5 px-3.5 py-3 rounded-xl transition text-left ${
                                    activeTab === t.key
                                        ? 'bg-[#1b263b] border border-amber-500 text-amber-400 shadow-[0_0_12px_rgba(245,158,11,0.25)]'
                                        : 'text-slate-300 hover:bg-[#172233] hover:text-white'
                                }`}
                            >
                                <span>{t.icon}</span>
                                <span>{t.label}</span>
                            </button>
                        ))}
                    </nav>
                </div>

                {/* RIGHT MAIN SETTINGS PANEL */}
                <div className="lg:col-span-8 bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-8 shadow-2xl">
                    {/* TAB 1: PERSONAL DETAILS */}
                    {activeTab === 'personal' && (
                        <form onSubmit={handleSaveProfile} className="space-y-6">
                            <div className="border-b border-[#1f2b3e] pb-4">
                                <h3 className="text-lg font-black text-white">Personal Profile Details</h3>
                                <p className="text-xs text-slate-400 mt-1">Manage your official member registration and contact info.</p>
                            </div>

                            <div className="grid grid-cols-2 gap-4 text-xs">
                                <div>
                                    <label className="text-slate-300 font-bold block mb-1">Full Name (ชื่อ - นามสกุล)</label>
                                    <input type="text" defaultValue={user.name} className="w-full bg-[#0c121d] border border-[#1f2b3e] rounded-xl px-4 py-2.5 text-white font-bold outline-none focus:border-amber-500" />
                                </div>
                                <div>
                                    <label className="text-slate-300 font-bold block mb-1">Username / Member ID</label>
                                    <input type="text" disabled value={user.username} className="w-full bg-[#090d14] border border-[#1f2b3e] rounded-xl px-4 py-2.5 text-slate-500 font-mono font-bold cursor-not-allowed" />
                                </div>
                                <div>
                                    <label className="text-slate-300 font-bold block mb-1">Phone Number (เบอร์โทรศัพท์)</label>
                                    <input type="text" defaultValue={user.phone} className="w-full bg-[#0c121d] border border-[#1f2b3e] rounded-xl px-4 py-2.5 text-white font-mono font-bold outline-none focus:border-amber-500" />
                                </div>
                                <div>
                                    <label className="text-slate-300 font-bold block mb-1">Email Address</label>
                                    <input type="email" defaultValue={user.email} className="w-full bg-[#0c121d] border border-[#1f2b3e] rounded-xl px-4 py-2.5 text-white font-bold outline-none focus:border-amber-500" />
                                </div>
                                <div className="col-span-2">
                                    <label className="text-slate-300 font-bold block mb-1">LINE ID (ติดต่อทางไลน์)</label>
                                    <input type="text" defaultValue={user.lineId} className="w-full bg-[#0c121d] border border-[#1f2b3e] rounded-xl px-4 py-2.5 text-white font-bold outline-none focus:border-amber-500" />
                                </div>
                            </div>

                            <button type="submit" className="bg-gradient-to-r from-amber-500 to-amber-600 text-slate-950 font-black text-xs py-3 px-6 rounded-xl shadow-[0_0_20px_rgba(245,158,11,0.35)] transition">
                                Save Profile Changes
                            </button>
                        </form>
                    )}

                    {/* TAB 2: SECURITY & PASSWORD */}
                    {activeTab === 'security' && (
                        <form onSubmit={handlePasswordUpdate} className="space-y-6">
                            <div className="border-b border-[#1f2b3e] pb-4">
                                <h3 className="text-lg font-black text-white">Password &amp; Security</h3>
                                <p className="text-xs text-slate-400 mt-1">Update login credentials and manage 6-digit withdrawal PIN.</p>
                            </div>

                            <div className="space-y-4 text-xs max-w-md">
                                <div>
                                    <label className="text-slate-300 font-bold block mb-1">Current Password</label>
                                    <input type="password" placeholder="••••••••" className="w-full bg-[#0c121d] border border-[#1f2b3e] rounded-xl px-4 py-2.5 text-white outline-none focus:border-amber-500" />
                                </div>
                                <div>
                                    <label className="text-slate-300 font-bold block mb-1">New Password</label>
                                    <input type="password" placeholder="••••••••" className="w-full bg-[#0c121d] border border-[#1f2b3e] rounded-xl px-4 py-2.5 text-white outline-none focus:border-amber-500" />
                                </div>
                                <div>
                                    <label className="text-slate-300 font-bold block mb-1">Confirm New Password</label>
                                    <input type="password" placeholder="••••••••" className="w-full bg-[#0c121d] border border-[#1f2b3e] rounded-xl px-4 py-2.5 text-white outline-none focus:border-amber-500" />
                                </div>
                            </div>

                            <button type="submit" className="bg-gradient-to-r from-amber-500 to-amber-600 text-slate-950 font-black text-xs py-3 px-6 rounded-xl shadow-[0_0_20px_rgba(245,158,11,0.35)] transition">
                                Update Password
                            </button>
                        </form>
                    )}

                    {/* TAB 3: BANK ACCOUNTS */}
                    {activeTab === 'bank' && (
                        <div className="space-y-6">
                            <div className="flex items-center justify-between border-b border-[#1f2b3e] pb-4">
                                <div>
                                    <h3 className="text-lg font-black text-white">Bound Bank Accounts</h3>
                                    <p className="text-xs text-slate-400 mt-1">Bank details used for automated 1-click payouts.</p>
                                </div>
                                <button
                                    onClick={() => alert('Add bank account modal opened.')}
                                    className="bg-[#172233] hover:bg-[#1e2c42] border border-amber-500 text-amber-400 text-xs font-bold px-3 py-1.5 rounded-lg transition"
                                >
                                    + Add New Bank
                                </button>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="bg-[#172233] border border-emerald-500 rounded-xl p-4 flex flex-col justify-between">
                                    <div className="flex items-center justify-between">
                                        <span className="font-black text-purple-400 text-xs">SCB (Siam Commercial)</span>
                                        <span className="bg-emerald-500/15 border border-emerald-500 text-emerald-400 text-[10px] font-black px-2 py-0.5 rounded">Primary</span>
                                    </div>
                                    <div className="font-mono text-sm font-bold text-white my-3">408-••••-4210</div>
                                    <span className="text-[11px] text-slate-400">Account: Alex Thompson</span>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* TAB 4: BALANCE TRANSFER (Cash to Win / Win to Cash) */}
                    {activeTab === 'transfer' && (
                        <div className="space-y-6">
                            <div className="border-b border-[#1f2b3e] pb-4">
                                <h3 className="text-lg font-black text-white">Balance Transfer (โอนย้ายเงิน)</h3>
                                <p className="text-xs text-slate-400 mt-1">Swap funds between Cash Wallet (Betting) and Winning Wallet (Withdrawals).</p>
                            </div>

                            <div className="bg-[#0c121d] border border-[#1f2b3e] rounded-xl p-5 flex items-center justify-between gap-4">
                                <div className="flex-1 bg-[#172233] p-4 rounded-xl">
                                    <span className="text-[10px] text-slate-400 block font-bold uppercase">From Wallet</span>
                                    <span className="text-xs font-bold text-white block mt-1">
                                        {transferDirection === 'cash_to_win' ? 'Main Cash Wallet' : 'Winning Wallet'}
                                    </span>
                                    <span className="font-mono text-xs text-emerald-400 font-bold">
                                        {transferDirection === 'cash_to_win' ? `${user.cashBalance.toLocaleString()} THB` : `${user.winBalance.toLocaleString()} THB`}
                                    </span>
                                </div>

                                <button
                                    onClick={() => setTransferDirection((prev) => (prev === 'cash_to_win' ? 'win_to_cash' : 'cash_to_win'))}
                                    className="w-10 h-10 rounded-full bg-amber-500 text-slate-950 font-black flex items-center justify-center shadow-lg transition active:rotate-180"
                                >
                                    ⇄
                                </button>

                                <div className="flex-1 bg-[#172233] p-4 rounded-xl">
                                    <span className="text-[10px] text-slate-400 block font-bold uppercase">To Wallet</span>
                                    <span className="text-xs font-bold text-white block mt-1">
                                        {transferDirection === 'cash_to_win' ? 'Winning Wallet' : 'Main Cash Wallet'}
                                    </span>
                                    <span className="font-mono text-xs text-slate-300 font-bold">Instant Transfer</span>
                                </div>
                            </div>

                            <div className="space-y-2">
                                <label className="text-xs font-bold text-slate-300 block">Transfer Amount (THB)</label>
                                <input
                                    type="number"
                                    value={transferAmount}
                                    onChange={(e) => setTransferAmount(parseFloat(e.target.value) || 0)}
                                    className="w-full bg-[#0c121d] border border-[#1f2b3e] rounded-xl px-4 py-3 text-white font-mono font-bold text-base outline-none focus:border-amber-500"
                                />
                            </div>

                            <button
                                onClick={handleTransfer}
                                className="bg-gradient-to-r from-amber-500 to-amber-600 text-slate-950 font-black text-xs py-3.5 px-6 rounded-xl shadow-[0_0_20px_rgba(245,158,11,0.35)] transition"
                            >
                                Execute Balance Transfer
                            </button>
                        </div>
                    )}
                </div>
            </main>
        </div>
    );
};
