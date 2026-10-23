@extends('layouts.app')

@section('title', __('public_pages.register_meta_title'))
@section('meta_robots', 'noindex, nofollow')

@push('styles')
    @vite(['resources/css/auth-portal.css'])
@endpush

@section('content')
<div class="tl-auth-page min-h-[85vh] flex items-center justify-center py-8 px-4 sm:px-6 relative">
    <div class="tl-auth-bg-ambient" aria-hidden="true"></div>

    <div class="tl-auth-card max-w-lg">
        <div class="text-center mb-6">
            <div class="tl-auth-logo-badge" aria-hidden="true">
                TL
            </div>
            <div class="tl-auth-badge">
                <span class="w-2 h-2 rounded-full bg-[#D4AF37]" aria-hidden="true"></span>
                <span>Create Account</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-[#FFF6D6] tracking-tight">
                Create Player Account
            </h1>
            <p class="text-xs sm:text-sm text-[#D4CAB3] mt-1.5 leading-relaxed">
                Create an account to access configured lottery information and member features. Eligibility and verification requirements apply.
            </p>
        </div>

        <div class="tl-auth-tabs" role="navigation" aria-label="Authentication mode">
            <a href="{{ route('login') }}" class="tl-auth-tab">
                Sign In
            </a>
            <a href="{{ route('register') }}" class="tl-auth-tab active" aria-current="page">
                Register
            </a>
        </div>

        @if ($errors->any())
            <div class="bg-rose-950/75 border border-rose-500/45 rounded-2xl p-4 text-rose-200 text-xs mb-6 space-y-1" role="alert">
                <div class="font-bold flex items-center gap-1.5 text-rose-100 mb-1">
                    <svg class="w-4 h-4 text-rose-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>Registration Form Errors</span>
                </div>
                <ul class="list-disc list-inside space-y-0.5 pl-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form class="space-y-4" action="{{ route('register.attempt') }}" method="POST" data-auth-form>
            @csrf

            <div>
                <label for="name" class="block text-xs font-bold text-[#F5E6B8] uppercase tracking-wider mb-1.5">
                    Full Legal Name
                </label>
                <div class="relative">
                    <input id="name" name="name" type="text" autocomplete="name" required value="{{ old('name') }}"
                           placeholder="Somchai Jaidee"
                           class="tl-input-field">
                    <span class="tl-auth-input-icon" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                    <label for="username" class="block text-xs font-bold text-[#F5E6B8] uppercase tracking-wider mb-1.5">
                        Username
                    </label>
                    <div class="relative">
                        <input id="username" name="username" type="text" autocomplete="username" required value="{{ old('username') }}"
                               placeholder="somchai99"
                               class="tl-input-field">
                        <span class="tl-auth-input-icon" aria-hidden="true">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                        </span>
                    </div>
                </div>

                <div>
                    <label for="phone" class="block text-xs font-bold text-[#F5E6B8] uppercase tracking-wider mb-1.5">
                        Phone (Thailand)
                    </label>
                    <div class="relative">
                        <input id="phone" name="phone" type="tel" autocomplete="tel" value="{{ old('phone') }}"
                               placeholder="0812345678"
                               class="tl-input-field font-mono">
                        <span class="tl-auth-input-icon" aria-hidden="true">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        </span>
                    </div>
                </div>
            </div>

            <div>
                <label for="email" class="block text-xs font-bold text-[#F5E6B8] uppercase tracking-wider mb-1.5">
                    Email Address
                </label>
                <div class="relative">
                    <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}"
                           placeholder="somchai@example.com"
                           class="tl-input-field">
                    <span class="tl-auth-input-icon" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </span>
                </div>
            </div>

            <div>
                <label for="register-password" class="block text-xs font-bold text-[#F5E6B8] uppercase tracking-wider mb-1.5">
                    Security Password
                </label>
                <div class="relative">
                    <input id="register-password" name="password" type="password" autocomplete="new-password" required
                           placeholder="Min. 8 characters"
                           class="tl-input-field pr-12">
                    <span class="tl-auth-input-icon" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </span>
                    <button type="button" data-password-toggle data-target="register-password" class="tl-password-toggle" aria-label="Toggle password visibility">
                        <svg class="w-4 h-4 eye-open" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <svg class="w-4 h-4 eye-closed hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>
                    </button>
                </div>
                <div class="tl-strength-meter">
                    <div id="password-strength-bar" class="tl-strength-bar"></div>
                </div>
                <div class="tl-strength-text">
                    <span id="password-strength-label"></span>
                    <span>Use 8+ chars, upper &amp; numbers</span>
                </div>
            </div>

            <div>
                <label for="password_confirmation" class="block text-xs font-bold text-[#F5E6B8] uppercase tracking-wider mb-1.5">
                    Confirm Security Password
                </label>
                <div class="relative">
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                           placeholder="••••••••"
                           class="tl-input-field pr-12">
                    <span class="tl-auth-input-icon" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </span>
                    <button type="button" data-password-toggle data-target="password_confirmation" class="tl-password-toggle" aria-label="Toggle password visibility">
                        <svg class="w-4 h-4 eye-open" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <svg class="w-4 h-4 eye-closed hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>
                    </button>
                </div>
            </div>

            <div>
                <label for="referral_code" class="block text-xs font-bold text-[#F5E6B8] uppercase tracking-wider mb-1.5">
                    Agent Partner Referral Code (Optional)
                </label>
                <div class="relative">
                    <input id="referral_code" name="referral_code" type="text"
                           value="{{ old('referral_code', request('ref')) }}"
                           placeholder="e.g. AGT-88219"
                           class="tl-input-field font-mono uppercase">
                    <span class="tl-auth-input-icon" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    </span>
                </div>
            </div>

            <div class="space-y-2.5 pt-1">
                <label class="flex items-start gap-2.5 cursor-pointer select-none">
                    <input type="checkbox" name="age_confirmation" value="1" required @checked(old('age_confirmation')) class="mt-0.5 h-4 w-4 rounded border-[#D4AF37]/40 bg-[#0B0904] accent-[#D4AF37]">
                    <span class="text-xs text-[#D4CAB3] leading-relaxed">
                        I confirm that I am at least <strong class="text-[#FFF6D6]">20 years of age</strong> as required by applicable lottery eligibility rules.
                    </span>
                </label>

                <label class="flex items-start gap-2.5 cursor-pointer select-none">
                    <input type="checkbox" name="terms" value="1" required @checked(old('terms')) class="mt-0.5 h-4 w-4 rounded border-[#D4AF37]/40 bg-[#0B0904] accent-[#D4AF37]">
                    <span class="text-xs text-[#D4CAB3] leading-relaxed">
                        I agree to the <a href="{{ route('terms') }}" class="text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4 font-bold" target="_blank" rel="noopener noreferrer">Terms of Service</a> and <a href="{{ route('privacy') }}" class="text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4 font-bold" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.
                    </span>
                </label>
            </div>

            <div class="pt-2">
                <button type="submit" class="tl-submit-btn">
                    <span>Create Account &amp; Play</span>
                    <svg class="w-4 h-4 text-[#0B0904]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </button>
            </div>

            <div class="text-center pt-4 text-xs text-[#D4CAB3] border-t border-[#D4AF37]/15">
                <span>Already registered?</span>
                <a href="{{ route('login') }}" class="font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4 ml-1">
                    Sign in here &rarr;
                </a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/auth-portal.js'])
@endpush
