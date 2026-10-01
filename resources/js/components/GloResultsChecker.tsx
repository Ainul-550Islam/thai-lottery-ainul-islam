import React, { useState } from 'react';

export const GloResultsChecker: React.FC = () => {
    const [currentInput, setCurrentInput] = useState<string>('');
    const [modalData, setModalData] = useState<{ isOpen: boolean; won: boolean; tier?: string; amount?: string; message: string; number: string }>({
        isOpen: false,
        won: false,
        message: '',
        number: '',
    });

    const published = {
        drawNumber: '128',
        date: 'Oct 16, 2023',
        firstPrize: '724605',
        twoDigitBottom: '14',
        threeDigitTop: '605',
    };

    const handleKeyClick = (val: string) => {
        if (val === 'clear') {
            setCurrentInput('');
        } else if (val === 'check') {
            handleCheck();
        } else {
            if (currentInput.length < 6) {
                setCurrentInput((prev) => prev + val);
            }
        }
    };

    const handleCheck = () => {
        const num = currentInput.trim();
        if (num.length < 2) {
            alert('Please enter at least 2 to 6 digits to verify.');
            return;
        }

        if (num.length === 6 && num === published.firstPrize) {
            setModalData({
                isOpen: true,
                won: true,
                tier: 'First Prize (รางวัลที่ 1)',
                amount: 'THB 6,000,000',
                message: '🎉 CONGRATULATIONS! You won the First Prize jackpot!',
                number: num,
            });
        } else if (num.endsWith(published.threeDigitTop) || (num.length === 3 && num === published.threeDigitTop)) {
            setModalData({
                isOpen: true,
                won: true,
                tier: '3-Digit Top (3 ตัวบน)',
                amount: 'THB 4,000',
                message: '🎉 Congratulations! You matched the 3-Digit Top prize!',
                number: num,
            });
        } else if (num.endsWith(published.twoDigitBottom) || (num.length === 2 && num === published.twoDigitBottom)) {
            setModalData({
                isOpen: true,
                won: true,
                tier: '2-Digit Bottom (2 ตัวท้าย)',
                amount: 'THB 2,000',
                message: '🎉 Congratulations! You matched the 2-Digit Bottom prize!',
                number: num,
            });
        } else {
            setModalData({
                isOpen: true,
                won: false,
                message: 'No winning matches found for this draw.',
                number: num,
            });
        }
    };

    const displayFormatted = currentInput ? currentInput.padEnd(6, '-').split('').join(' ') : '- - - - - -';

    return (
        <div className="w-full max-w-6xl mx-auto py-8 px-4 text-slate-100 font-sans">
            {/* Top Official Results Hero Card */}
            <div className="bg-[#0f1724]/90 border border-[#d4af37]/40 rounded-2xl p-6 sm:p-8 text-center shadow-2xl mb-8 backdrop-blur">
                <h1 className="text-base sm:text-xl font-black text-[#fbbf24] uppercase tracking-wider mb-6">
                    OFFICIAL DRAW #{published.drawNumber} RESULTS — <span className="text-slate-200 font-semibold">Published ({published.date})</span>
                </h1>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-6 items-center">
                    {/* 1st Prize */}
                    <div className="flex flex-col items-center gap-2">
                        <span className="text-sm font-bold text-slate-200">1st Prize</span>
                        <div className="flex items-center gap-1.5 flex-wrap justify-center">
                            {published.firstPrize.split('').map((digit, idx) => (
                                <div
                                    key={idx}
                                    className="w-10 h-10 sm:w-11 sm:h-11 rounded-full bg-[radial-gradient(circle_at_35%_30%,#fff6cc_0%,#fbd45a_25%,#d99a18_60%,#875405_100%)] text-[#1a0f00] font-black text-lg sm:text-xl flex items-center justify-center shadow-lg shadow-amber-500/30"
                                >
                                    {digit}
                                </div>
                            ))}
                        </div>
                        <span className="text-sm font-extrabold text-white">THB 6,000,000</span>
                    </div>

                    {/* 2-Digit Bottom */}
                    <div className="flex flex-col items-center gap-2">
                        <span className="text-sm font-bold text-slate-200">2-Digit Bottom</span>
                        <div className="flex items-center gap-1.5 justify-center">
                            {published.twoDigitBottom.split('').map((digit, idx) => (
                                <div
                                    key={idx}
                                    className="w-10 h-10 sm:w-11 sm:h-11 rounded-full bg-[radial-gradient(circle_at_35%_30%,#fff6cc_0%,#fbd45a_25%,#d99a18_60%,#875405_100%)] text-[#1a0f00] font-black text-lg sm:text-xl flex items-center justify-center shadow-lg shadow-amber-500/30"
                                >
                                    {digit}
                                </div>
                            ))}
                        </div>
                        <span className="text-sm font-extrabold text-white">THB 2,000</span>
                    </div>

                    {/* 3-Digit Top */}
                    <div className="flex flex-col items-center gap-2">
                        <span className="text-sm font-bold text-slate-200">3-Digit Top</span>
                        <div className="flex items-center gap-1.5 justify-center">
                            {published.threeDigitTop.split('').map((digit, idx) => (
                                <div
                                    key={idx}
                                    className="w-10 h-10 sm:w-11 sm:h-11 rounded-full bg-[radial-gradient(circle_at_35%_30%,#fff6cc_0%,#fbd45a_25%,#d99a18_60%,#875405_100%)] text-[#1a0f00] font-black text-lg sm:text-xl flex items-center justify-center shadow-lg shadow-amber-500/30"
                                >
                                    {digit}
                                </div>
                            ))}
                        </div>
                        <span className="text-sm font-extrabold text-white">THB 4,000</span>
                    </div>
                </div>
            </div>

            {/* Bottom Two-Column Section */}
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 mb-8">
                {/* Left Card: Check Your Ticket */}
                <div className="lg:col-span-5 bg-[#0f1724]/90 border border-[#d4af37]/30 rounded-2xl p-6 shadow-xl flex flex-col justify-between">
                    <div>
                        <h2 className="text-base font-black text-[#fbbf24] uppercase text-center mb-1">
                            CHECK YOUR TICKET
                        </h2>
                        <p className="text-xs text-slate-400 text-center mb-4">
                            Enter 6-digit or 3-digit Number
                        </p>

                        {/* Screen */}
                        <div className="bg-[#080d14] border border-[#d4af37]/40 rounded-xl py-3 text-center font-mono font-black text-xl text-[#fbbf24] tracking-widest mb-4">
                            {displayFormatted}
                        </div>

                        {/* Keypad */}
                        <div className="grid grid-cols-5 gap-2 mb-4">
                            {['1', '2', '3', '4', '5'].map((d) => (
                                <button
                                    key={d}
                                    onClick={() => handleKeyClick(d)}
                                    className="py-2.5 rounded-lg bg-[#16202e] hover:bg-[#223145] text-white font-bold text-sm border border-[#28394e]"
                                >
                                    {d}
                                </button>
                            ))}
                            {['7', '8', '9', 'clear', 'check'].map((k) => (
                                <button
                                    key={k}
                                    onClick={() => handleKeyClick(k)}
                                    className={`py-2.5 rounded-lg font-bold text-sm border border-[#28394e] ${
                                        k === 'clear'
                                            ? 'bg-[#16202e] text-[#fbbf24]'
                                            : k === 'check'
                                            ? 'bg-[#16202e] text-[#34d399]'
                                            : 'bg-[#16202e] hover:bg-[#223145] text-white'
                                    }`}
                                >
                                    {k === 'clear' ? 'Clear' : k === 'check' ? 'Check' : k}
                                </button>
                            ))}
                        </div>
                    </div>

                    <button
                        onClick={handleCheck}
                        className="w-full py-3 rounded-xl bg-gradient-to-r from-[#fce96a] via-[#e3b838] to-[#b8860b] text-[#1a0f00] font-black text-sm tracking-wide shadow-lg shadow-amber-500/30 hover:brightness-105 transition"
                    >
                        INSTANTLY CHECK TICKET 🔍
                    </button>
                </div>

                {/* Right Card: Prize Payout Multipliers */}
                <div className="lg:col-span-7 bg-[#0f1724]/90 border border-[#d4af37]/30 rounded-2xl p-6 shadow-xl">
                    <h2 className="text-base font-black text-[#fbbf24] uppercase text-center mb-4">
                        PRIZE PAYOUT MULTIPLIERS
                    </h2>

                    <div className="overflow-x-auto border border-[#d4af37]/20 rounded-xl bg-[#080d14]">
                        <table className="w-full text-left text-xs">
                            <thead className="bg-[#121b27] text-[#fbbf24] font-bold border-b border-[#d4af37]/25">
                                <tr>
                                    <th className="p-2.5">Prize Type</th>
                                    <th className="p-2.5">Multiplier (x)</th>
                                    <th className="p-2.5">Winning Match</th>
                                    <th className="p-2.5">Prize (THB per 100)</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[#162232] text-slate-200">
                                <tr>
                                    <td className="p-2.5 font-bold">3D Direct</td>
                                    <td className="p-2.5 text-[#fbbf24] font-mono">900x</td>
                                    <td className="p-2.5">Exact Match 3 Top</td>
                                    <td className="p-2.5 font-bold">THB 90,000</td>
                                </tr>
                                <tr>
                                    <td className="p-2.5 font-bold">3D Tod</td>
                                    <td className="p-2.5 text-[#fbbf24] font-mono">45x</td>
                                    <td className="p-2.5">Any Order 3 Top</td>
                                    <td className="p-2.5 font-bold">THB 4,500</td>
                                </tr>
                                <tr>
                                    <td className="p-2.5 font-bold">2D Top</td>
                                    <td className="p-2.5 text-[#fbbf24] font-mono">90x</td>
                                    <td className="p-2.5">Last 2 Digits Top</td>
                                    <td className="p-2.5 font-bold">THB 9,000</td>
                                </tr>
                                <tr>
                                    <td className="p-2.5 font-bold">2D Bottom</td>
                                    <td className="p-2.5 text-[#fbbf24] font-mono">90x</td>
                                    <td className="p-2.5">Last 2 Digits Bottom</td>
                                    <td className="p-2.5 font-bold">THB 9,000</td>
                                </tr>
                                <tr>
                                    <td className="p-2.5 font-bold">Run Top</td>
                                    <td className="p-2.5 text-[#fbbf24] font-mono">3x</td>
                                    <td className="p-2.5">One Digit 3 Top</td>
                                    <td className="p-2.5 font-bold">THB 300</td>
                                </tr>
                                <tr>
                                    <td className="p-2.5 font-bold">Run Bottom</td>
                                    <td className="p-2.5 text-[#fbbf24] font-mono">3x</td>
                                    <td className="p-2.5">One Digit 2 Bottom</td>
                                    <td className="p-2.5 font-bold">THB 300</td>
                                </tr>
                                <tr>
                                    <td className="p-2.5 font-bold">Run Bottom</td>
                                    <td className="p-2.5 text-[#fbbf24] font-mono">3x</td>
                                    <td className="p-2.5">One Digit 2 Bottom</td>
                                    <td className="p-2.5 font-bold">THB 300</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {/* Result Modal */}
            {modalData.isOpen && (
                <div className="fixed inset-0 bg-black/80 flex items-center justify-center p-4 z-50">
                    <div className="bg-[#0f1724] border border-[#d4af37] rounded-2xl max-w-sm w-full p-6 text-center space-y-4 shadow-2xl">
                        <h3 className="text-lg font-black text-[#fbbf24]">
                            {modalData.won ? '🏆 Official Prize Winning!' : 'Ticket Verification Result'}
                        </h3>
                        <div className="font-mono text-2xl font-black text-white tracking-widest">{modalData.number}</div>
                        <p className={`text-sm ${modalData.won ? 'text-[#34d399] font-bold' : 'text-slate-300'}`}>{modalData.message}</p>
                        {modalData.tier && (
                            <div className="bg-[#080d14] p-3 rounded-xl border border-[#d4af37]/30">
                                <span className="text-xs text-slate-400 block">{modalData.tier}</span>
                                <span className="text-xl font-black text-[#34d399]">{modalData.amount}</span>
                            </div>
                        )}
                        <button
                            onClick={() => setModalData({ ...modalData, isOpen: false })}
                            className="w-full py-2.5 bg-[#d4af37] text-slate-950 font-bold text-xs rounded-xl"
                        >
                            Close
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
};
