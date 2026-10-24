@extends('layouts.app')

@section('title', __('player.place_wager_title'))
@section('meta_description', __('player.place_wager_lead'))

@section('content')
@php
    $currency = \App\Enums\Currency::from((string) config('payment.currency.default'));
    $walletBalance = $wallet instanceof \App\Models\Wallet && $wallet->currency instanceof \App\Enums\Currency && $wallet->currency === $currency
        ? \App\Services\Finance\Money::of((string) $wallet->balance, $currency)->format()
        : null;
@endphp

<div class="space-y-8">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-black uppercase tracking-[0.18em] text-amber-400">{{ __('player.active_draw_label') }}</p>
            <h1 class="text-3xl font-black tracking-tight text-white">{{ __('player.place_wager_title') }}</h1>
            <p class="mt-1 text-sm text-slate-400">{{ __('player.place_wager_lead') }}</p>
        </div>
        <div class="rounded-2xl border border-slate-700 bg-slate-900/80 px-4 py-3 text-right">
            <span class="block text-[11px] uppercase tracking-wider text-slate-500">{{ __('player.available_balance') }}</span>
            <strong class="font-mono text-amber-400">{{ $walletBalance ?? __('player.not_configured') }}</strong>
        </div>
    </div>

    @if (! $openDraw)
        <section class="rounded-3xl border border-amber-500/30 bg-amber-950/20 p-6" role="status">
            <h2 class="text-lg font-bold text-amber-200">{{ __('player.no_open_draw') }}</h2>
            <p class="mt-2 text-sm leading-6 text-amber-100/70">{{ __('player.no_open_draw_lead') }}</p>
            <a class="mt-4 inline-flex rounded-xl border border-amber-500/40 px-4 py-2 text-sm font-bold text-amber-300 hover:bg-amber-500/10" href="{{ route('player.draws') }}">{{ __('player.view_all') }}</a>
        </section>
    @else
        <form method="POST" action="{{ route('player.bets.purchase') }}" class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]" data-bet-form data-draw-reference="{{ $openDraw->draw_number }}">
            @csrf
            <input type="hidden" name="draw_reference" value="{{ $openDraw->draw_number }}">
            <input type="hidden" name="client_key" value="" data-client-key>

            <section class="rounded-3xl border border-slate-800 bg-slate-900/90 p-6 shadow-xl sm:p-8" aria-labelledby="bet-builder-heading" data-ticket-selector>
                <div class="flex flex-col gap-3 border-b border-slate-800 pb-5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-black uppercase tracking-wider text-emerald-400">{{ __('player.active_draw_label') }} {{ $openDraw->draw_number }}</p>
                        <h2 id="bet-builder-heading" class="mt-1 text-xl font-black text-white">{{ __('player.select_market') }}</h2>
                    </div>
                    <span class="rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-bold uppercase text-emerald-300">{{ __('player.status_open') }}</span>
                </div>

                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="market" class="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('player.select_market') }}</label>
                        <select id="market-select" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100 focus:border-amber-400 focus:outline-none" data-market>
                            @forelse ($marketOptions as $key => $market)
                                <option value="{{ $key }}" data-digits="{{ $market['digits'] }}" data-multiplier="{{ $market['multiplier'] }}" data-label="{{ $market['label'] }}">{{ $market['label'] }} · {{ $market['digits'] }} {{ __('player.digits_suffix') }}</option>
                            @empty
                                <option value="">{{ __('player.not_configured') }}</option>
                            @endforelse
                        </select>
                    </div>
                    <div>
                        <label for="number" class="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('player.enter_digits') }}</label>
                        <input id="selected-number-input" type="text" inputmode="numeric" autocomplete="off" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 font-mono text-lg tracking-[0.2em] text-white focus:border-amber-400 focus:outline-none" data-number maxlength="6" pattern="[0-9]+">
                    </div>
                    <div>
                        <label for="stake" class="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('player.stake_thb') }}</label>
                        <input id="stake-amount-input" type="text" inputmode="decimal" autocomplete="off" value="" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 font-mono text-lg text-white focus:border-amber-400 focus:outline-none" data-stake>
                    </div>
                    <div class="flex items-end">
                        <button type="button" class="w-full rounded-xl bg-gradient-to-r from-amber-400 to-amber-600 px-4 py-3 text-sm font-black text-slate-950 shadow-lg hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-50" id="btn-add-to-slip" @disabled(empty($marketOptions))>
                            {{ __('player.add_to_slip') }}
                        </button>
                    </div>
                    <div id="number-keypad" class="sm:col-span-2 grid grid-cols-6 gap-2" aria-label="Number keypad">
                        @foreach (range(0, 9) as $digit)
                            <button type="button" class="rounded-lg border border-slate-700 px-3 py-2 text-sm font-bold text-slate-200" data-key="{{ $digit }}">{{ $digit }}</button>
                        @endforeach
                        <button type="button" class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-bold text-slate-300" data-key="backspace">⌫</button>
                        <button type="button" class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-bold text-slate-300" data-key="clear">CLR</button>
                    </div>
                </div>

                <p class="mt-4 text-xs leading-5 text-slate-500">{{ __('player.purchase_server_authoritative_note') }}</p>
                <div class="mt-5 rounded-xl border border-rose-500/30 bg-rose-950/30 p-3 text-sm text-rose-200" role="status" aria-live="polite" data-selection-status></div>
            </section>

            <aside id="bet-slip-container" class="rounded-3xl border border-amber-500/25 bg-slate-900/95 p-6 shadow-2xl" aria-labelledby="slip-heading" data-max-items="{{ (int) config('lottery.bulk.max_items', 50) }}" data-draw-id="{{ $openDraw->id }}">
                <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                    <h2 id="slip-heading" class="text-lg font-black text-white">{{ __('player.bet_slip_basket') }}</h2>
                    <span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-xs font-bold text-amber-300" data-slip-count>0</span>
                </div>
                <div id="bet-items-list" class="mt-4 max-h-80 space-y-3 overflow-y-auto">
                    <p class="rounded-xl border border-dashed border-slate-700 p-4 text-center text-xs text-slate-500" data-empty-slip>{{ __('player.empty_bet_slip') }}</p>
                </div>
                <div class="mt-5 space-y-2 border-t border-slate-800 pt-4 text-sm">
                    <div class="flex justify-between gap-3 text-slate-400"><span>{{ __('player.total_stake_label') }}</span><strong id="total-stake-amount" class="font-mono text-white">{{ __('player.not_recorded') }}</strong></div>
                </div>
                <div class="flex justify-between gap-3 text-sm text-slate-400"><span>Maximum payout preview</span><strong id="total-payout-amount" class="font-mono text-white">0.00</strong></div>
                <div class="mt-3 text-sm text-slate-300" role="status" aria-live="polite" data-slip-status></div>
                <button id="btn-place-bet" type="button" class="mt-5 w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-black text-white shadow-lg hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50" data-submit-slip disabled>
                    {{ __('player.confirm_place_bets') }}
                </button>
                <p class="mt-3 text-center text-[11px] leading-5 text-slate-500">{{ __('player.purchase_fail_closed_note') }}</p>
            </aside>
        </form>
    @endif
</div>
@endsection

@push('scripts')
    @vite(['resources/js/lottery/ticket-selector.js', 'resources/js/lottery/bet-slip.js'])
@endpush
