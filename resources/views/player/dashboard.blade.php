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
    <!-- Welcome Banner / Active Draw Countdown -->
    <div class="relative overflow-hidden bg-gradient-to-r from-slate-900 via-slate-900 to-emerald-950 border border-slate-800 rounded-3xl p-6 sm:p-10 shadow-2xl">
        <div class="relative z-10 max-w-2xl">
            <span class="px-3 py-1 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-bold rounded-full uppercase tracking-wider mb-4 inline-block">
                {{ __('player.dash_hero_subtitle') }}
            </span>
            <h1 class="text-3xl sm:text-4xl font-black text-white tracking-tight leading-tight">
                {{ __('player.welcome_back', ['name' => $user->name ?? $user->username ?? __('player.not_recorded')]) }}
            </h1>
            <p class="text-slate-400 text-sm sm:text-base mt-2">
                {{ __('player.dash_hero_subtitle') }}
            </p>
        </div>

        <div class="mt-8 pt-6 border-t border-slate-800/80 grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div>
                <span class="text-xs text-slate-400 block">{{ __('player.available_balance') }}</span>
                <span class="text-xl sm:text-2xl font-bold font-mono text-amber-400">
                    @if ($wallet instanceof \App\Models\Wallet && $wallet->currency instanceof \App\Enums\Currency)
                        {{ \App\Services\Finance\Money::of((string) $wallet->balance, $wallet->currency)->format() }}
                    @else
                        {{ __('player.not_configured') }}
                    @endif
                </span>
            </div>
            <div>
                <span class="text-xs text-slate-400 block">{{ __('player.player_account_badge') }}</span>
                <span class="text-sm font-bold font-mono text-slate-300">
                    {{ $user->username ?? __('player.not_recorded') }}
                </span>
            </div>
            <div>
                <span class="text-xs text-slate-400 block">{{ __('player.status_badge') }}</span>
                <span class="text-sm font-bold font-mono text-emerald-400 uppercase">
                    {{ $accountStatusLabel }}
                </span>
            </div>
        </div>
    </div>

    <!-- Active Draw Overview Card -->
    <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 border-b border-slate-800 gap-4">
            <div>
                <span class="text-xs font-bold text-emerald-400 uppercase tracking-wider">{{ __('player.next_official_draw') }}</span>
                <h2 class="text-xl sm:text-2xl font-black text-white mt-1">
                    Draw #{{ $openDraw->draw_number ?? ($upcomingDraw->draw_number ?? __('player.not_scheduled')) }}
                </h2>
            </div>
            <div class="flex items-center space-x-2">
                <span class="px-3 py-1 rounded-full text-xs font-bold font-mono {{ $openDraw ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30' : 'bg-slate-800 text-slate-400' }}">
                    {{ $openDraw ? __('player.status_open') : ($upcomingDraw ? __('player.status_scheduled') : __('player.not_scheduled')) }}
                </span>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6 items-center">
            <div>
                <span class="text-xs text-slate-400 uppercase tracking-wider font-semibold block mb-1">{{ __('player.betting_closes_in') }}</span>
                <div id="draw-countdown" class="text-3xl sm:text-4xl font-extrabold font-mono text-emerald-400 tracking-tight" data-closes-at="{{ $openDraw?->betting_close_at?->toIso8601String() ?? '' }}">
                    @if ($openDraw?->betting_close_at)
                        -- : -- : --
                    @else
                        —
                    @endif
                </div>
                <p class="text-xs text-slate-500 mt-2">
                    {{ __('player.draw_date_label') }} <span class="text-slate-300 font-mono">{{ $openDraw?->scheduled_at?->format('M d, Y H:i') ?? __('player.not_recorded') }}</span>
                </p>
            </div>

            <div class="space-y-2">
                <div class="flex justify-between text-xs py-2 border-b border-slate-800">
                    <span class="text-slate-400">{{ __('player.payout_rate_3d_top') }}</span>
                    <span class="font-bold font-mono text-amber-400">{{ $payout3dTop !== null && $payout3dTop !== '' ? (string) $payout3dTop : __('player.not_configured') }}</span>
                </div>
                <div class="flex justify-between text-xs py-2 border-b border-slate-800">
                    <span class="text-slate-400">{{ __('player.payout_rate_2d') }}</span>
                    <span class="font-bold font-mono text-amber-400">{{ $payout2d !== null && $payout2d !== '' ? (string) $payout2d : __('player.not_configured') }}</span>
                </div>
                <div class="flex justify-between text-xs py-2">
                    <span class="text-slate-400">{{ __('player.payout_rate_3d_tod') }}</span>
                    <span class="font-bold font-mono text-amber-400">{{ $payout3dTod !== null && $payout3dTod !== '' ? (string) $payout3dTod : __('player.not_configured') }}</span>
                </div>
            </div>
        </div>

        <div class="mt-6 pt-6 border-t border-slate-800 flex justify-end">
            <a href="{{ route('player.bet') }}" class="w-full sm:w-auto px-6 py-3 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl shadow-lg transition duration-150 text-center flex items-center justify-center space-x-2">
                <span>{{ __('player.enter_bet_slip') }}</span>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            </a>
        </div>
    </div>

    <!-- Latest Official Results (If Any) -->
    @if($latestCompletedDraw && $latestCompletedDraw->result)
        <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
            <div class="flex items-center justify-between pb-4 border-b border-slate-800">
                <h3 class="text-lg font-bold text-white">{{ __('player.latest_results') }} — Draw #{{ $latestCompletedDraw->draw_number }}</h3>
                <a href="{{ route('player.draws') }}" class="text-xs text-emerald-400 hover:underline font-semibold">{{ __('player.view_all') }}</a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                <div class="p-4 bg-slate-950 border border-slate-800/80 rounded-2xl text-center">
                    <span class="text-xs text-slate-400 uppercase tracking-wider block mb-1">{{ __('player.first_prize_6') }}</span>
                    <span class="text-2xl font-black font-mono text-amber-400 tracking-wider">
                        {{ $latestCompletedDraw->result->first_prize ?? __('player.not_published') }}
                    </span>
                </div>
                <div class="p-4 bg-slate-950 border border-slate-800/80 rounded-2xl text-center">
                    <span class="text-xs text-slate-400 uppercase tracking-wider block mb-1">{{ __('player.second_prize') }}</span>
                    <span class="text-2xl font-black font-mono text-emerald-400 tracking-wider">
                        {{ is_array($latestCompletedDraw->result->second_prize) && $latestCompletedDraw->result->second_prize !== [] ? implode(', ', array_map('strval', $latestCompletedDraw->result->second_prize)) : __('player.not_published') }}
                    </span>
                </div>
                <div class="p-4 bg-slate-950 border border-slate-800/80 rounded-2xl text-center">
                    <span class="text-xs text-slate-400 uppercase tracking-wider block mb-1">{{ __('player.third_prize') }}</span>
                    <span class="text-2xl font-black font-mono text-emerald-400 tracking-wider">
                        {{ is_array($latestCompletedDraw->result->third_prize) && $latestCompletedDraw->result->third_prize !== [] ? implode(', ', array_map('strval', $latestCompletedDraw->result->third_prize)) : __('player.not_published') }}
                    </span>
                </div>
            </div>
        </div>
    @endif

    <!-- Recent Wagers List -->
    <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
        <div class="flex items-center justify-between pb-6 border-b border-slate-800">
            <div>
                <h3 class="text-lg font-bold text-white">{{ __('player.my_recent_wagers') }}</h3>
                <p class="text-slate-400 text-xs mt-0.5">{{ __('player.recent_wagers_lead') }}</p>
            </div>
            <a href="{{ route('player.bets') }}" class="text-xs text-emerald-400 hover:underline font-semibold">{{ __('player.view_all_wagers') }}</a>
        </div>

        <div class="overflow-x-auto mt-4">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-xs text-slate-400 uppercase font-semibold">
                        <th class="py-3 px-4">{{ __('player.col_ticket_ref') }}</th>
                        <th class="py-3 px-4">{{ __('player.col_draw') }}</th>
                        <th class="py-3 px-4">{{ __('player.col_items_numbers') }}</th>
                        <th class="py-3 px-4">{{ __('player.col_stake') }}</th>
                        <th class="py-3 px-4">{{ __('player.col_potential_payout') }}</th>
                        <th class="py-3 px-4">{{ __('player.col_status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($recentBets as $bet)
                        @php
                            $betCurrency = $bet->currency instanceof \App\Enums\Currency ? $bet->currency : null;
                            $stakeLabel = $betCurrency instanceof \App\Enums\Currency
                                ? \App\Services\Finance\Money::of((string) $bet->stake_amount, $betCurrency)->format()
                                : __('player.not_configured');
                            $potentialPayoutLabel = $betCurrency instanceof \App\Enums\Currency
                                ? \App\Services\Finance\Money::of((string) $bet->potential_payout, $betCurrency)->format()
                                : __('player.not_configured');
                        @endphp
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="py-3.5 px-4 font-mono font-bold text-slate-200">
                                {{ $bet->bet_number }}
                            </td>
                            <td class="py-3.5 px-4 font-mono text-slate-400">
                                #{{ $bet->draw?->draw_number ?? __('player.not_recorded') }}
                            </td>
                            <td class="py-3.5 px-4">
                                <div class="flex flex-wrap gap-1">
                                    @foreach($bet->items as $item)
                                        <span class="px-2 py-0.5 bg-slate-950 border border-slate-800 rounded text-xs font-mono font-bold text-amber-400">
                                            {{ $item->market->value ?? $item->market }}: {{ $item->number }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="py-3.5 px-4 font-mono font-bold text-white">
                                {{ $stakeLabel }}
                            </td>
                            <td class="py-3.5 px-4 font-mono font-bold text-emerald-400">
                                {{ $potentialPayoutLabel }}
                            </td>
                            <td class="py-3.5 px-4">
                                @php
                                    $statusClass = match($bet->status->value ?? $bet->status) {
                                        'won' => 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30',
                                        'lost' => 'bg-slate-800 text-slate-400',
                                        'cancelled' => 'bg-rose-500/10 text-rose-400 border border-rose-500/30',
                                        default => 'bg-amber-500/10 text-amber-400 border border-amber-500/30',
                                    };
                                @endphp
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase {{ $statusClass }}">
                                    {{ $bet->status->value ?? $bet->status }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-slate-500 text-xs">
                                {{ __('player.no_recent_wagers') }}
                                <a href="{{ route('player.bet') }}" class="text-emerald-400 hover:underline ml-1 font-semibold">{{ __('player.place_first_bet') }}</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
