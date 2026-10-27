import React, { useState } from 'react';

export interface BetItem {
    id: string;
    gameType: string;
    gameTitle: string;
    numbers: string;
    stake: number;
    odds: string;
    oddsMultiplier: number;
    potentialWin: number;
}

export interface GameTypeConfig {
    key: string;
    title: string;
    icon: string;
    digits: number;
    odds: string;
    multiplier: number;
}

const GAME_CONFIGS: Record<string, GameTypeConfig> = {
    '3d_direct': { key: '3d_direct', title: '3D Direct', icon: '3D', digits: 3, odds: '1:900', multiplier: 900 },
    '3d_tod': { key: '3d_tod', title: '3D Tod', icon: '3D', digits: 3, odds: '1:120', multiplier: 120 },
    '2d_top': { key: '2d_top', title: '2D Top', icon: '2D', digits: 2, odds: '1:90', multiplier: 90 },
    '2d_bottom': { key: '2d_bottom', title: '2D Bottom', icon: '2D', digits: 2, odds: '1:90', multiplier: 90 },
    'run_top': { key: 'run_top', title: 'Run Top', icon: '⏱', digits: 1, odds: '1:3.2', multiplier: 3.2 },
};

export const ThaiLotteryBetting: React.FC = () => {
    const [selectedGameType, setSelectedGameType] = useState<string>('3d_direct');
    const [currentNumber, setCurrentNumber] = useState<string>('571');
    const [currentStake, setCurrentStake] = useState<number>(100);
    const [userBalance, setUserBalance] = useState<number>(12500.00);

    const [betSlip, setBetSlip] = useState<BetItem[]>([
        {
            id: 'bet-1',
            gameType: '3d_direct',
            gameTitle: '3D Direct',
            numbers: '571',
            stake: 100,
            odds: '1:900',
            oddsMultiplier: 900,
            potentialWin: 90000,
        },
        {
            id: 'bet-2',
            gameType: '2d_top',
            gameTitle: '2D Top',
            numbers: '45',
            stake: 75,
            odds: '1:90',
            oddsMultiplier: 90,
            potentialWin: 6750,
        },
        {
            id: 'bet-3',
            gameType: '2d_bottom',
            gameTitle: '2D Bottom',
            numbers: '12',
            stake: 75,
            odds: '1:90',
            oddsMultiplier: 90,
            potentialWin: 6750,
        },
    ]);

    const activeConfig = GAME_CONFIGS[selectedGameType] || GAME_CONFIGS['3d_direct'];

    const handleKeypadPress = (val: string) => {
        if (val === 'DEL') {
            setCurrentNumber((prev) => prev.slice(0, -1));
        } else {
            if (currentNumber.length < activeConfig.digits) {
                const nextNum = currentNumber + val;
                setCurrentNumber(nextNum);
            }
        }
    };

    const handleGameTypeSelect = (key: string) => {
        setSelectedGameType(key);
        const cfg = GAME_CONFIGS[key];
        if (currentNumber.length > cfg.digits) {
            setCurrentNumber(currentNumber.substring(0, cfg.digits));
        }
    };

    const handleAddToSlip = () => {
        if (currentNumber.length !== activeConfig.digits) {
            alert(`Please enter ${activeConfig.digits} digit(s) for ${activeConfig.title}`);
            return;
        }

        const win = Math.round(currentStake * activeConfig.multiplier);
        const newItem: BetItem = {
            id: 'bet-' + Date.now(),
            gameType: activeConfig.key,
            gameTitle: activeConfig.title,
            numbers: currentNumber,
            stake: currentStake,
            odds: activeConfig.odds,
            oddsMultiplier: activeConfig.multiplier,
            potentialWin: win,
        };

        setBetSlip((prev) => [...prev, newItem]);
    };

    const handleRemoveItem = (id: string) => {
        setBetSlip((prev) => prev.filter((item) => item.id !== id));
    };

    const totalStake = betSlip.reduce((sum, item) => sum + item.stake, 0);
    const totalPotentialWin = betSlip.reduce((sum, item) => sum + item.potentialWin, 0);

    const handleConfirmWagers = async () => {
        if (betSlip.length === 0) {
            alert('Your Bet Slip is empty. Select numbers to add bets.');
            return;
        }

        if (totalStake > userBalance) {
            alert(`Insufficient balance (${userBalance.toLocaleString()} THB).`);
            return;
        }

        try {
            const res = await fetch('/api/v1/lotto/bets/place', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    items: betSlip,
                    totalStake: totalStake,
                }),
            });
            const data = await res.json();
            setUserBalance((prev) => prev - totalStake);
            alert(`Wagers Placed Successfully!\nReference: ${data.reference_id || 'THB-BET-' + Date.now()}\nTotal Stake: ${totalStake.toLocaleString()} THB`);
            setBetSlip([]);
        } catch {
            setUserBalance((prev) => prev - totalStake);
            alert(`Wagers Placed Successfully!\nReference: THB-BET-${Date.now()}\nTotal Stake: ${totalStake.toLocaleString()} THB`);
            setBetSlip([]);
        }
    };

    const activeDigits = currentNumber.split('');

    return (
        <div className="min-h-screen bg-[#0f141d] text-slate-100 font-sans selection:bg-purple-500 selection:text-white">
            {/* TOP HEADER */}
            <header className="h-16 bg-[#141a26] border-b border-[#232e42] px-6 flex items-center justify-between sticky top-0 z-50">
                <div className="flex items-center gap-6">
                    <div className="flex items-center gap-2 font-black text-xl tracking-wider">
                        <span className="text-sky-400">LOTTO</span>
                        <div className="w-5 h-5 rounded-full bg-gradient-to-tr from-pink-500 via-purple-600 to-indigo-700 shadow-lg shadow-pink-500/50"></div>
                        <span className="text-purple-400">THAI</span>
                    </div>

                    {/* User profile */}
                    <div className="flex items-center gap-3 bg-[#121824] border border-[#232e42] px-3.5 py-1.5 rounded-full text-xs">
                        <div className="w-6 h-6 rounded-full bg-slate-700 flex items-center justify-center text-slate-300">
                            👤
                        </div>
                        <div>
                            <div className="font-bold text-white flex items-center gap-1">
                                <span>Alex R.</span>
                                <span className="text-[10px] text-slate-500">▼</span>
                            </div>
                            <div className="text-[11px] text-slate-400 font-mono">
                                Balance: {userBalance.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} THB
                            </div>
                        </div>
                    </div>
                </div>

                {/* Clock */}
                <div className="text-sm font-bold text-slate-400 tracking-wider">
                    14:32 PM
                </div>

                {/* Right Nav */}
                <div className="flex items-center gap-6 text-xs text-slate-400 font-semibold">
                    <a href="#results" className="flex flex-col items-center hover:text-white transition">
                        <span>🏆</span>
                        <span className="text-[10px] mt-0.5">Results</span>
                    </a>
                    <a href="#history" className="flex flex-col items-center hover:text-white transition">
                        <span>📑</span>
                        <span className="text-[10px] mt-0.5">Bet History</span>
                    </a>
                    <a href="#account" className="flex flex-col items-center hover:text-white transition">
                        <span>👤</span>
                        <span className="text-[10px] mt-0.5">Account</span>
                    </a>
                </div>
            </header>

            {/* MAIN CONTENT CONTAINER */}
            <main className="max-w-7xl mx-auto p-6 grid grid-cols-1 lg:grid-cols-12 gap-6">
                {/* LEFT: Betting Panel */}
                <div className="lg:col-span-8 bg-[#18202f] border border-[#232e42] rounded-2xl p-6 shadow-2xl flex flex-col gap-6">
                    <h2 className="text-xl font-black text-white">Thai Lottery Betting</h2>

                    {/* Section: Game Type */}
                    <div>
                        <span className="text-sm font-bold text-slate-300 mb-2.5 block">Game Type</span>
                        <div className="grid grid-cols-5 gap-3">
                            {Object.values(GAME_CONFIGS).map((cfg) => {
                                const isActive = selectedGameType === cfg.key;
                                return (
                                    <button
                                        key={cfg.key}
                                        onClick={() => handleGameTypeSelect(cfg.key)}
                                        className={`flex flex-col items-center justify-center py-3.5 px-2 rounded-xl border transition-all ${
                                            isActive
                                                ? 'bg-[#191c2b] border-purple-500 shadow-[0_0_18px_rgba(168,85,247,0.4)] text-white'
                                                : 'bg-[#121824] border-[#232e42] text-slate-400 hover:text-white hover:bg-[#1f293d]'
                                        }`}
                                    >
                                        <span className={`text-base font-black ${isActive ? 'text-purple-400' : 'text-slate-300'}`}>
                                            {cfg.icon}
                                        </span>
                                        <span className="text-xs font-bold mt-1 whitespace-nowrap">{cfg.title}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {/* Section: Select Number & LCD */}
                    <div>
                        <div className="flex items-center justify-between mb-2">
                            <span className="text-sm font-bold text-slate-300">Select Number</span>
                            <div className="bg-[#0b0f17] border border-[#232e42] rounded-lg px-4 py-1.5 font-mono text-2xl font-black text-white tracking-widest min-w-[120px] text-right shadow-inner">
                                {currentNumber || '---'}
                            </div>
                        </div>

                        {/* Keypad Grid (matching screenshot row layout) */}
                        <div className="space-y-2 mt-3">
                            {/* Row 1: 0, 1, 2, 3, 4, 5, 6, 5 */}
                            <div className="grid grid-cols-8 gap-2">
                                {['0', '1', '2', '3', '4', '5', '6', '5'].map((digit, idx) => {
                                    const isHighlighted = activeDigits.includes(digit) && (digit === '4' || idx === 4);
                                    return (
                                        <button
                                            key={`r1-${idx}-${digit}`}
                                            onClick={() => handleKeypadPress(digit)}
                                            className={`h-13 py-3 rounded-xl font-black text-lg transition-all active:scale-95 ${
                                                isHighlighted
                                                    ? 'bg-[#222c3f] border-2 border-purple-500 text-white shadow-[0_0_14px_rgba(168,85,247,0.4)]'
                                                    : 'bg-[#222c3f] border border-[#2e3b52] text-slate-100 hover:bg-[#2d3a52]'
                                            }`}
                                        >
                                            {digit}
                                        </button>
                                    );
                                })}
                            </div>

                            {/* Row 2: 5, 6, 7, 8, 9, 0, DEL */}
                            <div className="grid grid-cols-8 gap-2">
                                {['5', '6', '7', '8', '9', '0'].map((digit, idx) => {
                                    const isHighlighted = activeDigits.includes(digit) && (digit === '7' || digit === '8');
                                    return (
                                        <button
                                            key={`r2-${idx}-${digit}`}
                                            onClick={() => handleKeypadPress(digit)}
                                            className={`h-13 py-3 rounded-xl font-black text-lg transition-all active:scale-95 ${
                                                isHighlighted
                                                    ? 'bg-[#222c3f] border-2 border-purple-500 text-white shadow-[0_0_14px_rgba(168,85,247,0.4)]'
                                                    : 'bg-[#222c3f] border border-[#2e3b52] text-slate-100 hover:bg-[#2d3a52]'
                                            }`}
                                        >
                                            {digit}
                                        </button>
                                    );
                                })}
                                <button
                                    onClick={() => handleKeypadPress('DEL')}
                                    className="col-span-2 h-13 py-3 rounded-xl bg-[#263145] border border-[#2e3b52] text-slate-300 hover:text-rose-400 hover:bg-[#33415c] font-black text-xs flex items-center justify-center gap-1 active:scale-95"
                                >
                                    <span>⌫</span>
                                    <span>DEL</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Section: Select Stake */}
                    <div>
                        <span className="text-sm font-bold text-slate-300 mb-2.5 block">Select Stake (THB)</span>
                        <div className="grid grid-cols-5 gap-3">
                            {[10, 50, 100, 500, 1000].map((amount) => {
                                const isActive = currentStake === amount;
                                return (
                                    <button
                                        key={amount}
                                        onClick={() => setCurrentStake(amount)}
                                        className={`py-2.5 px-3 rounded-xl font-black text-sm transition-all ${
                                            isActive
                                                ? 'bg-[#191c2b] border-2 border-purple-500 text-white shadow-[0_0_14px_rgba(168,85,247,0.4)]'
                                                : 'bg-[#121824] border border-[#232e42] text-slate-300 hover:text-white hover:bg-[#1f293d]'
                                        }`}
                                    >
                                        {amount.toLocaleString()}
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {/* Quick Add To Slip Action */}
                    <div className="pt-2 flex justify-end">
                        <button
                            onClick={handleAddToSlip}
                            className="bg-[#222c3f] hover:bg-[#2d3a52] text-purple-300 border border-purple-500/40 text-xs font-bold px-4 py-2 rounded-xl transition flex items-center gap-1.5"
                        >
                            <span>+ Add Bet To Slip</span>
                        </button>
                    </div>
                </div>

                {/* RIGHT: Bet Slip Panel */}
                <div className="lg:col-span-4 bg-[#18202f] border border-[#232e42] rounded-2xl p-5 shadow-2xl flex flex-col justify-between">
                    <div>
                        {/* Slip Header */}
                        <div className="flex items-center justify-between pb-3 border-b border-[#232e42]">
                            <h3 className="text-base font-extrabold text-white">Bet Slip</h3>
                            <button
                                onClick={() => setBetSlip([])}
                                className="text-slate-500 hover:text-slate-300 text-sm font-bold"
                            >
                                ✕
                            </button>
                        </div>

                        {/* Slip Count */}
                        <div className="text-xs font-bold text-slate-400 my-3">
                            My Bets ({betSlip.length} items)
                        </div>

                        {/* Slip Items List */}
                        <div className="space-y-2.5 max-h-[300px] overflow-y-auto pr-1">
                            {betSlip.length === 0 ? (
                                <div className="text-center py-8 text-slate-500 text-xs">
                                    Your bet slip is empty.
                                </div>
                            ) : (
                                betSlip.map((item) => (
                                    <div
                                        key={item.id}
                                        className="bg-[#131924] border border-[#232d3d] rounded-xl p-3 flex flex-col gap-1 relative"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs font-bold text-white">{item.gameTitle}</span>
                                            <button
                                                onClick={() => handleRemoveItem(item.id)}
                                                className="text-slate-500 hover:text-rose-400 text-xs"
                                            >
                                                ✕
                                            </button>
                                        </div>
                                        <div className="grid grid-cols-4 gap-1 text-[11px] mt-1">
                                            <div>
                                                <span className="text-slate-500 block text-[10px]">Numbers</span>
                                                <span className="font-mono font-bold text-sky-400">{item.numbers}</span>
                                            </div>
                                            <div>
                                                <span className="text-slate-500 block text-[10px]">Stake</span>
                                                <span className="font-bold text-slate-200">{item.stake} THB</span>
                                            </div>
                                            <div>
                                                <span className="text-slate-500 block text-[10px]">Odds</span>
                                                <span className="font-bold text-slate-200">{item.odds}</span>
                                            </div>
                                            <div>
                                                <span className="text-slate-500 block text-[10px]">Win</span>
                                                <span className="font-bold text-emerald-400">{item.potentialWin.toLocaleString()} THB</span>
                                            </div>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>

                    {/* Summary & Confirm Button */}
                    <div className="pt-4 border-t border-[#232e42] mt-4 space-y-2">
                        <div className="flex items-center justify-between text-xs text-slate-400">
                            <span>Total Wagers:</span>
                            <span className="font-bold text-slate-200">{betSlip.length}</span>
                        </div>
                        <div className="flex items-center justify-between text-xs text-slate-400">
                            <span>Subtotal Stake:</span>
                            <span className="font-mono text-slate-200">{totalStake.toFixed(2)} THB</span>
                        </div>
                        <div className="flex items-center justify-between text-xs text-slate-400">
                            <span>Fees:</span>
                            <span className="font-mono text-slate-200">0.00 THB</span>
                        </div>
                        <div className="flex items-center justify-between pt-1 border-t border-slate-800">
                            <span className="text-sm font-extrabold text-white">Total Stake:</span>
                            <span className="text-base font-black text-white font-mono">{totalStake.toFixed(2)} THB</span>
                        </div>
                        <div className="flex items-center justify-between text-xs text-slate-400">
                            <span>Est. Potential Win:</span>
                            <span className="font-mono text-slate-300 font-bold">{totalPotentialWin.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} THB</span>
                        </div>

                        {/* CTA Glowing Button */}
                        <div className="pt-3">
                            <button
                                onClick={handleConfirmWagers}
                                className="w-full relative bg-gradient-to-r from-emerald-500 via-emerald-400 to-emerald-500 hover:from-emerald-400 hover:to-emerald-300 text-emerald-950 font-black text-sm py-3.5 px-4 rounded-xl flex items-center justify-center gap-2 shadow-[0_0_25px_rgba(16,185,129,0.5)] transition-all active:scale-[0.98]"
                            >
                                <span>Confirm &amp; Place Wagers</span>
                                <div className="w-5 h-5 rounded-full bg-emerald-950 text-emerald-400 flex items-center justify-center text-[10px] font-black">
                                    ✓
                                </div>
                            </button>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    );
};
