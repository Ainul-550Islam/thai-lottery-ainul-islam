@extends('layouts.app')

@section('title', 'Player Sign In — Thai Lottery Portal')

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
<div class="tl-auth-page min-h-[80vh] flex items-center justify-center py-10 px-4 sm:px-6 relative">
    <!-- Ambient Backdrop Light -->
    <div class="tl-auth-bg-ambient"></div>

    <div class="tl-auth-card relative z-10 max-w-md w-full bg-slate-900/90 border border-slate-800/90 rounded-3xl p-6 sm:p-8 shadow-2xl backdrop-blur-xl">
        <!-- Logo & Header -->
        <div class="text-center mb-6">
            <div class="tl-auth-logo-badge w-14 h-14 rounded-2xl bg-gradient-to-tr from-amber-400 via-emerald-400 to-emerald-500 mx-auto flex items-center justify-center font-black text-2xl text-slate-950 shadow-lg shadow-emerald-500/20 mb-3">
                TL
            </div>
            <div class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[11px] font-bold uppercase tracking-wider mb-2">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                <span>Official Member Access</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight">
                Player Sign In
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Enter your credentials to manage bets, view live draws, and withdraw winnings.
            </p>
        </div>

        <!-- Mode Navigation Tabs -->
        <div class="tl-auth-tabs flex bg-slate-950/70 p-1 rounded-2xl border border-slate-800 mb-6">
            <a href="{{ route('login') }}" class="tl-auth-tab flex-1 py-2 text-center text-xs font-bold rounded-xl bg-gradient-to-r from-emerald-600 to-emerald-500 text-white shadow">
                Sign In
            </a>
            <a href="{{ route('register') }}" class="tl-auth-tab flex-1 py-2 text-center text-xs font-bold rounded-xl text-slate-400 hover:text-white transition">
                Register
            </a>
        </div>

        @if ($errors->any())
            <div class="bg-rose-950/70 border border-rose-600/40 rounded-2xl p-4 text-rose-300 text-xs mb-6 space-y-1" role="alert">
                <div class="font-bold flex items-center space-x-1.5 text-rose-200 mb-1">
                    <svg class="w-4 h-4 text-rose-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>Authentication Notice</span>
                </div>
                <ul class="list-disc list-inside space-y-0.5 pl-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('status'))
            <div class="bg-emerald-950/60 border border-emerald-600/40 rounded-2xl p-4 text-emerald-300 text-xs mb-6" role="status">
                {{ session('status') }}
            </div>
        @endif

        <!-- Login Form -->
        <form class="space-y-4" action="{{ route('login.attempt') }}" method="POST" data-auth-form>
            @csrf

            <div>
                <label for="login" class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">
                    Username or Email
                </label>
                <div class="relative">
                    <input id="login" name="login" type="text" autocomplete="username" required value="{{ old('login') }}"
                           placeholder="e.g. somchai or somchai@example.com"
                           class="w-full pl-10 pr-4 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                    <span class="tl-auth-input-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </span>
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label for="password" class="block text-xs font-bold text-slate-300 uppercase tracking-wider">
                        Password
                    </label>
                    <a href="{{ route('password.request') }}" class="text-xs text-emerald-400 hover:text-emerald-300 transition">
                        Forgot password?
                    </a>
                </div>
                <div class="relative">
                    <input id="password" name="password" type="password" autocomplete="current-password" required
                           placeholder="••••••••"
                           class="w-full pl-10 pr-10 py-3 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                    <span class="tl-auth-input-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </span>
                    <button type="button" data-password-toggle data-target="password" class="tl-password-toggle" aria-label="Toggle password visibility">
                        <svg class="w-4 h-4 eye-open" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <svg class="w-4 h-4 eye-closed hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between text-xs text-slate-400 pt-1">
                <label class="flex items-center space-x-2 cursor-pointer select-none">
                    <input id="remember" name="remember" type="checkbox" class="h-4 w-4 text-emerald-500 focus:ring-emerald-500 border-slate-700 rounded bg-slate-950">
                    <span>Keep me signed in</span>
                </label>
            </div>

            <div class="pt-2">
                <button type="submit" class="tl-submit-btn w-full py-3.5 px-4 rounded-xl text-slate-950 font-black text-sm bg-gradient-to-r from-amber-400 via-emerald-400 to-emerald-500 hover:from-amber-500 hover:to-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-500 shadow-lg shadow-emerald-500/20 transition transform active:scale-95 flex items-center justify-center space-x-2">
                    <span>Sign In to Play</span>
                    <svg class="w-4 h-4 text-slate-950" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </button>
            </div>

            <div class="text-center pt-3 text-xs text-slate-400 border-t border-slate-800/80">
                <span>Don't have an account yet?</span>
                <a href="{{ route('register') }}" class="font-bold text-emerald-400 hover:text-emerald-300 ml-1">
                    Create account &rarr;
                </a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/auth-portal.js') }}"></script>
@endpush
