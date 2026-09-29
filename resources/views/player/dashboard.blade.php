@extends('layouts.app')

@section('title', 'Player Dashboard — Thai Lottery')

@section('content')
<div class="space-y-8">
    <!-- Welcome Banner & Quick Wallet Summary -->
    <div class="bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-2xl relative overflow-hidden">
        <div class="absolute -right-12 -top-12 w-64 h-64 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div>
                <div class="flex items-center space-x-3 mb-2">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                        Player Account
                    </span>
                    <span class="text-xs text-slate-400">ID: #{{ $user->id ?? 1001 }}</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">
                    Welcome back, <span class="text-emerald-400">{{ $user->name ?? 'Player' }}</span>!
                </h1>
                <p class="text-slate-400 text-sm mt-1">{{ __('player.dash_hero_subtitle') }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <div class="bg-slate-950/80 border border-slate-800 rounded-2xl px-5 py-3">
                    <span class="text-xs text-slate-400 block font-medium">{{ __('player.available_balance') }}</span>
                    <span class="text-xl sm:text-2xl font-bold font-mono text-amber-400" id="dashboard-balance">
                        {{ \App\Services\Finance\Money::of((string) ($wallet->balance ?? '0'), \App\Enums\Currency::THB)->format() }}
                    </span>
                </div>
                <div class="flex sm:flex-col gap-2">
                    <a href="{{ route('player.bet') }}" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl shadow-lg transition duration-150 text-center text-sm">
                        Play Lottery
                    </a>
                    <a href="{{ route('player.deposit') }}" class="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold rounded-xl border border-slate-700 transition duration-150 text-center text-sm">
                        Deposit
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Draw Countdown & Upcoming Draws -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Active / Next Draw Card -->
        <div class="lg:col-span-2 bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl relative">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center space-x-2">
                    <span class="w-3 h-3 bg-emerald-500 rounded-full animate-ping"></span>
                    <h2 class="text-lg font-bold text-white tracking-wide">{{ __('player.next_official_draw') }}</h2>
                </div>
                <span class="text-xs font-mono font-bold px-3 py-1 bg-emerald-950 text-emerald-400 border border-emerald-800 rounded-full">
                    {{ $openDraw?->draw_number ?? 'Not scheduled' }}
                </span>
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
                        Draw Date: <span class="text-slate-300 font-mono">{{ $openDraw?->scheduled_at?->format('M d, Y H:i') ?? '—' }}</span>
                    </p>
                </div>

                <div class="space-y-2">
                    <div class="flex justify-between text-xs py-2 border-b border-slate-800">
                        <span class="text-slate-400">{{ __('player.payout_rate_3d_top') }}</span>
                        <span class="font-bold font-mono text-amber-400">฿900.00 / ฿1</span>
                    </div>
                    <div class="flex justify-between text-xs py-2 border-b border-slate-800">
                        <span class="text-slate-400">{{ __('player.payout_rate_2d') }}</span>
                        <span class="font-bold font-mono text-amber-400">฿90.00 / ฿1</span>
                    </div>
                    <div class="flex justify-between text-xs py-2">
                        <span class="text-slate-400">{{ __('player.payout_rate_3d_tod') }}</span>
                        <span class="font-bold font-mono text-amber-400">฿120.00 / ฿1</span>
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

        <!-- Latest Draw Results Overview -->
        <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-xl flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-base font-bold text-white">{{ __('player.latest_results') }}</h2>
                    <a href="{{ route('player.draws') }}" class="text-xs text-emerald-400 hover:underline">{{ __('player.view_all') }}</a>
                </div>
                
                <div class="bg-slate-950 border border-slate-800/80 rounded-2xl p-4 text-center">
                    <span class="text-xs text-slate-400 uppercase tracking-wider block font-semibold mb-1">{{ __('player.first_prize_6') }}</span>
                    <div class="text-3xl font-black font-mono text-amber-400 tracking-widest my-2">
                        {{ $latestCompletedDraw?->result?->first_prize ?? 'TBA' }}
                    </div>
                    <span class="text-[11px] text-slate-500">Draw #{{ $latestCompletedDraw?->draw_number ?? '—' }}</span>
                </div>

                <div class="grid grid-cols-2 gap-3 mt-4">
                    <div class="bg-slate-950/60 border border-slate-800 rounded-xl p-3 text-center">
                        <span class="text-[10px] text-slate-400 block font-semibold">{{ __('player.digit_3d_top') }}</span>
                        <span class="text-lg font-bold font-mono text-emerald-400">{{ $latestCompletedDraw?->result?->three_digits_top ?? '—' }}</span>
                    </div>
                    <div class="bg-slate-950/60 border border-slate-800 rounded-xl p-3 text-center">
                        <span class="text-[10px] text-slate-400 block font-semibold">{{ __('player.digit_2d_bottom') }}</span>
                        <span class="text-lg font-bold font-mono text-emerald-400">{{ $latestCompletedDraw?->result?->two_digits_bottom ?? '—' }}</span>
                    </div>
                </div>
            </div>

            <div class="mt-6">
                <a href="{{ route('player.draws') }}" class="block w-full py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold text-xs rounded-xl text-center transition">
                    Explore Past Results Archive
                </a>
            </div>
        </div>
    </div>

    <!-- Recent Wagers Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h2 class="text-lg font-bold text-white">{{ __('player.my_recent_wagers') }}</h2>
                <p class="text-xs text-slate-400">{{ __('player.recent_wagers_lead') }}</p>
            </div>
            <a href="{{ route('player.bets') }}" class="text-xs text-emerald-400 font-bold hover:underline">{{ __('player.view_all_wagers') }}</a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-xs text-slate-400 uppercase font-semibold">
                        <th class="py-3 px-4">{{ __('player.col_ticket_ref') }}</th>
                        <th class="py-3 px-4">{{ __('player.col_draw') }}</th>
                        <th class="py-3 px-4">{{ __('player.col_items_numbers') }}</th>
                        <th class="py-3 px-4 text-right">{{ __('player.col_stake') }}</th>
                        <th class="py-3 px-4 text-right">{{ __('player.col_potential_payout') }}</th>
                        <th class="py-3 px-4 text-center">{{ __('player.col_status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($recentBets ?? [] as $bet)
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="py-3.5 px-4 font-mono font-bold text-slate-200">
                                {{ $bet->ticket->ticket_number ?? 'TKT-'.$bet->id }}
                            </td>
                            <td class="py-3.5 px-4 font-mono text-xs text-slate-400">
                                {{ $bet->draw->draw_number ?? 'DRAW-'.$bet->draw_id }}
                            </td>
                            <td class="py-3.5 px-4">
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($bet->items ?? [] as $item)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-bold bg-slate-800 text-amber-400 border border-slate-700">
                                            {{ $item->number }} <span class="text-[10px] text-slate-400 ml-1">({{ $item->market->value ?? $item->market }})</span>
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono font-bold text-slate-200">
                                {{ \App\Services\Finance\Money::of((string) ($bet->total_stake ?? '0'), \App\Enums\Currency::THB)->format() }}
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono font-bold text-emerald-400">
                                {{ \App\Services\Finance\Money::of((string) ($bet->potential_payout ?? '0'), \App\Enums\Currency::THB)->format() }}
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                @php
                                    $statusVal = $bet->status->value ?? (string) $bet->status;
                                @endphp
                                @if($statusVal === 'won')
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">{{ __('player.status_won') }}</span>
                                @elseif($statusVal === 'lost')
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-rose-500/10 text-rose-400 border border-rose-500/30">{{ __('player.status_lost') }}</span>
                                @elseif($statusVal === 'cancelled')
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-slate-500/10 text-slate-400 border border-slate-500/30">{{ __('player.status_cancelled') }}</span>
                                @else
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-amber-500/10 text-amber-400 border border-amber-500/30">{{ __('player.status_pending') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-slate-500 text-xs">
                                {{ __('player.no_recent_wagers') }} <a href="{{ route('player.bet') }}" class="text-emerald-400 hover:underline">{{ __('player.place_first_bet') }}</a>
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
    <script type="module" src="{{ Vite::asset('resources/js/lottery/countdown.js') }}"></script>
@endpush
