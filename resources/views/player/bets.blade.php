@extends('layouts.app')

@section('title', __('player.bets_title'))
@section('meta_description', __('player.bets_lead'))
@section('meta_robots', 'noindex,nofollow')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">{{ __('player.bets_title') }}</h1>
        <p class="text-slate-400 text-sm mt-1">{{ __('player.bets_lead') }}</p>
    </div>

    <!-- Bets Table Card -->
    <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-xs text-slate-400 uppercase font-semibold">
                        <th class="py-3 px-4">{{ __('player.col_ticket_number') }}</th>
                        <th class="py-3 px-4">{{ __('player.col_draw') }}</th>
                        <th class="py-3 px-4">{{ __('player.col_markets_numbers') }}</th>
                        <th class="py-3 px-4 text-right">{{ __('player.col_total_stake') }}</th>
                        <th class="py-3 px-4 text-right">{{ __('player.col_potential_payout') }}</th>
                        <th class="py-3 px-4 text-center">{{ __('player.col_status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($bets as $bet)
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
                                {{ $bet->ticket?->ticket_number ?? __('player.not_recorded') }}
                            </td>
                            <td class="py-3.5 px-4 font-mono text-xs text-slate-400">
                                {{ $bet->draw?->draw_number ?? __('player.not_recorded') }}
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
                                {{ $stakeLabel }}
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono font-bold text-emerald-400">
                                {{ $potentialPayoutLabel }}
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
                            <td colspan="6" class="py-12 text-center text-slate-500 text-xs">
                                {{ __('player.no_bets_recorded') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(method_exists($bets, 'links'))
            <div class="pt-6">
                {{ $bets->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
