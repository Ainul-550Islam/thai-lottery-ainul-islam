@extends('layouts.app')

@section('title', trans('agent.title'))
@section('meta_description', trans('agent.meta_description'))
@section('robots', 'noindex, nofollow')

@section('content')
<main class="next-shell next-content" id="agent-main" tabindex="-1" aria-labelledby="agent-title">
    <a class="pp-skip-link" href="#agent-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header">
        <div class="next-shell next-page-header__inner">
            <a class="next-brand" href="{{ route('agent.dashboard') }}" aria-label="{{ trans('agent.home_aria') }}">
                <span class="next-brand__mark">TL</span>
                <span>{{ config('app.name') }}<small>{{ trans('agent.brand_subtitle') }}</small></span>
            </a>
            <nav class="next-nav" aria-label="{{ trans('agent.primary_nav') }}">
                <a href="{{ route('agent.dashboard') }}" @class(['is-active' => $surface === 'dashboard'])>{{ trans('agent.nav_dashboard') }}</a>
                <a href="{{ route('agent.commissions') }}" @class(['is-active' => $surface === 'commissions'])>{{ trans('agent.nav_commissions') }}</a>
                <a href="{{ route('agent.settlements') }}" @class(['is-active' => $surface === 'settlements'])>{{ trans('agent.nav_settlements') }}</a>
                <a href="{{ route('agent.statement') }}" @class(['is-active' => $surface === 'statement'])>{{ trans('agent.nav_statement') }}</a>
                <a href="{{ route('agent.referrals') }}" @class(['is-active' => in_array($surface, ['referrals', 'referral-detail'], true)])>{{ trans('agent.nav_referrals') }}</a>
            </nav>
            <span class="next-button">{{ trans('agent.authenticated') }}</span>
        </div>
    </header>

    <section class="next-hero" aria-labelledby="agent-title">
        <div>
            <p class="next-eyebrow">{{ trans('agent.eyebrow') }}</p>
            <h1 id="agent-title">{{ trans('agent.'.($surface === 'referral-detail' ? 'referral_detail_title' : $surface.'_title')) }}</h1>
            <p>{{ trans('agent.description') }}</p>
            <p class="next-note">{{ trans('agent.no_private_data_note') }}</p>
        </div>
        <div class="next-hero-object" aria-hidden="true"><span>AG</span></div>
    </section>

    <section class="next-meta-strip" aria-label="{{ trans('agent.metadata') }}">
        <div><span>{{ trans('agent.agent_reference') }}</span><strong>{{ $agent->agent_code }}</strong></div>
        <div><span>{{ trans('agent.status') }}</span><strong>{{ $state }}</strong></div>
        <div><span>{{ trans('agent.currency') }}</span><strong>{{ $agent->currency?->value ?? trans('agent.not_configured') }}</strong></div>
        <div><span>{{ trans('agent.referrals') }}</span><strong>{{ (string) $agent->total_referrals }}</strong></div>
    </section>

    @if ($surface === 'dashboard' && $report !== null)
        <section class="next-card-grid" aria-label="{{ trans('agent.snapshot') }}">
            <article class="next-card"><h2>{{ trans('agent.referred_players') }}</h2><p>{{ $report->referredPlayers }}</p></article>
            <article class="next-card"><h2>{{ trans('agent.active_players') }}</h2><p>{{ $report->activePlayers }}</p></article>
            <article class="next-card"><h2>{{ trans('agent.turnover') }}</h2><p>{{ $report->totalTurnover }} {{ $report->currency }}</p></article>
            <article class="next-card"><h2>{{ trans('agent.commission_accrued') }}</h2><p>{{ $report->commissionAccrued }} {{ $report->currency }}</p></article>
        </section>
    @endif

    <section class="next-panel" aria-labelledby="agent-records-title">
        <div class="next-panel-header">
            <h2 id="agent-records-title">{{ trans('agent.records') }}</h2>
            <span>{{ count($records) }} {{ trans('agent.records_count') }}</span>
        </div>
        <div class="next-table-wrap">
            <table class="next-table">
                <caption class="sr-only">{{ trans('agent.records') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ trans('agent.reference') }}</th>
                        <th scope="col">{{ trans('agent.type_or_basis') }}</th>
                        <th scope="col">{{ trans('agent.amount_or_status') }}</th>
                        <th scope="col">{{ trans('agent.currency') }}</th>
                        <th scope="col">{{ trans('agent.status') }}</th>
                        <th scope="col">{{ trans('agent.created') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr>
                            <td class="font-mono">{{ $record['reference'] ?? trans('agent.not_configured') }}</td>
                            <td>{{ $record['type'] ?? $record['basis'] ?? trans('agent.not_configured') }}</td>
                            <td class="font-mono">{{ $record['amount'] ?? $record['net'] ?? $record['status'] ?? trans('agent.not_configured') }}</td>
                            <td>{{ $record['currency'] ?? trans('agent.not_configured') }}</td>
                            <td>{{ $record['status'] ?? trans('agent.not_configured') }}</td>
                            <td>{{ $record['created_at'] ?? $record['paid_at'] ?? trans('agent.no_data') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" role="status">{{ trans('agent.no_records') }} · {{ $state }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>
@endsection
