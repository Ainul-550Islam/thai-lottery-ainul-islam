import React, { useState } from 'react';

interface AuthCardProps {
    initialMode?: 'login' | 'register';
    loginActionUrl?: string;
    registerActionUrl?: string;
    csrfToken?: string;
}

export const AuthCard: React.FC<AuthCardProps> = ({
    initialMode = 'login',
    loginActionUrl = '/login',
    registerActionUrl = '/register',
    csrfToken = '',
}) => {
    const [mode, setMode] = useState<'login' | 'register'>(initialMode);
    const [showPassword, setShowPassword] = useState<boolean>(false);
    const [password, setPassword] = useState<string>('');
    const [referralCode, setReferralCode] = useState<string>('');

    const calculateStrength = (pwd: string) => {
        if (!pwd) return { width: '0%', color: 'bg-slate-700', label: '' };
        let score = 0;
        if (pwd.length >= 8) score++;
        if (/[A-Z]/.test(pwd) && /[a-z]/.test(pwd)) score++;
        if (/[0-9]/.test(pwd)) score++;
        if (/[^A-Za-z0-9]/.test(pwd)) score++;

        switch (score) {
            case 1:
                return { width: '25%', color: 'bg-rose-500', label: 'Weak' };
            case 2:
                return { width: '50%', color: 'bg-amber-500', label: 'Fair' };
            case 3:
                return { width: '75%', color: 'bg-sky-500', label: 'Good' };
            case 4:
                return { width: '100%', color: 'bg-emerald-500', label: 'Strong' };
            default:
                return { width: '15%', color: 'bg-rose-500', label: 'Very Weak' };
        }
    };

    const strength = calculateStrength(password);

    return (
        <div className="w-full max-w-md mx-auto bg-slate-900/90 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-2xl backdrop-blur-xl relative overflow-hidden">
            {/* Header ambient logo */}
            <div className="text-center mb-6">
                <div className="w-14 h-14 rounded-2xl bg-gradient-to-tr from-amber-400 to-emerald-500 mx-auto flex items-center justify-center font-black text-2xl text-slate-950 shadow-lg shadow-emerald-500/20 mb-3">
                    TL
                </div>
                <h1 className="text-2xl font-black text-white tracking-tight">
                    {mode === 'login' ? 'Player Portal Sign In' : 'Create Player Account'}
                </h1>
                <p className="text-xs text-slate-400 mt-1">
                    {mode === 'login'
                        ? 'Official access to Thai Lottery draws & instant disbursements'
                        : 'Join Thailand\'s premier verified digital wagering platform'}
                </p>
            </div>

            {/* Mode Tabs */}
            <div className="flex bg-slate-950/60 p-1 rounded-xl border border-slate-800 mb-6">
                <button
                    type="button"
                    onClick={() => setMode('login')}
                    className={`flex-1 py-2 text-xs font-bold rounded-lg transition ${
                        mode === 'login'
                            ? 'bg-gradient-to-r from-emerald-600 to-emerald-500 text-white shadow'
                            : 'text-slate-400 hover:text-white'
                    }`}
                >
                    Sign In
                </button>
                <button
                    type="button"
                    onClick={() => setMode('register')}
                    className={`flex-1 py-2 text-xs font-bold rounded-lg transition ${
                        mode === 'register'
                            ? 'bg-gradient-to-r from-emerald-600 to-emerald-500 text-white shadow'
                            : 'text-slate-400 hover:text-white'
                    }`}
                >
                    Register
                </button>
            </div>

            {/* Login Form */}
            {mode === 'login' ? (
                <form action={loginActionUrl} method="POST" className="space-y-4">
                    <input type="hidden" name="_token" value={csrfToken} />

                    <div>
                        <label className="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1">
                            Username or Email
                        </label>
                        <input
                            type="text"
                            name="login"
                            required
                            placeholder="e.g. somchai or somchai@example.com"
                            className="w-full px-4 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1">
                            Password
                        </label>
                        <div className="relative">
                            <input
                                type={showPassword ? 'text' : 'password'}
                                name="password"
                                required
                                placeholder="••••••••"
                                className="w-full px-4 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 pr-10"
                            />
                            <button
                                type="button"
                                onClick={() => setShowPassword(!showPassword)}
                                className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-white text-xs"
                            >
                                {showPassword ? 'Hide' : 'Show'}
                            </button>
                        </div>
                    </div>

                    <div className="flex items-center justify-between text-xs text-slate-400 pt-1">
                        <label className="flex items-center space-x-2 cursor-pointer">
                            <input type="checkbox" name="remember" className="rounded bg-slate-950 border-slate-700 text-emerald-500 focus:ring-emerald-500" />
                            <span>Remember login</span>
                        </label>
                        <a href="/forgot-password" className="text-emerald-400 hover:underline">
                            Forgot password?
                        </a>
                    </div>

                    <button
                        type="submit"
                        className="w-full py-3.5 bg-gradient-to-r from-amber-400 via-emerald-400 to-emerald-500 hover:from-amber-500 hover:to-emerald-600 text-slate-950 font-black text-sm rounded-xl shadow-lg shadow-emerald-500/20 transition transform active:scale-95 mt-4"
                    >
                        Sign In & Enter Portal
                    </button>
                </form>
            ) : (
                /* Registration Form */
                <form action={registerActionUrl} method="POST" className="space-y-4">
                    <input type="hidden" name="_token" value={csrfToken} />

                    <div>
                        <label className="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1">
                            Full Name
                        </label>
                        <input
                            type="text"
                            name="name"
                            required
                            placeholder="Somchai Jaidee"
                            className="w-full px-4 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1">
                                Username
                            </label>
                            <input
                                type="text"
                                name="username"
                                required
                                placeholder="somchai99"
                                className="w-full px-4 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1">
                                Phone (Optional)
                            </label>
                            <input
                                type="tel"
                                name="phone"
                                placeholder="0812345678"
                                className="w-full px-4 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                            />
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1">
                            Email Address
                        </label>
                        <input
                            type="email"
                            name="email"
                            required
                            placeholder="somchai@example.com"
                            className="w-full px-4 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1">
                            Password
                        </label>
                        <input
                            type="password"
                            name="password"
                            required
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            placeholder="Min. 8 characters"
                            className="w-full px-4 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                        />
                        {password && (
                            <div className="mt-1.5">
                                <div className="h-1.5 w-full bg-slate-800 rounded-full overflow-hidden">
                                    <div
                                        className={`h-full ${strength.color} transition-all duration-300`}
                                        style={{ width: strength.width }}
                                    />
                                </div>
                                <span className="text-[10px] text-slate-400 mt-0.5 block">{strength.label}</span>
                            </div>
                        )}
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1">
                            Confirm Password
                        </label>
                        <input
                            type="password"
                            name="password_confirmation"
                            required
                            placeholder="••••••••"
                            className="w-full px-4 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1">
                            Agent Referral Code (Optional)
                        </label>
                        <input
                            type="text"
                            name="referral_code"
                            value={referralCode}
                            onChange={(e) => setReferralCode(e.target.value.toUpperCase())}
                            placeholder="AG123456"
                            className="w-full px-4 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm uppercase font-mono focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>

                    <button
                        type="submit"
                        className="w-full py-3.5 bg-gradient-to-r from-amber-400 via-emerald-400 to-emerald-500 hover:from-amber-500 hover:to-emerald-600 text-slate-950 font-black text-sm rounded-xl shadow-lg shadow-emerald-500/20 transition transform active:scale-95 mt-4"
                    >
                        Create Account & Start Playing
                    </button>
                </form>
            )}
        </div>
    );
};
