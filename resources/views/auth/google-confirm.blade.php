@extends('layouts.app')

@section('title', __('public_pages.google_confirm_title'))
@section('meta_robots', 'noindex, nofollow')

@section('content')
<div class="min-h-[75vh] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-6 bg-slate-900/90 border border-slate-800 rounded-2xl p-8 shadow-2xl backdrop-blur">

        {{-- What we actually received from Google (the verified id_token). --}}
        <div class="flex items-center gap-4 rounded-xl border border-amber-400/30 bg-slate-950/60 p-4">
            @if ($profile->picture)
                <img src="{{ $profile->picture }}" alt="" width="56" height="56" referrerpolicy="no-referrer"
                     class="h-14 w-14 rounded-full ring-2 ring-amber-400/60 object-cover">
            @endif
            <div class="min-w-0">
                <p class="truncate text-base font-bold text-white">{{ __('public_pages.google_confirm_welcome', ['name' => $profile->name]) }}</p>
                <p class="truncate text-xs text-slate-400">{{ $profile->email }}</p>
            </div>
        </div>

        <p class="text-sm text-slate-300">{{ __('public_pages.google_confirm_lead') }}</p>

        @if ($errors->any())
            <div class="bg-rose-950/60 border border-rose-600/50 rounded-xl p-4 text-rose-200 text-sm" role="alert">
                <ul class="list-disc list-inside space-y-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <form action="{{ route('social.google.complete') }}" method="POST" class="space-y-5" novalidate>
            @csrf
            <input type="hidden" name="terms_version" value="{{ $termsDocument['version'] ?? '' }}">
            <input type="hidden" name="terms_hash" value="{{ $termsDocument['content_hash'] ?? '' }}">
            <input type="hidden" name="privacy_version" value="{{ $privacyDocument['version'] ?? '' }}">
            <input type="hidden" name="privacy_hash" value="{{ $privacyDocument['content_hash'] ?? '' }}">

            <div>
                <label for="referral_id" class="block text-sm font-medium text-slate-300 mb-1">{{ __('public_pages.register_referral') }}</label>
                <input id="referral_id" name="referral_id" type="text" required maxlength="64" autocomplete="off" value="{{ old('referral_id') }}"
                       class="block w-full px-4 py-3 border rounded-xl sm:text-sm border-slate-700 bg-slate-950 text-slate-100">
            </div>

            <div>
                <label for="date_of_birth" class="block text-sm font-medium text-slate-300 mb-1">{{ __('public_pages.register_dob') }}</label>
                <input id="date_of_birth" name="date_of_birth" type="date" required value="{{ old('date_of_birth') }}"
                       class="block w-full px-4 py-3 border rounded-xl sm:text-sm border-slate-700 bg-slate-950 text-slate-100">
            </div>

            <div class="flex items-start">
                <input id="age_attestation" name="age_attestation" type="checkbox" value="1" required @checked(old('age_attestation'))
                       class="mt-1 h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-slate-700 rounded bg-slate-950">
                <label for="age_attestation" class="ml-3 block text-sm text-slate-300">
                    {{ __('public_pages.register_age_attestation', ['age' => min(255, max(18, (int) config('legal.minimum_age', 18)))]) }}
                </label>
            </div>

            <div class="flex items-start">
                <input id="terms" name="terms" type="checkbox" value="1" required @checked(old('terms'))
                       class="mt-1 h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-slate-700 rounded bg-slate-950">
                <label for="terms" class="ml-3 block text-sm text-slate-300">
                    {{ __('public_pages.register_terms') }}
                    <a class="text-emerald-400 underline" href="{{ route('terms') }}" target="_blank" rel="noopener noreferrer">{{ __('public_pages.register_terms_link') }}</a>
                </label>
            </div>

            <div class="flex items-start">
                <input id="privacy_notice" name="privacy_notice" type="checkbox" value="1" required @checked(old('privacy_notice'))
                       class="mt-1 h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-slate-700 rounded bg-slate-950">
                <label for="privacy_notice" class="ml-3 block text-sm text-slate-300">
                    {{ __('public_pages.register_privacy_notice') }}
                    <a class="text-emerald-400 underline" href="{{ route('privacy') }}" target="_blank" rel="noopener noreferrer">{{ __('public_pages.register_privacy_link') }}</a>
                </label>
            </div>

            <button type="submit" @disabled(! $legalAvailable)
                    class="w-full py-3.5 px-4 text-sm font-bold rounded-xl text-slate-950 bg-gradient-to-r from-amber-400 via-emerald-400 to-emerald-500 shadow-lg disabled:cursor-not-allowed disabled:opacity-50">
                {{ __('public_pages.google_confirm_submit') }}
            </button>
            <a href="{{ route('login') }}" class="block text-center text-xs text-slate-400 hover:text-slate-200">{{ __('public_pages.google_confirm_cancel') }}</a>
        </form>
    </div>
</div>
@endsection
