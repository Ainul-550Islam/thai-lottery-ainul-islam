import React, { useState, useEffect } from 'react';

export const PlayerSettings: React.FC = () => {
    const [activeTab, setActiveTab] = useState<'general' | 'security' | 'betting' | 'notifications' | 'responsible'>('general');
    const [language, setLanguage] = useState<string>('th');
    const [theme, setTheme] = useState<string>('dark_gold');
    const [timezone, setTimezone] = useState<string>('Asia/Bangkok');
    const [oddsFormat, setOddsFormat] = useState<string>('multiplier');

    const [twoFactorEnabled, setTwoFactorEnabled] = useState<boolean>(false);
    const [sessionTimeout, setSessionTimeout] = useState<number>(30);
    const [loginAlertEmail, setLoginAlertEmail] = useState<boolean>(true);
    const [loginAlertLine, setLoginAlertLine] = useState<boolean>(true);
    const [biometricLogin, setBiometricLogin] = useState<boolean>(true);

    const [fastBetMode, setFastBetMode] = useState<boolean>(false);
    const [soundEffects, setSoundEffects] = useState<boolean>(true);
    const [defaultPermutation, setDefaultPermutation] = useState<string>('standard');
    const [autoClearBetSlip, setAutoClearBetSlip] = useState<boolean>(true);

    const [prizeAlertSms, setPrizeAlertSms] = useState<boolean>(true);
    const [prizeAlertEmail, setPrizeAlertEmail] = useState<boolean>(true);
    const [prizeAlertLine, setPrizeAlertLine] = useState<boolean>(true);
    const [drawBroadcast, setDrawBroadcast] = useState<boolean>(true);
    const [lineToken, setLineToken] = useState<string>('');

    const [dailyDepositLimit, setDailyDepositLimit] = useState<number>(5000);
    const [singleBetLimit, setSingleBetLimit] = useState<number>(1000);
    const [dailyWagerLimit, setDailyWagerLimit] = useState<number>(10000);
    const [dailyLossLimit, setDailyLossLimit] = useState<number>(5000);

    const handleSaveGeneral = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            const res = await fetch('/api/v1/player/settings/general', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ language, theme, timezone, odds_format: oddsFormat }),
            });
            const data = await res.json();
            alert(data.message || 'General settings saved.');
        } catch {
            alert('Failed to save settings.');
        }
    };

    const handleSaveSecurity = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            const res = await fetch('/api/v1/player/settings/security', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    session_timeout: sessionTimeout,
                    login_alert_email: loginAlertEmail,
                    login_alert_line: loginAlertLine,
                    biometric_login: biometricLogin,
                }),
            });
            const data = await res.json();
            alert(data.message || 'Security settings saved.');
        } catch {
            alert('Failed to save security settings.');
        }
    };

    const handleToggle2fa = async () => {
        const nextState = !twoFactorEnabled;
        try {
            const res = await fetch('/api/v1/player/settings/2fa', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ enable: nextState }),
            });
            const data = await res.json();
            if (data.requires_verification) {
                const code = prompt(`${data.message}\nSecret: ${data.secret_key}\nEnter 6-digit code:`);
                if (code) {
                    const confirmRes = await fetch('/api/v1/player/settings/2fa', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ enable: true, code }),
                    });
                    const confirmData = await confirmRes.json();
                    setTwoFactorEnabled(true);
                    alert(confirmData.message || '2FA enabled.');
                }
            } else {
                setTwoFactorEnabled(nextState);
                alert(data.message || '2FA updated.');
            }
        } catch {
            alert('Error toggling 2FA.');
        }
    };

    return (
        <div className="w-full max-w-[1280px] mx-auto p-4 md:p-8 grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-8 text-slate-100 font-sans">
            {/* SIDEBAR */}
            <aside className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-6 flex flex-col gap-4 shadow-xl h-fit">
                <div className="border-b border-[#1f2b3e] pb-3">
                    <h2 className="text-sm font-black text-white uppercase tracking-wider">Settings &amp; Controls</h2>
                    <span className="text-[11px] text-amber-500 font-bold">THAILOTTO MEMBER</span>
                </div>

                <nav className="flex flex-col gap-1.5">
                    {[
                        { id: 'general', label: 'General Preferences (การตั้งค่าทั่วไป)', icon: '⚙️' },
                        { id: 'security', label: 'Security & 2FA (ความปลอดภัย)', icon: '🔒' },
                        { id: 'betting', label: 'Betting & Slip (การแทงหวย)', icon: '🎲' },
                        { id: 'notifications', label: 'Alerts & LINE (การแจ้งเตือน)', icon: '🔔' },
                        { id: 'responsible', label: 'Responsible Gaming (จำกัดการเล่น)', icon: '🛡️' },
                    ].map((tab) => (
                        <button
                            key={tab.id}
                            type="button"
                            onClick={() => setActiveTab(tab.id as any)}
                            className={`flex items-center gap-3 px-4 py-3 rounded-xl text-xs font-bold transition text-left ${
                                activeTab === tab.id
                                    ? 'bg-amber-500/15 border border-amber-500 text-amber-400 shadow-[0_0_12px_rgba(245,158,11,0.2)]'
                                    : 'text-slate-400 hover:text-white hover:bg-[#172233]'
                            }`}
                        >
                            <span>{tab.icon}</span>
                            <span>{tab.label}</span>
                        </button>
                    ))}
                </nav>
            </aside>

            {/* MAIN CONTENT PANEL */}
            <main className="flex flex-col gap-6">
                {/* TAB 1: GENERAL */}
                {activeTab === 'general' && (
                    <form onSubmit={handleSaveGeneral} className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-6 md:p-8 shadow-xl flex flex-col gap-6">
                        <div className="border-b border-[#1f2b3e] pb-4">
                            <h2 className="text-lg font-black text-white">General Preferences</h2>
                            <p className="text-xs text-slate-400 mt-1">Configure language, display theme, and odds representation format.</p>
                        </div>

                        <div className="flex flex-col gap-4">
                            <div className="flex items-center justify-between p-4 bg-[#0c121d] border border-[#1f2b3e] rounded-xl">
                                <div>
                                    <span className="text-xs font-bold text-white block">Portal Language (ภาษาของระบบ)</span>
                                    <span className="text-[11px] text-slate-400">Select interface language for member area.</span>
                                </div>
                                <select
                                    value={language}
                                    onChange={(e) => setLanguage(e.target.value)}
                                    className="bg-[#172233] border border-[#1f2b3e] rounded-xl px-4 py-2 text-xs text-white outline-none cursor-pointer"
                                >
                                    <option value="th">ภาษาไทย (Thai)</option>
                                    <option value="en">English (US)</option>
                                </select>
                            </div>

                            <div className="flex items-center justify-between p-4 bg-[#0c121d] border border-[#1f2b3e] rounded-xl">
                                <div>
                                    <span className="text-xs font-bold text-white block">Color Theme (ธีมหน้าจอ)</span>
                                    <span className="text-[11px] text-slate-400">High-contrast dark gold luxury mode.</span>
                                </div>
                                <select
                                    value={theme}
                                    onChange={(e) => setTheme(e.target.value)}
                                    className="bg-[#172233] border border-[#1f2b3e] rounded-xl px-4 py-2 text-xs text-white outline-none cursor-pointer"
                                >
                                    <option value="dark_gold">Dark Luxury Gold (โหมดมืดสีทอง)</option>
                                    <option value="dark_blue">Deep Midnight Navy</option>
                                </select>
                            </div>

                            <div className="flex items-center justify-between p-4 bg-[#0c121d] border border-[#1f2b3e] rounded-xl">
                                <div>
                                    <span className="text-xs font-bold text-white block">Odds Format (รูปแบบอัตราจ่าย)</span>
                                    <span className="text-[11px] text-slate-400">Payout multiplier multiplier display (e.g. x900).</span>
                                </div>
                                <select
                                    value={oddsFormat}
                                    onChange={(e) => setOddsFormat(e.target.value)}
                                    className="bg-[#172233] border border-[#1f2b3e] rounded-xl px-4 py-2 text-xs text-white outline-none cursor-pointer"
                                >
                                    <option value="multiplier">Thai Multiplier (บาทละ x900 / x95)</option>
                                    <option value="decimal">Decimal Ratio (900.00 / 95.00)</option>
                                </select>
                            </div>
                        </div>

                        <button
                            type="submit"
                            className="bg-gradient-to-r from-amber-500 to-amber-600 text-slate-950 font-black text-xs py-3 px-6 rounded-xl shadow-[0_0_15px_rgba(245,158,11,0.35)] self-start transition hover:brightness-110"
                        >
                            Save General Settings
                        </button>
                    </form>
                )}

                {/* TAB 2: SECURITY */}
                {activeTab === 'security' && (
                    <form onSubmit={handleSaveSecurity} className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-6 md:p-8 shadow-xl flex flex-col gap-6">
                        <div className="border-b border-[#1f2b3e] pb-4">
                            <h2 className="text-lg font-black text-white">Security &amp; 2-Factor Authentication</h2>
                            <p className="text-xs text-slate-400 mt-1">Manage authentication security protocols and login alerts.</p>
                        </div>

                        <div className="flex flex-col gap-4">
                            <div className="flex items-center justify-between p-4 bg-[#0c121d] border border-[#1f2b3e] rounded-xl">
                                <div>
                                    <span className="text-xs font-bold text-white block">Two-Factor Authentication (Google 2FA)</span>
                                    <span className="text-[11px] text-slate-400">Requires 6-digit TOTP code on every login.</span>
                                </div>
                                <button
                                    type="button"
                                    onClick={handleToggle2fa}
                                    className={`px-4 py-2 rounded-xl text-xs font-black transition ${
                                        twoFactorEnabled
                                            ? 'bg-rose-500/20 border border-rose-500 text-rose-400'
                                            : 'bg-emerald-500 text-slate-950 shadow-[0_0_12px_rgba(16,185,129,0.4)]'
                                    }`}
                                >
                                    {twoFactorEnabled ? 'Disable 2FA' : 'Enable Google 2FA'}
                                </button>
                            </div>

                            <div className="flex items-center justify-between p-4 bg-[#0c121d] border border-[#1f2b3e] rounded-xl">
                                <div>
                                    <span className="text-xs font-bold text-white block">Auto Logout Idle Timeout</span>
                                    <span className="text-[11px] text-slate-400">Automatically logout inactive session.</span>
                                </div>
                                <select
                                    value={sessionTimeout}
                                    onChange={(e) => setSessionTimeout(parseInt(e.target.value, 10))}
                                    className="bg-[#172233] border border-[#1f2b3e] rounded-xl px-4 py-2 text-xs text-white outline-none cursor-pointer"
                                >
                                    <option value={15}>15 Minutes</option>
                                    <option value={30}>30 Minutes</option>
                                    <option value={60}>1 Hour</option>
                                </select>
                            </div>
                        </div>

                        <button
                            type="submit"
                            className="bg-gradient-to-r from-amber-500 to-amber-600 text-slate-950 font-black text-xs py-3 px-6 rounded-xl shadow-[0_0_15px_rgba(245,158,11,0.35)] self-start transition hover:brightness-110"
                        >
                            Save Security Settings
                        </button>
                    </form>
                )}

                {/* TAB 3: BETTING */}
                {activeTab === 'betting' && (
                    <div className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-6 md:p-8 shadow-xl flex flex-col gap-6">
                        <div className="border-b border-[#1f2b3e] pb-4">
                            <h2 className="text-lg font-black text-white">Betting &amp; Slip Preferences</h2>
                            <p className="text-xs text-slate-400 mt-1">Configure fast betting mode and sound effects.</p>
                        </div>

                        <div className="flex flex-col gap-4">
                            <label className="flex items-center justify-between p-4 bg-[#0c121d] border border-[#1f2b3e] rounded-xl cursor-pointer">
                                <div>
                                    <span className="text-xs font-bold text-white block">Sound Effects (เสียงเอฟเฟกต์)</span>
                                    <span className="text-[11px] text-slate-400">Audio chime on draw countdown and bet placement.</span>
                                </div>
                                <input
                                    type="checkbox"
                                    checked={soundEffects}
                                    onChange={(e) => setSoundEffects(e.target.checked)}
                                    className="w-4 h-4 rounded text-emerald-500"
                                />
                            </label>

                            <label className="flex items-center justify-between p-4 bg-[#0c121d] border border-[#1f2b3e] rounded-xl cursor-pointer">
                                <div>
                                    <span className="text-xs font-bold text-white block">Auto-Clear Bet Slip (ล้างโพยอัตโนมัติ)</span>
                                    <span className="text-[11px] text-slate-400">Clear selection after successful bet placement.</span>
                                </div>
                                <input
                                    type="checkbox"
                                    checked={autoClearBetSlip}
                                    onChange={(e) => setAutoClearBetSlip(e.target.checked)}
                                    className="w-4 h-4 rounded text-emerald-500"
                                />
                            </label>
                        </div>
                    </div>
                )}

                {/* TAB 4: NOTIFICATIONS */}
                {activeTab === 'notifications' && (
                    <div className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-6 md:p-8 shadow-xl flex flex-col gap-6">
                        <div className="border-b border-[#1f2b3e] pb-4">
                            <h2 className="text-lg font-black text-white">Notifications &amp; LINE Notify</h2>
                            <p className="text-xs text-slate-400 mt-1">Bind LINE Notify token for instant win alerts.</p>
                        </div>

                        <div className="flex flex-col gap-4">
                            <div className="p-4 bg-[#0c121d] border border-[#1f2b3e] rounded-xl flex flex-col gap-3">
                                <div>
                                    <span className="text-xs font-bold text-white block">LINE Notify Token (การเชื่อมต่อไลน์)</span>
                                    <span className="text-[11px] text-slate-400">Receive instant prize notifications in your LINE app.</span>
                                </div>
                                <div className="flex gap-2">
                                    <input
                                        type="password"
                                        placeholder="Paste LINE Notify Access Token..."
                                        value={lineToken}
                                        onChange={(e) => setLineToken(e.target.value)}
                                        className="bg-[#172233] border border-[#1f2b3e] rounded-xl px-4 py-2 text-xs text-white outline-none flex-1 font-mono"
                                    />
                                    <button
                                        type="button"
                                        onClick={async () => {
                                            if (!lineToken) return alert('Enter token');
                                            const res = await fetch('/api/v1/player/settings/line-notify', {
                                                method: 'POST',
                                                headers: { 'Content-Type': 'application/json' },
                                                body: JSON.stringify({ line_token: lineToken }),
                                            });
                                            const d = await res.json();
                                            alert(d.message || 'Connected');
                                            setLineToken('');
                                        }}
                                        className="bg-[#00c300] hover:bg-[#00b000] text-slate-950 text-xs font-black px-4 py-2 rounded-xl transition"
                                    >
                                        Connect LINE
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* TAB 5: RESPONSIBLE */}
                {activeTab === 'responsible' && (
                    <div className="bg-[#131b29] border border-[#1f2b3e] rounded-2xl p-6 md:p-8 shadow-xl flex flex-col gap-6">
                        <div className="border-b border-[#1f2b3e] pb-4">
                            <h2 className="text-lg font-black text-white">Responsible Gaming &amp; Limits</h2>
                            <p className="text-xs text-slate-400 mt-1">Set deposit and wager ceilings to stay in control.</p>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div className="p-4 bg-[#0c121d] border border-[#1f2b3e] rounded-xl">
                                <label className="text-xs font-bold text-slate-300 block mb-1">Daily Deposit Limit (THB)</label>
                                <input
                                    type="number"
                                    value={dailyDepositLimit}
                                    onChange={(e) => setDailyDepositLimit(parseFloat(e.target.value))}
                                    className="w-full bg-[#172233] border border-[#1f2b3e] rounded-xl px-4 py-2 font-mono text-white text-xs outline-none"
                                />
                            </div>
                            <div className="p-4 bg-[#0c121d] border border-[#1f2b3e] rounded-xl">
                                <label className="text-xs font-bold text-slate-300 block mb-1">Maximum Single Bet (THB)</label>
                                <input
                                    type="number"
                                    value={singleBetLimit}
                                    onChange={(e) => setSingleBetLimit(parseFloat(e.target.value))}
                                    className="w-full bg-[#172233] border border-[#1f2b3e] rounded-xl px-4 py-2 font-mono text-white text-xs outline-none"
                                />
                            </div>
                        </div>

                        <button
                            type="button"
                            onClick={async () => {
                                const res = await fetch('/api/v1/player/settings/limits', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ daily_deposit_limit: dailyDepositLimit, single_bet_limit: singleBetLimit }),
                                });
                                const d = await res.json();
                                alert(d.message || 'Limits updated.');
                            }}
                            className="bg-gradient-to-r from-amber-500 to-amber-600 text-slate-950 font-black text-xs py-3 px-6 rounded-xl shadow-[0_0_15px_rgba(245,158,11,0.35)] self-start transition"
                        >
                            Save Responsible Limits
                        </button>
                    </div>
                )}
            </main>
        </div>
    );
};
