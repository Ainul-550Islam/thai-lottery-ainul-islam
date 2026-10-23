@extends('layouts.app')

@section('title', __('player.bets_title'))
@section('meta_description', __('player.bets_lead'))
@section('meta_robots', 'noindex,nofollow')

@section('content')
<div class="space-y-6">
    <div>
        <span class="tl-v2-eyebrow">{{ __('player.nav_my_bets') }}</span>
        <h1 class="mt-1 text-2xl sm:text-3xl font-black text-[#FFF6D6] tracking-tight">{{ __('player.bets_title') }}</h1>
        <p class="text-[#D4CAB3] text-sm mt-1">{{ __('player.bets_lead') }}</p>
    </div>

    <div class="tl-v2-card">
        <div class="tl-v2-table-wrap">
            <table class="tl-v2-table">
                <caption class="sr-only">{{ __('player.bets_title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('player.col_ticket_number') }}</th>
                        <th scope="col">{{ __('player.col_draw') }}</th>
                        <th scope="col">{{ __('player.col_markets_numbers') }}</th>
                        <th scope="col" class="text-right">{{ __('player.col_total_stake') }}</th>
                        <th scope="col" class="text-right">{{ __('player.col_potential_payout') }}</th>
                        <th scope="col" class="text-center">{{ __('player.col_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($bets as $bet)
                        @php
                            $betCurrency = $bet->currency instanceof \App\Enums\Currency ? $bet->currency : null;
                            $stakeLabel = $betCurrency instanceof \App\Enums\Currency
                                ? \App\Services\Finance\Money::of((string) $bet->stake_amount, $betCurrency)->format()
                                : __('player.not_configured');
                            $potentialPayoutLabel = $betCurrency instanceof \App\Enums\Currency
                                ? \App\Services\Finance\Money::of((string) $bet->potential_payout, $betCurrency)->format()
                                : __('player.not_configured');
                            $statusVal = $bet->status->value ?? (string) $bet->status;
                        @endphp
                        <tr>
                            <td class="font-mono font-bold text-[#FFF6D6]">
                                {{ $bet->ticket?->ticket_number ?? __('player.not_recorded') }}
                            </td>
                            <td class="font-mono text-xs text-[#D4CAB3]">
                                {{ $bet->draw?->draw_number ?? __('player.not_recorded') }}
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($bet->items ?? [] as $item)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-mono font-bold bg-[#0B0904] text-[#F5E6B8] border border-[#D4AF37]/25">
                                            {{ $item->number }} <span class="text-[10px] text-[#9D937D] ml-1">({{ $item->market->value ?? $item->market }})</span>
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="text-right font-mono font-bold text-[#FFF6D6]">{{ $stakeLabel }}</td>
                            <td class="text-right font-mono font-bold text-[#86EFAC]">{{ $potentialPayoutLabel }}</td>
                            <td class="text-center">
                                @if($statusVal === 'won')
                                    <span class="tl-v2-pill tl-v2-pill--verified">{{ __('player.status_won') }}</span>
                                @elseif($statusVal === 'lost')
                                    <span class="tl-v2-pill tl-v2-pill--danger">{{ __('player.status_lost') }}</span>
                                @elseif($statusVal === 'cancelled')
                                    <span class="tl-v2-pill tl-v2-pill--neutral">{{ __('player.status_cancelled') }}</span>
                                @else
                                    <span class="tl-v2-pill tl-v2-pill--configured">{{ __('player.status_pending') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-[#D4CAB3] text-sm">
                                {{ __('player.no_bets_recorded') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(is_object($bets) && method_exists($bets, 'links'))
            <div class="pt-6">
                {{ $bets->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
