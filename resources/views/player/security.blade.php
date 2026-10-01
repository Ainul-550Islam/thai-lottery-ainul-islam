@extends('layouts.app')

@section('title', __('player.security_page_title'))
@section('meta_description', __('player.security_page_lead'))
@section('meta_robots', 'noindex,nofollow')

@section('content')
@php
    $status = $kycStatus ?? null;
    $sessionCount = isset($sessions) && method_exists($sessions, 'count') ? $sessions->count() : 0;
@endphp

<div class="mx-auto max-w-5xl space-y-8">
    <header class="border-b border-slate-800 pb-6">
        <p class="text-xs font-black uppercase tracking-[0.18em] text-amber-400">{{ __('player.security_status') }}</p>
        <h1 class="mt-1 text-3xl font-black tracking-tight text-white">{{ __('player.security_page_title') }}</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">{{ __('player.security_page_lead') }}</p>
    </header>

    <section class="grid grid-cols-1 gap-4 sm:grid-cols-3" aria-label="{{ __('player.security_status') }}">
        <div class="rounded-2xl border border-slate-800 bg-slate-900/90 p-5">
            <span class="block text-xs uppercase tracking-wider text-slate-500">{{ __('player.kyc_status') }}</span>
            <strong class="mt-2 block font-mono text-emerald-300">{{ $status ?? __('player.not_configured') }}</strong>
        </div>
        <div class="rounded-2xl border border-slate-800 bg-slate-900/90 p-5">
            <span class="block text-xs uppercase tracking-wider text-slate-500">{{ __('player.active_sessions') }}</span>
            <strong class="mt-2 block font-mono text-white">{{ $sessionCount }}</strong>
        </div>
        <div class="rounded-2xl border border-slate-800 bg-slate-900/90 p-5">
            <span class="block text-xs uppercase tracking-wider text-slate-500">{{ __('player.self_exclusion_active') }}</span>
            <strong class="mt-2 block font-mono {{ $activeExclusion ? 'text-rose-300' : 'text-emerald-300' }}">{{ $activeExclusion ? __('player.status_active') : __('player.status_inactive') }}</strong>
        </div>
    </section>

    @if ($activeExclusion)
        <section class="rounded-2xl border border-rose-500/30 bg-rose-950/30 p-5" role="status">
            <h2 class="font-bold text-rose-200">{{ __('player.self_exclusion_active') }}</h2>
            <p class="mt-2 text-sm leading-6 text-rose-100/75">{{ __('player.self_exclusion_active_lead') }}</p>
            @if ($activeExclusion->ends_at)
                <p class="mt-3 font-mono text-xs text-rose-200">{{ $activeExclusion->ends_at->toIso8601String() }}</p>
            @endif
        </section>
    @endif

    <section class="rounded-3xl border border-slate-800 bg-slate-900/90 p-6 shadow-xl sm:p-8" aria-labelledby="security-actions-heading">
        <h2 id="security-actions-heading" class="text-xl font-black text-white">{{ __('player.security_status') }}</h2>
        <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <a class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm font-bold text-amber-300 hover:bg-amber-500/20" href="{{ route('player.profile') }}">{{ __('player.security_password_action') }}</a>
            <a class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm font-bold text-emerald-300 hover:bg-emerald-500/20" href="{{ route('account.verification') }}">{{ __('player.account_verification_action') }}</a>
            <a class="rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm font-bold text-slate-200 hover:bg-slate-800" href="{{ route('settings.index') }}">{{ __('player.security_limits_action') }}</a>
            <a class="rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm font-bold text-slate-200 hover:bg-slate-800" href="{{ route('account.grade') }}">{{ __('player.account_grade_action') }}</a>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-800 bg-slate-900/90 p-6 shadow-xl sm:p-8" aria-labelledby="security-sessions-heading">
        <h2 id="security-sessions-heading" class="text-xl font-black text-white">{{ __('player.active_sessions') }}</h2>
        @if ($sessionCount === 0)
            <p class="mt-4 text-sm text-slate-500">{{ __('player.no_security_sessions') }}</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <thead class="border-b border-slate-800 text-xs uppercase tracking-wider text-slate-500">
                        <tr><th class="px-3 py-3">{{ __('player.session_device') }}</th><th class="px-3 py-3">{{ __('player.session_status') }}</th><th class="px-3 py-3">{{ __('player.session_last_seen') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/70">
                        @foreach ($sessions as $session)
                            <tr><td class="px-3 py-3 text-slate-300">{{ $session->user_agent ?? __('player.not_recorded') }}</td><td class="px-3 py-3 font-mono text-emerald-300">{{ $session->status->value }}</td><td class="px-3 py-3 text-slate-400">{{ $session->last_seen_at?->toIso8601String() ?? __('player.not_recorded') }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
