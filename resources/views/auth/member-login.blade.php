@extends('layouts.app')

@section('title', __('public_pages.login_meta_title'))
@section('meta_robots', 'noindex, nofollow')
@section('meta_description', __('public_pages.login_meta_description'))

@section('content')
<div class="min-h-[75vh] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8 bg-slate-900/90 border border-slate-800 rounded-2xl p-8 shadow-2xl backdrop-blur">
        <div class="text-center">
            <h2 class="text-3xl font-extrabold tracking-tight text-white">
                {{ __('public_pages.login_heading') }}
            </h2>
            <p class="mt-2 text-sm text-slate-400">
                {{ __('public_pages.login_lead') }}
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

        <form class="mt-8 space-y-6" action="{{ route('login.attempt') }}" method="POST" novalidate>
            @csrf

            <div class="space-y-4">
                <div>
                    <label for="login" class="block text-sm font-medium text-slate-300 mb-1">
                        {{ __('public_pages.login_identifier') }}
                    </label>
                    <input id="login" name="login" type="text" autocomplete="username" required
                           value="{{ old('login') }}"
                           aria-describedby="login-hint"
                           @if ($errors->has('login')) aria-invalid="true" @endif
                           class="appearance-none relative block w-full px-4 py-3 border rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent sm:text-sm
                                  {{ $errors->has('login') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}"
                           placeholder="{{ __('public_pages.login_identifier_placeholder') }}">
                    <p id="login-hint" class="mt-1 text-xs text-slate-500">
                        {{ __('public_pages.login_identifier_hint') }}
                    </p>
                    @if ($errors->has('login'))
                        <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('login') }}</p>
                    @endif
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-300 mb-1">
                        {{ __('public_pages.login_password') }}
                    </label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required
                           @if ($errors->has('password')) aria-invalid="true" @endif
                           class="appearance-none relative block w-full px-4 py-3 border rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent sm:text-sm
                                  {{ $errors->has('password') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}"
                           placeholder="••••••••">
                    @if ($errors->has('password'))
                        <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('password') }}</p>
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
                                   autocomplete="off" required
                                   aria-describedby="captcha-hint"
                                   @if ($errors->has('captcha')) aria-invalid="true" @endif
                                   class="appearance-none relative block flex-1 px-4 py-3 border rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent sm:text-sm
                                          {{ $errors->has('captcha') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}"
                                   placeholder="{{ __('public_pages.captcha_placeholder') }}">
                        </div>
                        <input type="hidden" name="captcha_token" value="{{ $captcha['token'] }}">
                        <p id="captcha-hint" class="mt-1 text-xs text-slate-500">
                            {{ __('public_pages.captcha_hint') }}
                        </p>
                        @if ($errors->has('captcha'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('captcha') }}</p>
                        @endif
                    </div>
                @endif

                <div class="flex items-center">
                    <input id="remember" name="remember" type="checkbox" value="1"
                           class="h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-slate-700 rounded bg-slate-950">
                    <label for="remember" class="ml-2 block text-sm text-slate-400">
                        {{ __('public_pages.login_remember') }}
                    </label>
                </div>
            </div>

            <div>
                <button type="submit" class="group relative w-full flex justify-center py-3.5 px-4 border border-transparent text-sm font-bold rounded-xl text-slate-950 bg-gradient-to-r from-amber-400 via-emerald-400 to-emerald-500 hover:from-amber-500 hover:to-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 shadow-lg transition duration-200">
                    {{ __('public_pages.login_submit') }}
                </button>
            </div>
        </form>

        {{-- Social login --}}
        <div class="mt-8 space-y-4">
            <div class="relative">
                <div class="absolute inset-0 flex items-center">
                    <span class="w-full border-t border-slate-700"></span>
                </div>
                <div class="relative flex justify-center text-xs uppercase">
                    <span class="px-2 text-slate-400 bg-slate-900/90">
                        {{ __('public_pages.social_or_continue_with') }}
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <a href="{{ route('social.redirect', 'facebook') }}"
                   class="flex items-center justify-center px-4 py-3 border border-slate-700 rounded-xl bg-slate-800 text-slate-100 hover:bg-slate-700 hover:border-emerald-400 transition text-sm font-medium">
                    <span class="mr-2">f</span> {{ __('public_pages.social_facebook') }}
                </a>
                <a href="{{ route('social.redirect', 'twitter') }}"
                   class="flex items-center justify-center px-4 py-3 border border-slate-700 rounded-xl bg-slate-800 text-slate-100 hover:bg-slate-700 hover:border-emerald-400 transition text-sm font-medium">
                    <span class="mr-2">X</span> {{ __('public_pages.social_twitter') }}
                </a>
                <a href="{{ route('social.redirect', 'google') }}"
                   class="flex items-center justify-center px-4 py-3 border border-slate-700 rounded-xl bg-slate-800 text-slate-100 hover:bg-slate-700 hover:border-emerald-400 transition text-sm font-medium">
                    <span class="mr-2">g</span> {{ __('public_pages.social_google') }}
                </a>
            </div>

            <p class="text-xs text-slate-500 text-center">
                {{ __('public_pages.social_account_link_note') }}
            </p>
        </div>

        <div class="flex items-center justify-between text-sm">
            <a href="{{ route('register') }}" class="font-medium text-emerald-400 hover:text-emerald-300">
                {{ __('public_pages.login_register_link') }}
            </a>
            <a href="{{ route('password.request') }}" class="font-medium text-emerald-400 hover:text-emerald-300">
                {{ __('public_pages.login_forgot_link') }}
            </a>
        </div>
    </div>
</div>
@endsection
