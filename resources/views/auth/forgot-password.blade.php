@extends('layouts.app')

@section('title', __('public_pages.forgot_meta_title'))
@section('meta_description', __('public_pages.forgot_meta_description'))

@section('content')
<div class="min-h-[75vh] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8 bg-slate-900/90 border border-slate-800 rounded-2xl p-8 shadow-2xl backdrop-blur">
        <div class="text-center">
            <h2 class="text-3xl font-extrabold tracking-tight text-white">
                {{ __('public_pages.forgot_heading') }}
            </h2>
            <p class="mt-2 text-sm text-slate-400">
                {{ __('public_pages.forgot_lead') }}
            </p>
        </div>

        @if (session('status'))
            <div class="bg-emerald-950/60 border border-emerald-600/50 rounded-xl p-4 text-emerald-200 text-sm" role="status">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="bg-rose-950/60 border border-rose-600/50 rounded-xl p-4 text-rose-200 text-sm" role="alert" aria-live="polite">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ============ State A: the recovery request form ============ --}}
        @if ($resetToken === null)
            <form class="mt-8 space-y-6" action="{{ route('password.request.attempt') }}" method="POST" novalidate>
                @csrf

                <div class="space-y-4">
                    <div>
                        <label for="identifier" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.forgot_identifier') }}
                        </label>
                        <input id="identifier" name="identifier" type="text" autocomplete="username" required
                               value="{{ old('identifier') }}"
                               aria-describedby="identifier-hint"
                               @if ($errors->has('identifier')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('identifier') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}"
                               placeholder="{{ __('public_pages.forgot_identifier_placeholder') }}">
                        <p id="identifier-hint" class="mt-1 text-xs text-slate-500">
                            {{ __('public_pages.forgot_identifier_hint') }}
                        </p>
                        @if ($errors->has('identifier'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('identifier') }}</p>
                        @endif
                    </div>

                    @if ($captchaEnabled)
                        <div>
                            <label for="captcha_answer" class="block text-sm font-medium text-slate-300 mb-1">
                                {{ __('public_pages.captcha_label') }}
                            </label>
                            <div class="flex items-center gap-3">
                                <span class="select-none inline-flex items-center justify-center px-4 py-3 rounded-xl border border-slate-700 bg-slate-950 font-mono text-lg tracking-widest text-emerald-300"
                                      aria-hidden="true">
                                    {{ $captcha['question'] }}
                                </span>
                                <input id="captcha_answer" name="captcha_answer" type="text" inputmode="numeric"
                                       autocomplete="off" required aria-describedby="captcha-hint"
                                       @if ($errors->has('captcha')) aria-invalid="true" @endif
                                       class="block flex-1 px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('captcha') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}"
                                       placeholder="{{ __('public_pages.captcha_placeholder') }}">
                            </div>
                            <input type="hidden" name="captcha_token" value="{{ $captcha['token'] }}">
                            <p id="captcha-hint" class="mt-1 text-xs text-slate-500">{{ __('public_pages.captcha_hint') }}</p>
                            @if ($errors->has('captcha'))
                                <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('captcha') }}</p>
                            @endif
                        </div>
                    @endif
                </div>

                <div>
                    <button type="submit" class="group relative w-full flex justify-center py-3.5 px-4 border border-transparent text-sm font-bold rounded-xl text-slate-950 bg-gradient-to-r from-amber-400 via-emerald-400 to-emerald-500 hover:from-amber-500 hover:to-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 shadow-lg transition duration-200">
                        {{ __('public_pages.forgot_submit') }}
                    </button>
                </div>
            </form>

        {{-- ============ State B: the new-password form (token-gated) ============ --}}
        @else
            <form class="mt-8 space-y-6" action="{{ route('password.reset.attempt') }}" method="POST" novalidate>
                @csrf
                <input type="hidden" name="token" value="{{ $resetToken }}">

                <div class="space-y-4">
                    <div>
                        <label for="email" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.forgot_identifier') }}
                        </label>
                        <input id="email" name="email" type="email" autocomplete="username" required
                               value="{{ old('email', $resetEmail) }}"
                               @if ($errors->has('email')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('email') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}">
                        @if ($errors->has('email'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('email') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.reset_new_password') }}
                        </label>
                        <input id="password" name="password" type="password" autocomplete="new-password" required
                               aria-describedby="password-hint"
                               @if ($errors->has('password')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('password') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}">
                        <p id="password-hint" class="mt-1 text-xs text-slate-500">{{ __('public_pages.reset_password_hint') }}</p>
                        @if ($errors->has('password'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('password') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.reset_confirm_password') }}
                        </label>
                        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                               @if ($errors->has('password_confirmation')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('password_confirmation') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}">
                        @if ($errors->has('password_confirmation'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('password_confirmation') }}</p>
                        @endif
                    </div>
                </div>

                <div>
                    <button type="submit" class="group relative w-full flex justify-center py-3.5 px-4 border border-transparent text-sm font-bold rounded-xl text-slate-950 bg-gradient-to-r from-amber-400 via-emerald-400 to-emerald-500 hover:from-amber-500 hover:to-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 shadow-lg transition duration-200">
                        {{ __('public_pages.reset_submit') }}
                    </button>
                </div>
            </form>
        @endif

        <div class="text-center text-sm">
            <a href="{{ route('login') }}" class="font-medium text-emerald-400 hover:text-emerald-300">
                {{ __('public_pages.forgot_back_to_login') }}
            </a>
        </div>
    </div>
</div>
@endsection
