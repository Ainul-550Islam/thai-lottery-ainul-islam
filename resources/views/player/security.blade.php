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
    <header class="border-b border-[#D4AF37]/15 pb-6">
        <p class="tl-v2-eyebrow">{{ __('player.security_status') }}</p>
        <h1 class="mt-1 text-3xl font-black tracking-tight text-[#FFF6D6]">{{ __('player.security_page_title') }}</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-[#D4CAB3]">{{ __('player.security_page_lead') }}</p>
    </header>

    <section class="grid grid-cols-1 gap-4 sm:grid-cols-3" aria-label="{{ __('player.security_status') }}">
        <div class="tl-v2-subcard">
            <span class="block text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ __('player.kyc_status') }}</span>
            <strong class="mt-2 block font-mono text-[#F5E6B8]">{{ $status ?? __('player.not_configured') }}</strong>
        </div>
        <div class="tl-v2-subcard">
            <span class="block text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ __('player.active_sessions') }}</span>
            <strong class="mt-2 block font-mono text-[#FFF6D6]" data-tabular-nums>{{ $sessionCount }}</strong>
        </div>
        <div class="tl-v2-subcard">
            <span class="block text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ __('player.self_exclusion_active') }}</span>
            <strong class="mt-2 block font-mono {{ $activeExclusion ? 'text-rose-300' : 'text-[#86EFAC]' }}">{{ $activeExclusion ? __('player.status_active') : __('player.status_inactive') }}</strong>
        </div>
    </section>

    @if ($activeExclusion)
        <section class="tl-v2-card border-rose-500/45 bg-rose-950/40" role="status">
            <h2 class="font-bold text-rose-200">{{ __('player.self_exclusion_active') }}</h2>
            <p class="mt-2 text-sm leading-6 text-rose-100">{{ __('player.self_exclusion_active_lead') }}</p>
            @if ($activeExclusion->ends_at)
                <p class="mt-3 font-mono text-xs text-rose-200">{{ $activeExclusion->ends_at->toIso8601String() }}</p>
            @endif
        </section>
    @endif

    <section class="tl-v2-card" aria-labelledby="security-actions-heading">
        <h2 id="security-actions-heading" class="text-xl font-black text-[#FFF6D6]">{{ __('player.security_status') }}</h2>
        <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <a class="tl-v2-btn-gold text-sm justify-start" href="{{ route('player.profile') }}">{{ __('player.security_password_action') }}</a>
            <a class="tl-v2-btn-outline text-sm justify-start" href="{{ route('account.verification') }}">{{ __('player.account_verification_action') }}</a>
            <a class="tl-v2-btn-outline text-sm justify-start" href="{{ route('settings.index') }}">{{ __('player.security_limits_action') }}</a>
            <a class="tl-v2-btn-outline text-sm justify-start" href="{{ route('account.grade') }}">{{ __('player.account_grade_action') }}</a>
        </div>
    </section>

    <section class="tl-v2-card" aria-labelledby="security-sessions-heading">
        <h2 id="security-sessions-heading" class="text-xl font-black text-[#FFF6D6]">{{ __('player.active_sessions') }}</h2>
        @if ($sessionCount === 0)
            <p class="mt-4 text-sm text-[#D4CAB3]">{{ __('player.no_security_sessions') }}</p>
        @else
            <div class="mt-4 tl-v2-table-wrap">
                <table class="tl-v2-table">
                    <caption class="sr-only">{{ __('player.active_sessions') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('player.session_device') }}</th>
                            <th scope="col">{{ __('player.session_status') }}</th>
                            <th scope="col">{{ __('player.session_last_seen') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sessions as $session)
                            <tr>
                                <td class="text-[#FAF6EC]">{{ $session->user_agent ?? __('player.not_recorded') }}</td>
                                <td class="font-mono text-[#86EFAC]">{{ $session->status->value }}</td>
                                <td class="text-[#D4CAB3] font-mono">{{ $session->last_seen_at?->toIso8601String() ?? __('player.not_recorded') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
