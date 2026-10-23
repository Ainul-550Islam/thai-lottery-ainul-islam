@extends('layouts.app')

@section('title', __('player.dashboard_title'))
@section('meta_description', __('player.dashboard_lead'))
@section('meta_robots', 'noindex,nofollow')

@section('content')
@php
    $payout3dTop = config('lottery.markets.3d_direct.payout_multiplier');
    $payout2d = config('lottery.markets.2d_top.payout_multiplier');
    $payout3dTod = config('lottery.markets.3d_tod.payout_multiplier');
    $accountStatus = $user->status?->value;
    $accountStatusKey = $accountStatus !== null ? 'status_'.$accountStatus : null;
    $accountStatusLabel = $accountStatusKey !== null && trans()->has('player.'.$accountStatusKey)
        ? __('player.'.$accountStatusKey)
        : __('player.not_configured');
@endphp
<div class="space-y-8">
    <!-- Welcome Banner / Active Telemetry -->
    <div class="tl-v2-card tl-v2-card--hero">
        <div class="max-w-2xl">
            <span class="tl-v2-eyebrow">{{ __('player.dash_hero_subtitle') }}</span>
            <h1 class="mt-2 text-3xl sm:text-4xl font-black text-[#FFF6D6] tracking-tight leading-tight">
                {{ __('player.welcome_back', ['name' => $user->name ?? $user->username ?? __('player.not_recorded')]) }}
            </h1>
            <p class="text-[#D4CAB3] text-sm sm:text-base mt-2">
                {{ __('player.dash_hero_subtitle') }}
            </p>
        </div>

        <div class="mt-8 pt-6 border-t border-[#D4AF37]/18 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="tl-v2-subcard">
                <span class="text-xs font-bold uppercase tracking-wider text-[#9D937D] block">{{ __('player.available_balance') }}</span>
                <span class="mt-1.5 block text-xl sm:text-2xl font-black font-mono text-[#F5E6B8]" data-tabular-nums>
                    @if ($wallet instanceof \App\Models\Wallet && $wallet->currency instanceof \App\Enums\Currency)
                        {{ \App\Services\Finance\Money::of((string) $wallet->balance, $wallet->currency)->format() }}
                    @else
                        {{ __('player.not_configured') }}
                    @endif
                </span>
            </div>
            <div class="tl-v2-subcard">
                <span class="text-xs font-bold uppercase tracking-wider text-[#9D937D] block">{{ __('player.player_account_badge') }}</span>
                <span class="mt-1.5 block text-base font-bold font-mono text-[#FFF6D6]">
                    {{ $user->username ?? __('player.not_recorded') }}
                </span>
            </div>
            <div class="tl-v2-subcard">
                <span class="text-xs font-bold uppercase tracking-wider text-[#9D937D] block">{{ __('player.status_badge') }}</span>
                <span class="mt-1.5 block text-base font-bold font-mono text-[#86EFAC] uppercase">
                    {{ $accountStatusLabel }}
                </span>
            </div>
        </div>
    </div>

    <!-- Active Draw Overview Card -->
    <div class="tl-v2-card">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 border-b border-[#D4AF37]/15 gap-4">
            <div>
                <span class="tl-v2-eyebrow">{{ __('player.next_official_draw') }}</span>
                <h2 class="text-xl sm:text-2xl font-black text-[#FFF6D6] mt-1">
                    Draw #{{ $openDraw->draw_number ?? ($upcomingDraw->draw_number ?? __('player.not_scheduled')) }}
                </h2>
            </div>
            <div>
                <span class="tl-v2-pill {{ $openDraw ? 'tl-v2-pill--verified' : 'tl-v2-pill--neutral' }}">
                    {{ $openDraw ? __('player.status_open') : ($upcomingDraw ? __('player.status_scheduled') : __('player.not_scheduled')) }}
                </span>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6 items-center">
            <div class="tl-v2-subcard">
                <span class="text-xs text-[#9D937D] uppercase tracking-wider font-bold block mb-1">{{ __('player.betting_closes_in') }}</span>
                <div id="draw-countdown" class="text-3xl sm:text-4xl font-black font-mono text-[#FFF6D6] tracking-tight" data-tabular-nums data-closes-at="{{ $openDraw?->betting_close_at?->toIso8601String() ?? '' }}"
                     data-day-label="{{ __('home.countdown.days') }}"
                     data-closed-label="{{ __('home.countdown.status_closed') }}"
                     data-status-target="draw-countdown-status" role="timer" aria-live="off">
                    @if ($openDraw?->betting_close_at)
                        -- : -- : --
                    @else
                        —
                    @endif
                </div>
                <span id="draw-countdown-status" class="sr-only" role="status" aria-live="polite"></span>
                <p class="text-xs text-[#D4CAB3] mt-2">
                    {{ __('player.draw_date_label') }} <span class="text-[#F5E6B8] font-mono">{{ $openDraw?->scheduled_at?->format('M d, Y H:i') ?? __('player.not_recorded') }}</span>
                </p>
            </div>

            <div class="space-y-2.5">
                <div class="flex justify-between text-xs py-2.5 px-3 rounded-xl border border-[#D4AF37]/15 bg-[#0B0904]/70">
                    <span class="text-[#D4CAB3] font-semibold">{{ __('player.payout_rate_3d_top') }}</span>
                    <span class="font-bold font-mono text-[#F5E6B8]" data-tabular-nums>{{ $payout3dTop !== null && $payout3dTop !== '' ? (string) $payout3dTop : __('player.not_configured') }}</span>
                </div>
                <div class="flex justify-between text-xs py-2.5 px-3 rounded-xl border border-[#D4AF37]/15 bg-[#0B0904]/70">
                    <span class="text-[#D4CAB3] font-semibold">{{ __('player.payout_rate_2d') }}</span>
                    <span class="font-bold font-mono text-[#F5E6B8]" data-tabular-nums>{{ $payout2d !== null && $payout2d !== '' ? (string) $payout2d : __('player.not_configured') }}</span>
                </div>
                <div class="flex justify-between text-xs py-2.5 px-3 rounded-xl border border-[#D4AF37]/15 bg-[#0B0904]/70">
                    <span class="text-[#D4CAB3] font-semibold">{{ __('player.payout_rate_3d_tod') }}</span>
                    <span class="font-bold font-mono text-[#F5E6B8]" data-tabular-nums>{{ $payout3dTod !== null && $payout3dTod !== '' ? (string) $payout3dTod : __('player.not_configured') }}</span>
                </div>
            </div>
        </div>

        <div class="mt-6 pt-6 border-t border-[#D4AF37]/15 flex flex-wrap justify-end gap-3">
            <a href="{{ route('player.wallet') }}" class="tl-v2-btn-outline w-full sm:w-auto">
                {{ __('player.nav_wallet') }}
            </a>
            <a href="{{ route('player.bet') }}" class="tl-v2-btn-gold w-full sm:w-auto">
                <span>{{ __('player.enter_bet_slip') }}</span>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            </a>
        </div>
    </div>

    <!-- Latest Official Results (If Any) -->
    @if($latestCompletedDraw && $latestCompletedDraw->result)
        <div class="tl-v2-card">
            <div class="flex items-center justify-between pb-4 border-b border-[#D4AF37]/15">
                <h3 class="text-lg font-extrabold text-[#FFF6D6]">{{ __('player.latest_results') }} — Draw #{{ $latestCompletedDraw->draw_number }}</h3>
                <a href="{{ route('player.draws') }}" class="text-xs text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4 font-bold">{{ __('player.view_all') }}</a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                <div class="tl-v2-subcard text-center">
                    <span class="text-xs text-[#9D937D] font-bold uppercase tracking-wider block mb-1">{{ __('player.first_prize_6') }}</span>
                    <span class="text-2xl font-black font-mono text-[#FFF6D6] tracking-wider" data-tabular-nums>
                        {{ $latestCompletedDraw->result->first_prize ?? __('player.not_published') }}
                    </span>
                </div>
                <div class="tl-v2-subcard text-center">
                    <span class="text-xs text-[#9D937D] font-bold uppercase tracking-wider block mb-1">{{ __('player.second_prize') }}</span>
                    <span class="text-2xl font-black font-mono text-[#F5E6B8] tracking-wider" data-tabular-nums>
                        {{ is_array($latestCompletedDraw->result->second_prize) && $latestCompletedDraw->result->second_prize !== [] ? implode(', ', array_map('strval', $latestCompletedDraw->result->second_prize)) : __('player.not_published') }}
                    </span>
                </div>
                <div class="tl-v2-subcard text-center">
                    <span class="text-xs text-[#9D937D] font-bold uppercase tracking-wider block mb-1">{{ __('player.third_prize') }}</span>
                    <span class="text-2xl font-black font-mono text-[#F5E6B8] tracking-wider" data-tabular-nums>
                        {{ is_array($latestCompletedDraw->result->third_prize) && $latestCompletedDraw->result->third_prize !== [] ? implode(', ', array_map('strval', $latestCompletedDraw->result->third_prize)) : __('player.not_published') }}
                    </span>
                </div>
            </div>
        </div>
    @endif

    <!-- Recent Wagers List -->
    <div class="tl-v2-card">
        <div class="flex items-center justify-between pb-5 border-b border-[#D4AF37]/15">
            <div>
                <h3 class="text-lg font-extrabold text-[#FFF6D6]">{{ __('player.my_recent_wagers') }}</h3>
                <p class="text-[#D4CAB3] text-xs mt-0.5">{{ __('player.recent_wagers_lead') }}</p>
            </div>
            <a href="{{ route('player.bets') }}" class="text-xs text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4 font-bold">{{ __('player.view_all_wagers') }}</a>
        </div>

        <div class="tl-v2-table-wrap mt-5">
            <table class="tl-v2-table">
                <caption class="sr-only">{{ __('player.my_recent_wagers') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('player.col_ticket_ref') }}</th>
                        <th scope="col">{{ __('player.col_draw') }}</th>
                        <th scope="col">{{ __('player.col_items_numbers') }}</th>
                        <th scope="col">{{ __('player.col_stake') }}</th>
                        <th scope="col">{{ __('player.col_potential_payout') }}</th>
                        <th scope="col">{{ __('player.col_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentBets as $bet)
                        @php
                            $betCurrency = $bet->currency instanceof \App\Enums\Currency ? $bet->currency : null;
                            $stakeLabel = $betCurrency instanceof \App\Enums\Currency
                                ? \App\Services\Finance\Money::of((string) $bet->stake_amount, $betCurrency)->format()
                                : __('player.not_configured');
                            $potentialPayoutLabel = $betCurrency instanceof \App\Enums\Currency
                                ? \App\Services\Finance\Money::of((string) $bet->potential_payout, $betCurrency)->format()
                                : __('player.not_configured');
                            $statusVal = $bet->status->value ?? $bet->status;
                            $statusClass = match($statusVal) {
                                'won' => 'tl-v2-pill--verified',
                                'lost' => 'tl-v2-pill--neutral',
                                'cancelled' => 'tl-v2-pill--danger',
                                default => 'tl-v2-pill--configured',
                            };
                        @endphp
                        <tr>
                            <td class="font-mono font-bold text-[#FFF6D6]">{{ $bet->bet_number }}</td>
                            <td class="font-mono text-[#D4CAB3]">#{{ $bet->draw?->draw_number ?? __('player.not_recorded') }}</td>
                            <td>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($bet->items as $item)
                                        <span class="px-2 py-0.5 bg-[#0B0904] border border-[#D4AF37]/25 rounded-lg text-xs font-mono font-bold text-[#F5E6B8]">
                                            {{ $item->market->value ?? $item->market }}: {{ $item->number }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="font-mono font-bold text-[#FFF6D6]">{{ $stakeLabel }}</td>
                            <td class="font-mono font-bold text-[#86EFAC]">{{ $potentialPayoutLabel }}</td>
                            <td>
                                <span class="tl-v2-pill {{ $statusClass }}">{{ $statusVal }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-10 text-center text-[#D4CAB3] text-sm">
                                {{ __('player.no_recent_wagers') }}
                                <a href="{{ route('player.bet') }}" class="text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4 ml-1 font-bold">{{ __('player.place_first_bet') }}</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/lottery/countdown.js')
@endpush
