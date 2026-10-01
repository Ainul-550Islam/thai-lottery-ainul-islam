@extends('layouts.app')

@section('title', 'Player Registration — Thai Lottery Portal')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/auth-portal.css') }}">
<style>
    .tl-auth-input-icon {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #64748b;
        pointer-events: none;
    }
</style>
@endpush

@section('content')
<div class="tl-auth-page min-h-[85vh] flex items-center justify-center py-10 px-4 sm:px-6 relative">
    <!-- Ambient Backdrop Light -->
    <div class="tl-auth-bg-ambient"></div>

    <div class="tl-auth-card relative z-10 max-w-lg w-full bg-slate-900/90 border border-slate-800/90 rounded-3xl p-6 sm:p-8 shadow-2xl backdrop-blur-xl">
        <!-- Logo & Header -->
        <div class="text-center mb-6">
            <div class="tl-auth-logo-badge w-14 h-14 rounded-2xl bg-gradient-to-tr from-amber-400 via-emerald-400 to-emerald-500 mx-auto flex items-center justify-center font-black text-2xl text-slate-950 shadow-lg shadow-emerald-500/20 mb-3">
                TL
            </div>
            <div class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[11px] font-bold uppercase tracking-wider mb-2">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                <span>Fast Registration</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight">
                Create Player Account
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Join Thailand's premier verified digital lottery platform with instant payouts.
            </p>
        </div>

        <!-- Mode Navigation Tabs -->
        <div class="tl-auth-tabs flex bg-slate-950/70 p-1 rounded-2xl border border-slate-800 mb-6">
            <a href="{{ route('login') }}" class="tl-auth-tab flex-1 py-2 text-center text-xs font-bold rounded-xl text-slate-400 hover:text-white transition">
                Sign In
            </a>
            <a href="{{ route('register') }}" class="tl-auth-tab flex-1 py-2 text-center text-xs font-bold rounded-xl bg-gradient-to-r from-emerald-600 to-emerald-500 text-white shadow">
                Register
            </a>
        </div>

        @if ($errors->any())
            <div class="bg-rose-950/70 border border-rose-600/40 rounded-2xl p-4 text-rose-300 text-xs mb-6 space-y-1" role="alert">
                <div class="font-bold flex items-center space-x-1.5 text-rose-200 mb-1">
                    <svg class="w-4 h-4 text-rose-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>Registration Form Errors</span>
                </div>
                <ul class="list-disc list-inside space-y-0.5 pl-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Registration Form -->
        <form class="space-y-4" action="{{ route('register.attempt') }}" method="POST" data-auth-form>
            @csrf

            <div>
                <label for="name" class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">
                    Full Legal Name
                </label>
                <div class="relative">
                    <input id="name" name="name" type="text" autocomplete="name" required value="{{ old('name') }}"
                           placeholder="Somchai Jaidee"
                           class="w-full pl-10 pr-4 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                    <span class="tl-auth-input-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                    <label for="username" class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">
                        Username
                    </label>
                    <div class="relative">
                        <input id="username" name="username" type="text" autocomplete="username" required value="{{ old('username') }}"
                               placeholder="somchai99"
                               class="w-full pl-10 pr-4 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                        <span class="tl-auth-input-icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                        </span>
                    </div>
                </div>

                <div>
                    <label for="phone" class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">
                        Phone (Thailand)
                    </label>
                    <div class="relative">
                        <input id="phone" name="phone" type="tel" autocomplete="tel" value="{{ old('phone') }}"
                               placeholder="0812345678"
                               class="w-full pl-10 pr-4 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm font-mono focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                        <span class="tl-auth-input-icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        </span>
                    </div>
                </div>
            </div>

            <div>
                <label for="email" class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">
                    Email Address
                </label>
                <div class="relative">
                    <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}"
                           placeholder="somchai@example.com"
                           class="w-full pl-10 pr-4 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                    <span class="tl-auth-input-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </span>
                </div>
            </div>

            <div>
                <label for="register-password" class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">
                    Security Password
                </label>
                <div class="relative">
                    <input id="register-password" name="password" type="password" autocomplete="new-password" required
                           placeholder="Min. 8 characters"
                           class="w-full pl-10 pr-10 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                    <span class="tl-auth-input-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </span>
                    <button type="button" data-password-toggle data-target="register-password" class="tl-password-toggle" aria-label="Toggle password visibility">
                        <svg class="w-4 h-4 eye-open" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <svg class="w-4 h-4 eye-closed hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>
                    </button>
                </div>
                <!-- Dynamic Strength Bar -->
                <div class="tl-strength-meter">
                    <div id="password-strength-bar" class="tl-strength-bar"></div>
                </div>
                <div class="tl-strength-text">
                    <span id="password-strength-label"></span>
                    <span>Use 8+ chars, upper & numbers</span>
                </div>
            </div>

            <div>
                <label for="password_confirmation" class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">
                    Confirm Security Password
                </label>
                <div class="relative">
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                           placeholder="••••••••"
                           class="w-full pl-10 pr-10 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                    <span class="tl-auth-input-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </span>
                    <button type="button" data-password-toggle data-target="password_confirmation" class="tl-password-toggle" aria-label="Toggle password visibility">
                        <svg class="w-4 h-4 eye-open" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <svg class="w-4 h-4 eye-closed hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>
                    </button>
                </div>
            </div>

            <div>
                <label for="referral_code" class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">
                    Agent Partner Referral Code (Optional)
                </label>
                <div class="relative">
                    <input id="referral_code" name="referral_code" type="text"
                           value="{{ old('referral_code', request('ref')) }}"
                           placeholder="e.g. AGT-88219"
                           class="w-full pl-10 pr-4 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm font-mono uppercase focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                    <span class="tl-auth-input-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    </span>
                </div>
            </div>

            <div class="space-y-2 pt-1">
                <label class="flex items-start space-x-2.5 cursor-pointer select-none">
                    <input type="checkbox" name="age_confirmation" required checked class="mt-0.5 h-4 w-4 text-emerald-500 focus:ring-emerald-500 border-slate-700 rounded bg-slate-950">
                    <span class="text-xs text-slate-400">
                        I confirm that I am at least <strong>20 years of age</strong> as required by Thai Government Lottery regulations.
                    </span>
                </label>

                <label class="flex items-start space-x-2.5 cursor-pointer select-none">
                    <input type="checkbox" name="terms" required checked class="mt-0.5 h-4 w-4 text-emerald-500 focus:ring-emerald-500 border-slate-700 rounded bg-slate-950">
                    <span class="text-xs text-slate-400">
                        I agree to the <a href="{{ route('terms') }}" class="text-emerald-400 hover:underline" target="_blank">Terms of Service</a> and <a href="{{ route('privacy') }}" class="text-emerald-400 hover:underline" target="_blank">Privacy Policy</a>.
                    </span>
                </label>
            </div>

            <div class="pt-2">
                <button type="submit" class="tl-submit-btn w-full py-3.5 px-4 rounded-xl text-slate-950 font-black text-sm bg-gradient-to-r from-amber-400 via-emerald-400 to-emerald-500 hover:from-amber-500 hover:to-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-500 shadow-lg shadow-emerald-500/20 transition transform active:scale-95 flex items-center justify-center space-x-2">
                    <span>Create Account &amp; Play</span>
                    <svg class="w-4 h-4 text-slate-950" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </button>
            </div>

            <div class="text-center pt-3 text-xs text-slate-400 border-t border-slate-800/80">
                <span>Already registered?</span>
                <a href="{{ route('login') }}" class="font-bold text-emerald-400 hover:text-emerald-300 ml-1">
                    Sign in here &rarr;
                </a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/auth-portal.js') }}"></script>
@endpush
