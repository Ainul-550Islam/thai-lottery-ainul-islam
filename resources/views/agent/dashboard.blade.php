@extends('layouts.app')

@section('title', 'Agent Partner Portal — Thai Lottery')

@section('content')
<div class="space-y-8">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-slate-800 pb-6">
        <div>
            <div class="flex items-center space-x-2">
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-amber-500/10 text-amber-400 border border-amber-500/30">
                    Partner Program
                </span>
                <span class="text-xs text-slate-400 font-mono">CODE: {{ $agent->agent_code ?? 'AGT-PARTNER' }}</span>
            </div>
            <h1 class="text-3xl font-black text-white tracking-tight mt-1">Agent Partner Dashboard</h1>
        </div>

        <div class="flex items-center space-x-3">
            <span class="px-3 py-1 rounded-full text-xs font-bold uppercase bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                Status: {{ $agent->status->value ?? (string) ($agent->status ?? 'Active') }}
            </span>
        </div>
    </div>

    <!-- Agent Financial Metrics -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="tl-metric-card">
            <div class="tl-metric-card__header">
                <span class="tl-metric-card__label">Commission Earned</span>
                <span class="tl-metric-badge tl-metric-badge--verified">Total</span>
            </div>
            <div class="tl-metric-card__value tl-metric-card__value--highlight">
                {{ \App\Services\Finance\Money::of((string) ($agent->total_commission_earned ?? '0'), $agent->currency ?? \App\Enums\Currency::THB)->format() }}
            </div>
            <div class="tl-metric-card__footer">
                <span>Rate: {{ $agent->commission_rate !== null ? bcmul((string) $agent->commission_rate, '100', 2).'%' : '—' }}</span>
            </div>
        </div>

        <div class="tl-metric-card">
            <div class="tl-metric-card__header">
                <span class="tl-metric-card__label">Commission Paid</span>
                <span class="tl-metric-badge tl-metric-badge--verified">Disbursed</span>
            </div>
            <div class="tl-metric-card__value tl-metric-card__value--accent">
                {{ \App\Services\Finance\Money::of((string) ($agent->total_commission_paid ?? '0'), $agent->currency ?? \App\Enums\Currency::THB)->format() }}
            </div>
            <div class="tl-metric-card__footer">
                <a href="{{ route('agent.settlements') }}" class="text-emerald-400 hover:underline">Settlement History &rarr;</a>
            </div>
        </div>

        <div class="tl-metric-card">
            <div class="tl-metric-card__header">
                <span class="tl-metric-card__label">Referred Players</span>
                <span class="tl-metric-badge tl-metric-badge--verified">Active</span>
            </div>
            <div class="tl-metric-card__value">
                {{ $agent->total_referrals ?? 0 }}
            </div>
            <div class="tl-metric-card__footer">
                <span>Intake: Open</span>
            </div>
        </div>

        <div class="tl-metric-card">
            <div class="tl-metric-card__header">
                <span class="tl-metric-card__label">Pending Accrual</span>
                <span class="tl-metric-badge tl-metric-badge--warning">Current Draw</span>
            </div>
            <div class="tl-metric-card__value">
                {{ \App\Services\Finance\Money::of((string) ($pendingCommission ?? '0'), $agent->currency ?? \App\Enums\Currency::THB)->format() }}
            </div>
            <div class="tl-metric-card__footer">
                <a href="{{ route('agent.commissions') }}" class="text-emerald-400 hover:underline">View Journal &rarr;</a>
            </div>
        </div>
    </div>

    <!-- Agent Quick Links -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="tl-glass-panel p-6 space-y-4">
            <h3 class="text-base font-bold text-white">Commission Journal</h3>
            <p class="text-xs text-slate-400">Review detailed bet-by-bet accruals, calculated rates, and draw settlements.</p>
            <a href="{{ route('agent.commissions') }}" class="inline-flex items-center text-xs font-bold text-emerald-400 hover:underline">
                Open Commission Ledger &rarr;
            </a>
        </div>

        <div class="tl-glass-panel p-6 space-y-4">
            <h3 class="text-base font-bold text-white">Settlement Requests</h3>
            <p class="text-xs text-slate-400">Track automatic draw settlements and manual transfer requests into your agent wallet.</p>
            <a href="{{ route('agent.settlements') }}" class="inline-flex items-center text-xs font-bold text-emerald-400 hover:underline">
                View Settlements &rarr;
            </a>
        </div>
    </div>
</div>
@endsection
