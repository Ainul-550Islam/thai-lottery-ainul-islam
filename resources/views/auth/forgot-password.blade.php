@extends('layouts.app')

@section('title', __('public_pages.forgot_meta_title'))
@section('meta_description', __('public_pages.forgot_meta_description'))
@section('meta_robots', 'noindex, nofollow')

@push('styles')
    @vite(['resources/css/auth-portal.css'])
@endpush

@section('content')
<div class="tl-auth-page min-h-[75vh] flex items-center justify-center py-10 px-4 sm:px-6 lg:px-8 relative">
    <div class="tl-auth-bg-ambient" aria-hidden="true"></div>

    <div class="tl-auth-card max-w-md space-y-6">
        <div class="text-center">
            <div class="tl-auth-logo-badge" aria-hidden="true">TL</div>
            <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-[#FFF6D6]">
                {{ __('public_pages.forgot_heading') }}
            </h1>
            <p class="mt-2 text-sm text-[#D4CAB3] leading-relaxed">
                {{ __('public_pages.forgot_lead') }}
            </p>
        </div>

        @if (session('status'))
            <div class="bg-emerald-950/65 border border-emerald-500/45 rounded-xl p-4 text-emerald-200 text-sm" role="status">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="bg-rose-950/65 border border-rose-500/45 rounded-xl p-4 text-rose-200 text-sm" role="alert" aria-live="polite">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($resetToken === null)
            <form class="space-y-5" action="{{ route('password.request.attempt') }}" method="POST" novalidate>
                @csrf

                <div class="space-y-4">
                    <div>
                        <label for="identifier" class="block text-xs font-bold uppercase tracking-wider text-[#F5E6B8] mb-1.5">
                            {{ __('public_pages.forgot_identifier') }}
                        </label>
                        <input id="identifier" name="identifier" type="text" autocomplete="username" required
                               value="{{ old('identifier') }}"
                               aria-describedby="identifier-hint"
                               @if ($errors->has('identifier')) aria-invalid="true" @endif
                               class="tl-v2-input {{ $errors->has('identifier') ? 'border-rose-500 bg-rose-950/30' : '' }}"
                               placeholder="{{ __('public_pages.forgot_identifier_placeholder') }}">
                        <p id="identifier-hint" class="mt-1.5 text-xs text-[#9D937D]">
                            {{ __('public_pages.forgot_identifier_hint') }}
                        </p>
                        @if ($errors->has('identifier'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('identifier') }}</p>
                        @endif
                    </div>

                    @if ($captchaEnabled)
                        <div>
                            <label for="captcha_answer" class="block text-xs font-bold uppercase tracking-wider text-[#F5E6B8] mb-1.5">
                                {{ __('public_pages.captcha_label') }}
                            </label>
                            <div class="flex items-center gap-3">
                                <span class="select-none inline-flex items-center justify-center min-h-[44px] px-4 py-2.5 rounded-xl border border-[#D4AF37]/30 bg-[#0B0904] font-mono text-lg font-bold tracking-widest text-[#F5E6B8]"
                                      aria-hidden="true">
                                    {{ $captcha['question'] }}
                                </span>
                                <input id="captcha_answer" name="captcha_answer" type="text" inputmode="numeric"
                                       autocomplete="off" required aria-describedby="captcha-hint"
                                       @if ($errors->has('captcha')) aria-invalid="true" @endif
                                       class="tl-v2-input flex-1 {{ $errors->has('captcha') ? 'border-rose-500 bg-rose-950/30' : '' }}"
                                       placeholder="{{ __('public_pages.captcha_placeholder') }}">
                            </div>
                            <input type="hidden" name="captcha_token" value="{{ $captcha['token'] }}">
                            <p id="captcha-hint" class="mt-1.5 text-xs text-[#9D937D]">{{ __('public_pages.captcha_hint') }}</p>
                            @if ($errors->has('captcha'))
                                <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('captcha') }}</p>
                            @endif
                        </div>
                    @endif
                </div>

                <button type="submit" class="tl-submit-btn">
                    {{ __('public_pages.forgot_submit') }}
                </button>
            </form>
        @else
            <form class="space-y-5" action="{{ route('password.reset.attempt') }}" method="POST" novalidate>
                @csrf
                <input type="hidden" name="token" value="{{ $resetToken }}">

                <div class="space-y-4">
                    <div>
                        <label for="email" class="block text-xs font-bold uppercase tracking-wider text-[#F5E6B8] mb-1.5">
                            {{ __('public_pages.forgot_identifier') }}
                        </label>
                        <input id="email" name="email" type="email" autocomplete="username" required
                               value="{{ old('email', $resetEmail) }}"
                               @if ($errors->has('email')) aria-invalid="true" @endif
                               class="tl-v2-input {{ $errors->has('email') ? 'border-rose-500 bg-rose-950/30' : '' }}">
                        @if ($errors->has('email'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('email') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="password" class="block text-xs font-bold uppercase tracking-wider text-[#F5E6B8] mb-1.5">
                            {{ __('public_pages.reset_new_password') }}
                        </label>
                        <input id="password" name="password" type="password" autocomplete="new-password" required
                               aria-describedby="password-hint"
                               @if ($errors->has('password')) aria-invalid="true" @endif
                               class="tl-v2-input {{ $errors->has('password') ? 'border-rose-500 bg-rose-950/30' : '' }}">
                        <p id="password-hint" class="mt-1.5 text-xs text-[#9D937D]">{{ __('public_pages.reset_password_hint') }}</p>
                        @if ($errors->has('password'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('password') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-xs font-bold uppercase tracking-wider text-[#F5E6B8] mb-1.5">
                            {{ __('public_pages.reset_confirm_password') }}
                        </label>
                        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                               @if ($errors->has('password_confirmation')) aria-invalid="true" @endif
                               class="tl-v2-input {{ $errors->has('password_confirmation') ? 'border-rose-500 bg-rose-950/30' : '' }}">
                        @if ($errors->has('password_confirmation'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('password_confirmation') }}</p>
                        @endif
                    </div>
                </div>

                <button type="submit" class="tl-submit-btn">
                    {{ __('public_pages.reset_submit') }}
                </button>
            </form>
        @endif

        <div class="text-center text-sm pt-2 border-t border-[#D4AF37]/15">
            <a href="{{ route('login') }}" class="font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4">
                {{ __('public_pages.forgot_back_to_login') }}
            </a>
        </div>
    </div>
</div>
@endsection
