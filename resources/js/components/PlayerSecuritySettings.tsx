import React, { useState } from 'react';

export const PlayerSecuritySettings: React.FC = () => {
    const [dailyDeposit, setDailyDeposit] = useState<number>(5000);
    const [singleBet, setSingleBet] = useState<number>(1000);
    const [dailyWager, setDailyWager] = useState<number>(10000);
    const [selfExclusionActive, setSelfExclusionActive] = useState<boolean>(false);
    const [activeBreak, setActiveBreak] = useState<string>('None');

    const handleEditPrompt = (key: 'deposit' | 'bet' | 'wager') => {
        let current = dailyDeposit;
        let max = 20000;
        if (key === 'bet') {
            current = singleBet;
            max = 5000;
        } else if (key === 'wager') {
            current = dailyWager;
            max = 50000;
        }

        const input = prompt(`Enter new limit (Max: ${max.toLocaleString()} THB):`, current.toString());
        if (input !== null) {
            const parsed = parseInt(input.replace(/[^0-9]/g, ''), 10);
            if (!isNaN(parsed) && parsed > 0 && parsed <= max) {
                if (key === 'deposit') setDailyDeposit(parsed);
                if (key === 'bet') setSingleBet(parsed);
                if (key === 'wager') setDailyWager(parsed);
            }
        }
    };

    const handleTakeBreak = (duration: string) => {
        const pin = prompt(`Take a ${duration} break? Enter your 4-digit security PIN to confirm:`);
        if (pin && pin.length >= 4) {
            setActiveBreak(duration);
            alert(`Your ${duration} cool-off period has been activated.`);
        }
    };

    return (
        <div className="w-full max-w-6xl mx-auto py-6 text-slate-100 font-sans">
            <h1 className="text-2xl font-black text-white mb-6">Player Security &amp; Settings</h1>

            <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                {/* Left Card: Identity & KYC Verification */}
                <div className="lg:col-span-5 bg-[#121c2b] border border-[#1f2d42] rounded-2xl p-6 flex flex-col justify-between shadow-xl">
                    <div className="space-y-4">
                        <div className="flex items-center gap-2 text-white font-bold text-base">
                            <span className="text-slate-400">👤</span>
                            <span>Identity &amp; KYC Verification</span>
                        </div>

                        {/* Status Verified Banner */}
                        <div className="bg-[#064e3b]/40 border border-[#10b981]/40 rounded-xl py-2.5 px-4 flex items-center justify-center gap-2 text-[#34d399] font-black text-xs uppercase tracking-wider">
                            <span>✔</span>
                            <span>STATUS: VERIFIED</span>
                        </div>

                        {/* Document Details */}
                        <div className="text-xs space-y-0.5">
                            <p className="font-bold text-white">Passport - Thai, | No. AA1234567</p>
                            <p className="text-slate-400">Status: Verified on 14 Nov 2023</p>
                        </div>

                        {/* Document Thumbnail Preview */}
                        <div className="flex flex-col items-center gap-1.5 py-2">
                            <div className="relative rounded-xl overflow-hidden border border-slate-700 max-w-[240px] shadow-lg">
                                <img
                                    src="https://images.unsplash.com/photo-1578632767115-351597cf2477?w=400&auto=format&fit=crop&q=80"
                                    alt="Uploaded ID Card"
                                    className="w-full h-auto object-cover opacity-90"
                                />
                                <div className="absolute top-1 right-1 w-5 h-5 rounded-full bg-[#10b981] text-slate-950 font-black text-[10px] flex items-center justify-center shadow">
                                    ✔
                                </div>
                            </div>
                            <span className="text-[11px] text-slate-400">Uploaded ID Preview (Verified)</span>
                        </div>

                        {/* Verified Status Note */}
                        <div className="space-y-0.5 text-xs">
                            <p className="font-bold text-white">Verified Status</p>
                            <p className="text-slate-400 flex items-center gap-1.5">
                                <span className="w-2 h-2 rounded-full bg-[#10b981]"></span>
                                <span>Identity is fully verified. Your account is secure.</span>
                            </p>
                        </div>
                    </div>

                    <button
                        onClick={() => alert('Document re-upload desk opened.')}
                        className="w-full mt-4 py-2.5 rounded-xl border border-[#10b981]/50 text-[#34d399] font-bold text-xs hover:bg-[#10b981]/10 transition"
                    >
                        Re-Upload Documents
                    </button>
                </div>

                {/* Right Card: Responsible Gaming & Player Protection */}
                <div className="lg:col-span-7 bg-[#121c2b] border border-[#1f2d42] rounded-2xl p-6 flex flex-col justify-between shadow-xl space-y-5">
                    <div className="flex items-center gap-2 text-white font-bold text-base">
                        <span className="text-slate-400">⚙</span>
                        <span>Responsible Gaming &amp; Player Protection</span>
                    </div>

                    {/* Limit 1: Daily Deposit Limit */}
                    <div className="space-y-1">
                        <span className="text-xs font-bold text-white">Daily Deposit Limit</span>
                        <div className="flex items-center gap-4">
                            <div className="flex-1">
                                <input
                                    type="range"
                                    min="500"
                                    max="20000"
                                    step="500"
                                    value={dailyDeposit}
                                    onChange={(e) => setDailyDeposit(parseInt(e.target.value, 10))}
                                    className="w-full h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer accent-[#10b981]"
                                />
                                <span className="text-[10px] text-slate-500">Set to</span>
                            </div>
                            <div className="flex flex-col items-end">
                                <div className="flex items-center gap-2 bg-[#0d1624] border border-[#1f2d42] px-3 py-1 rounded-lg">
                                    <span className="font-mono text-xs font-bold text-white">{dailyDeposit.toLocaleString()}</span>
                                    <span className="text-[10px] text-slate-400">THB</span>
                                    <button
                                        onClick={() => handleEditPrompt('deposit')}
                                        className="text-[10px] text-[#34d399] border border-[#10b981]/40 px-1.5 py-0.5 rounded hover:bg-[#10b981]/15"
                                    >
                                        Edit
                                    </button>
                                </div>
                                <span className="text-[10px] text-slate-500">20,000 Max</span>
                            </div>
                        </div>
                    </div>

                    {/* Limit 2: Single Bet Limit */}
                    <div className="space-y-1">
                        <span className="text-xs font-bold text-white">Single Bet Limit</span>
                        <div className="flex items-center gap-4">
                            <div className="flex-1">
                                <input
                                    type="range"
                                    min="100"
                                    max="5000"
                                    step="100"
                                    value={singleBet}
                                    onChange={(e) => setSingleBet(parseInt(e.target.value, 10))}
                                    className="w-full h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer accent-[#10b981]"
                                />
                                <span className="text-[10px] text-slate-500">Set to</span>
                            </div>
                            <div className="flex flex-col items-end">
                                <div className="flex items-center gap-2 bg-[#0d1624] border border-[#1f2d42] px-3 py-1 rounded-lg">
                                    <span className="font-mono text-xs font-bold text-white">{singleBet.toLocaleString()}</span>
                                    <span className="text-[10px] text-slate-400">THB</span>
                                    <button
                                        onClick={() => handleEditPrompt('bet')}
                                        className="text-[10px] text-[#34d399] border border-[#10b981]/40 px-1.5 py-0.5 rounded hover:bg-[#10b981]/15"
                                    >
                                        Edit
                                    </button>
                                </div>
                                <span className="text-[10px] text-slate-500">5,000 Max</span>
                            </div>
                        </div>
                    </div>

                    {/* Limit 3: Daily Wagering Limit */}
                    <div className="space-y-1">
                        <span className="text-xs font-bold text-white">Daily Wagering Limit</span>
                        <div className="flex items-center gap-4">
                            <div className="flex-1">
                                <input
                                    type="range"
                                    min="1000"
                                    max="50000"
                                    step="1000"
                                    value={dailyWager}
                                    onChange={(e) => setDailyWager(parseInt(e.target.value, 10))}
                                    className="w-full h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer accent-[#10b981]"
                                />
                                <span className="text-[10px] text-slate-500">Set to</span>
                            </div>
                            <div className="flex flex-col items-end">
                                <div className="flex items-center gap-2 bg-[#0d1624] border border-[#1f2d42] px-3 py-1 rounded-lg">
                                    <span className="font-mono text-xs font-bold text-white">{dailyWager.toLocaleString()}</span>
                                    <span className="text-[10px] text-slate-400">THB</span>
                                    <button
                                        onClick={() => handleEditPrompt('wager')}
                                        className="text-[10px] text-[#34d399] border border-[#10b981]/40 px-1.5 py-0.5 rounded hover:bg-[#10b981]/15"
                                    >
                                        Edit
                                    </button>
                                </div>
                                <span className="text-[10px] text-slate-500">50,000 Max</span>
                            </div>
                        </div>
                    </div>

                    {/* Self-Exclusion & Cool-Off Period */}
                    <div className="pt-3 border-t border-slate-800 space-y-3">
                        <span className="text-xs font-bold text-white block">Self-Exclusion &amp; Cool-Off Period</span>

                        <div className="flex items-center justify-between">
                            <label className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={selfExclusionActive}
                                    onChange={(e) => {
                                        if (e.target.checked) {
                                            const pin = prompt('Enter 4-digit PIN to activate Self-Exclusion:');
                                            if (pin && pin.length >= 4) {
                                                setSelfExclusionActive(true);
                                            }
                                        } else {
                                            setSelfExclusionActive(false);
                                        }
                                    }}
                                    className="rounded bg-slate-900 border-slate-700 text-rose-500 focus:ring-0"
                                />
                                <span className="text-xs font-bold text-slate-400">
                                    {selfExclusionActive ? 'ON' : 'OFF'}
                                </span>
                            </label>

                            <button
                                type="button"
                                onClick={() => alert('PIN confirmation is required to alter exclusion horizons.')}
                                className="bg-[#0369a1] hover:bg-[#0284c7] text-white text-xs font-bold px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition"
                            >
                                <span>🔒</span>
                                <span>Requires PIN Confirmation</span>
                            </button>
                        </div>

                        <div className="flex items-center justify-between bg-[#0d1624] p-3 rounded-xl border border-slate-800">
                            <div>
                                <span className="text-[10px] text-slate-500 block">Active Break</span>
                                <span className="text-xs font-bold text-white">{activeBreak}</span>
                            </div>

                            <div className="flex items-center gap-1.5">
                                <span className="text-xs text-slate-400 mr-1">Take a break:</span>
                                {['24hrs', '7 Days', '30 Days'].map((d) => (
                                    <button
                                        key={d}
                                        onClick={() => handleTakeBreak(d)}
                                        className="bg-slate-800 hover:bg-slate-700 text-slate-200 hover:text-sky-400 text-xs font-bold px-2.5 py-1 rounded-lg border border-slate-700 transition"
                                    >
                                        {d}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};
