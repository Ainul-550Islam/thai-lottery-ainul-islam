@extends('layouts.admin')

@section('title', __('admin.title'))

@section('content')
<div class="flex w-full flex-col gap-6" aria-labelledby="admin-page-title">
    @php
        $panelKey = (string) ($panel ?? 'dashboard');
        $panelTitle = trans('admin.'.$panelKey);
        if ($panelTitle === 'admin.'.$panelKey) {
            $panelTitle = trans('admin.dashboard');
        }
    @endphp

    <header>
        <p class="text-xs uppercase tracking-[0.2em] text-emerald-400">{{ __('admin.title') }}</p>
        <h1 id="admin-page-title" class="lf-page-title">{{ $panelTitle }}</h1>
    </header>

    @if ($panelKey === 'dashboard')
        <section class="lf-kpi-grid" aria-label="{{ __('admin.analytics') }}">
            @foreach ([
                ['label' => __('admin.total_wagered'), 'value' => $kpis['totalWagered'] ?? __('admin.unavailable'), 'trend' => $kpis['totalWageredTrend'] ?? __('admin.unavailable')],
                ['label' => __('admin.house_profit'), 'value' => $kpis['houseGrossProfit'] ?? __('admin.unavailable'), 'trend' => $kpis['houseGrossProfitTrend'] ?? __('admin.unavailable')],
                ['label' => __('admin.active_bets'), 'value' => (string) ($kpis['activeInPlayBets'] ?? __('admin.unavailable')), 'trend' => $kpis['activeInPlayBetsState'] ?? __('admin.unavailable')],
                ['label' => __('admin.pending_withdrawals'), 'value' => (string) ($kpis['pendingWithdrawals'] ?? __('admin.unavailable')), 'trend' => $kpis['pendingWithdrawalsState'] ?? __('admin.unavailable')],
            ] as $metric)
                <article class="lf-kpi-card">
                    <span class="lf-kpi-label">{{ $metric['label'] }}</span>
                    <strong class="lf-kpi-value">{{ $metric['value'] }}</strong>
                    <span class="lf-kpi-trend lf-kpi-trend--neutral">{{ __('admin.state') }}: {{ $metric['trend'] }}</span>
                </article>
            @endforeach
        </section>

        <section class="lf-panel-card" aria-labelledby="admin-feed-title">
            <div class="lf-panel-header">
                <h2 id="admin-feed-title" class="lf-panel-title">{{ __('admin.live_feed') }}</h2>
                <span class="text-xs text-slate-400">{{ count($transactions ?? []) }} {{ __('admin.records') }}</span>
            </div>
            <div class="lf-table-container">
                <table class="lf-table">
                    <caption class="sr-only">{{ __('admin.live_feed') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.reference') }}</th>
                            <th scope="col">{{ __('admin.type') }}</th>
                            <th scope="col">{{ __('admin.status') }}</th>
                            <th scope="col">{{ __('admin.amount') }}</th>
                            <th scope="col">{{ __('admin.currency') }}</th>
                            <th scope="col">{{ __('admin.created') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transactions ?? [] as $row)
                            <tr>
                                <td class="lf-tx-id">{{ $row['reference'] }}</td>
                                <td>{{ $row['type'] }}</td>
                                <td>{{ $row['status'] }}</td>
                                <td class="font-mono">{{ $row['amount'] }}</td>
                                <td>{{ $row['currency'] }}</td>
                                <td class="text-slate-400">{{ $row['created_at'] ?? __('admin.no_data') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-8 text-center text-slate-400" role="status">{{ __('admin.no_records') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @elseif (in_array($panelKey, ['reconciliation', 'risk', 'compliance', 'payments', 'withdrawals'], true))
        <section class="lf-panel-card" role="status" aria-live="polite">
            <h2 class="lf-panel-title">{{ __('admin.state') }}: {{ $state ?? __('admin.unavailable') }}</h2>
            <p class="mt-3 text-sm text-slate-400">
                @if ($panelKey === 'reconciliation')
                    {{ __('admin.not_run') }}
                @else
                    {{ __('admin.no_records') }}
                @endif
            </p>
        </section>
    @else
        <section class="lf-panel-card" aria-labelledby="admin-records-title">
            <div class="lf-panel-header">
                <h2 id="admin-records-title" class="lf-panel-title">{{ __('admin.records') }}</h2>
                <span class="text-xs text-slate-400">{{ count($records ?? []) }} {{ __('admin.records') }}</span>
            </div>
            <div class="lf-table-container">
                <table class="lf-table">
                    <caption class="sr-only">{{ $panelTitle }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.reference') }}</th>
                            <th scope="col">{{ __('admin.type') }}</th>
                            <th scope="col">{{ __('admin.status') }}</th>
                            <th scope="col">{{ __('admin.amount') }}</th>
                            <th scope="col">{{ __('admin.currency') }}</th>
                            <th scope="col">{{ __('admin.created') }}</th>
                            @if ($panelKey === 'claims')
                                <th scope="col">{{ __('admin.ticket') }}</th>
                                <th scope="col">{{ __('admin.draw') }}</th>
                                <th scope="col">{{ __('admin.gross') }}</th>
                                <th scope="col">{{ __('admin.stamp_duty') }}</th>
                                <th scope="col">{{ __('admin.payment_state') }}</th>
                                <th scope="col">{{ __('admin.hold_state') }}</th>
                                <th scope="col">{{ __('admin.age_state') }}</th>
                            @elseif ($panelKey === 'freezes')
                                <th scope="col">{{ __('admin.ticket') }}</th>
                                <th scope="col">{{ __('admin.draw') }}</th>
                                <th scope="col">{{ __('admin.authority') }}</th>
                                <th scope="col">{{ __('admin.case_reference') }}</th>
                                <th scope="col">{{ __('admin.evidence_reference') }}</th>
                            @endif
                            @if ($panelKey === 'kyc')
                                <th scope="col">{{ __('admin.actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($records ?? [] as $row)
                            <tr>
                                <td class="lf-tx-id">{{ $row['reference'] ?? $row['draw'] ?? __('admin.no_data') }}</td>
                                <td>{{ $row['type'] ?? $row['status'] ?? __('admin.no_data') }}</td>
                                <td>{{ $row['status'] ?? __('admin.no_data') }}</td>
                                <td class="font-mono">{{ $row['amount'] ?? __('admin.no_data') }}</td>
                                <td>{{ $row['currency'] ?? __('admin.no_data') }}</td>
                                <td class="text-slate-400">{{ $row['created_at'] ?? $row['date'] ?? __('admin.no_data') }}</td>
                                @if ($panelKey === 'claims')
                                    <td>{{ $row['ticket'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['draw'] ?? __('admin.no_data') }}</td>
                                    <td class="font-mono">{{ $row['gross'] ?? __('admin.no_data') }}</td>
                                    <td class="font-mono">{{ $row['stamp_duty'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['payment_status'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['hold_status'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['age_state'] ?? __('admin.no_data') }}</td>
                                @elseif ($panelKey === 'freezes')
                                    <td>{{ $row['ticket'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['draw'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['authority'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['case_reference'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['evidence_reference'] ?? __('admin.no_data') }}</td>
                                @endif
                                @if ($panelKey === 'kyc')
                                    <td>
                                        <div class="flex flex-wrap gap-2">
                                            <a href="{{ $row['download_url'] }}" class="text-xs text-emerald-400 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400">{{ __('admin.download') }}</a>
                                            <form method="POST" action="{{ $row['approve_url'] }}">
                                                @csrf
                                                <button type="submit" class="text-xs text-emerald-400 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400">{{ __('admin.approve') }}</button>
                                            </form>
                                            <form method="POST" action="{{ $row['reject_url'] }}">
                                                @csrf
                                                <button type="submit" class="text-xs text-rose-400 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-400">{{ __('admin.reject') }}</button>
                                            </form>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $panelKey === 'kyc' ? 7 : ($panelKey === 'claims' ? 13 : ($panelKey === 'freezes' ? 11 : 6)) }}" class="py-8 text-center text-slate-400" role="status">{{ $state ? __('admin.state').': '.$state : __('admin.no_records') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
@endsection
