@extends('layouts.app')

@section('title', __('player.place_wager_title'))
@section('meta_description', __('player.place_wager_lead'))
@section('meta_robots', 'noindex, nofollow')

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
            <p class="tl-v2-eyebrow">{{ __('player.active_draw_label') }}</p>
            <h1 class="mt-1 text-3xl font-black tracking-tight text-[#FFF6D6]">{{ __('player.place_wager_title') }}</h1>
            <p class="mt-1 text-sm text-[#D4CAB3]">{{ __('player.place_wager_lead') }}</p>
        </div>
        <div class="tl-v2-subcard px-4 py-3 sm:text-right">
            <span class="block text-[11px] font-bold uppercase tracking-wider text-[#9D937D]">{{ __('player.available_balance') }}</span>
            <strong class="font-mono text-lg font-black text-[#F5E6B8]" data-tabular-nums>{{ $walletBalance ?? __('player.not_configured') }}</strong>
        </div>
    </div>

    @if (! $openDraw)
        <section class="tl-v2-card border-[#D4AF37]/40" role="status">
            <h2 class="text-lg font-bold text-[#FFF6D6]">{{ __('player.no_open_draw') }}</h2>
            <p class="mt-2 text-sm leading-6 text-[#D4CAB3]">{{ __('player.no_open_draw_lead') }}</p>
            <a class="mt-4 tl-v2-btn-outline text-xs" href="{{ route('player.draws') }}">{{ __('player.view_all') }}</a>
        </section>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]" aria-disabled="true">
            <section class="tl-v2-card" aria-labelledby="bet-builder-heading" data-ticket-selector>
                <div class="flex flex-col gap-3 border-b border-[#D4AF37]/15 pb-5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="tl-v2-eyebrow">{{ __('player.active_draw_label') }}</p>
                        <h2 id="bet-builder-heading" class="mt-1 text-xl font-black text-[#FFF6D6]">{{ __('player.select_market') }}</h2>
                    </div>
                    <span class="tl-v2-pill tl-v2-pill--neutral">{{ __('player.no_open_draw') }}</span>
                </div>

                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="market-select" class="mb-2 block text-xs font-bold uppercase tracking-wider text-[#F5E6B8]">{{ __('player.select_market') }}</label>
                        <select id="market-select" class="tl-v2-input" data-market disabled>
                            @foreach ($marketOptions as $key => $market)
                                <option value="{{ $key }}" data-digits="{{ $market['digits'] }}" data-multiplier="{{ $market['multiplier'] }}" data-label="{{ $market['label'] }}">{{ $market['label'] }} · {{ $market['digits'] }} {{ __('player.digits_suffix') }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="selected-number-input" class="mb-2 block text-xs font-bold uppercase tracking-wider text-[#F5E6B8]">{{ __('player.enter_digits') }}</label>
                        <input id="selected-number-input" type="text" inputmode="numeric" autocomplete="off" class="tl-v2-input font-mono text-lg tracking-[0.22em]" data-number maxlength="6" pattern="[0-9]+" disabled>
                    </div>
                    <div>
                        <label for="stake-amount-input" class="mb-2 block text-xs font-bold uppercase tracking-wider text-[#F5E6B8]">{{ __('player.stake_thb') }}</label>
                        <input id="stake-amount-input" type="text" inputmode="decimal" autocomplete="off" value="" class="tl-v2-input font-mono text-lg" data-stake disabled>
                    </div>
                    <div class="flex items-end">
                        <button type="button" class="tl-v2-btn-gold w-full disabled:cursor-not-allowed disabled:opacity-50" id="btn-add-to-slip" disabled>
                            {{ __('player.add_to_slip') }}
                        </button>
                    </div>
                </div>

                <p class="mt-4 text-xs leading-5 text-[#9D937D]">{{ __('player.purchase_server_authoritative_note') }}</p>
            </section>

            <aside id="bet-slip-container" class="tl-v2-card border-[#D4AF37]/35" aria-labelledby="slip-heading" data-max-items="{{ (int) config('lottery.bulk.max_items', 50) }}">
                <div class="flex items-center justify-between border-b border-[#D4AF37]/15 pb-4">
                    <h2 id="slip-heading" class="text-lg font-black text-[#FFF6D6]">{{ __('player.bet_slip_basket') }}</h2>
                    <span class="tl-v2-pill tl-v2-pill--configured font-mono" data-slip-count>0</span>
                </div>
                <div id="bet-items-list" class="mt-4 max-h-80 space-y-3 overflow-y-auto">
                    <p class="rounded-xl border border-dashed border-[#D4AF37]/25 p-4 text-center text-xs text-[#9D937D]" data-empty-slip>{{ __('player.empty_bet_slip') }}</p>
                </div>
                <div class="mt-5 space-y-2 border-t border-[#D4AF37]/15 pt-4 text-sm">
                    <div class="flex justify-between gap-3 text-[#D4CAB3]"><span>{{ __('player.total_stake_label') }}</span><strong id="total-stake-amount" class="font-mono text-[#FFF6D6]" data-tabular-nums>{{ __('player.not_recorded') }}</strong></div>
                </div>
                <div class="mt-1 flex justify-between gap-3 text-sm text-[#D4CAB3]"><span>{{ __('player.max_potential_payout_label') }}</span><strong id="total-payout-amount" class="font-mono text-[#86EFAC]" data-tabular-nums>0.00</strong></div>
                <button id="btn-place-bet" type="button" class="mt-5 tl-v2-btn-gold w-full disabled:cursor-not-allowed disabled:opacity-50" data-submit-slip disabled>
                    {{ __('player.confirm_place_bets') }}
                </button>
                <p class="mt-3 text-center text-[11px] leading-5 text-[#9D937D]">{{ __('player.purchase_fail_closed_note') }}</p>
            </aside>
        </div>
    @else
        <form method="POST" action="{{ route('player.bets.purchase') }}" class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]" data-bet-form data-draw-reference="{{ $openDraw->draw_number }}" data-slip-scope="{{ auth()->id() }}:{{ $openDraw->id }}">
            @csrf
            <input type="hidden" name="draw_reference" value="{{ $openDraw->draw_number }}">
            <input type="hidden" name="client_key" value="" data-client-key>

            <section class="tl-v2-card" aria-labelledby="bet-builder-heading" data-ticket-selector>
                <div class="flex flex-col gap-3 border-b border-[#D4AF37]/15 pb-5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="tl-v2-eyebrow">{{ __('player.active_draw_label') }} {{ $openDraw->draw_number }}</p>
                        <h2 id="bet-builder-heading" class="mt-1 text-xl font-black text-[#FFF6D6]">{{ __('player.select_market') }}</h2>
                    </div>
                    <span class="tl-v2-pill tl-v2-pill--verified">{{ __('player.status_open') }}</span>
                </div>

                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="market-select" class="mb-2 block text-xs font-bold uppercase tracking-wider text-[#F5E6B8]">{{ __('player.select_market') }}</label>
                        <select id="market-select" class="tl-v2-input" data-market>
                            @forelse ($marketOptions as $key => $market)
                                <option value="{{ $key }}" data-digits="{{ $market['digits'] }}" data-multiplier="{{ $market['multiplier'] }}" data-label="{{ $market['label'] }}">{{ $market['label'] }} · {{ $market['digits'] }} {{ __('player.digits_suffix') }}</option>
                            @empty
                                <option value="">{{ __('player.not_configured') }}</option>
                            @endforelse
                        </select>
                    </div>
                    <div>
                        <label for="selected-number-input" class="mb-2 block text-xs font-bold uppercase tracking-wider text-[#F5E6B8]">{{ __('player.enter_digits') }}</label>
                        <input id="selected-number-input" type="text" inputmode="numeric" autocomplete="off" class="tl-v2-input font-mono text-lg tracking-[0.22em]" data-number maxlength="6" pattern="[0-9]+">
                    </div>
                    <div>
                        <label for="stake-amount-input" class="mb-2 block text-xs font-bold uppercase tracking-wider text-[#F5E6B8]">{{ __('player.stake_thb') }}</label>
                        <input id="stake-amount-input" type="text" inputmode="decimal" autocomplete="off" value="" class="tl-v2-input font-mono text-lg" data-stake>
                    </div>
                    <div class="flex items-end">
                        <button type="button" class="tl-v2-btn-gold w-full disabled:cursor-not-allowed disabled:opacity-50" id="btn-add-to-slip" @disabled(empty($marketOptions))>
                            {{ __('player.add_to_slip') }}
                        </button>
                    </div>
                    <div id="number-keypad" class="sm:col-span-2 grid grid-cols-4 sm:grid-cols-6 gap-2" role="group" aria-label="Number keypad">
                        @foreach (range(0, 9) as $digit)
                            <button type="button" class="min-h-[44px] rounded-xl border border-[#D4AF37]/25 bg-[#0B0904] px-3 py-2 font-mono text-sm font-black text-[#FFF6D6] hover:border-[#D4AF37] hover:bg-[#1C170B] transition" data-key="{{ $digit }}" aria-label="Digit {{ $digit }}">{{ $digit }}</button>
                        @endforeach
                        <button type="button" class="min-h-[44px] rounded-xl border border-[#D4AF37]/25 bg-[#0B0904] px-3 py-2 text-xs font-bold text-[#F5E6B8] hover:border-[#D4AF37] hover:bg-[#1C170B] transition" data-key="backspace" aria-label="Delete last digit">⌫</button>
                        <button type="button" class="min-h-[44px] rounded-xl border border-[#D4AF37]/25 bg-[#0B0904] px-3 py-2 text-xs font-bold text-[#F5E6B8] hover:border-[#D4AF37] hover:bg-[#1C170B] transition" data-key="clear" aria-label="Clear number">CLR</button>
                    </div>
                </div>

                <p class="mt-4 text-xs leading-5 text-[#9D937D]">{{ __('player.purchase_server_authoritative_note') }}</p>
                <div class="mt-5 empty:hidden rounded-xl border border-[#D4AF37]/35 bg-[#0B0904]/85 p-3 text-sm text-[#FFF6D6]" role="status" aria-live="polite" data-selection-status></div>
            </section>

            <aside id="bet-slip-container" class="tl-v2-card border-[#D4AF37]/35" aria-labelledby="slip-heading" data-max-items="{{ (int) config('lottery.bulk.max_items', 50) }}" data-draw-id="{{ $openDraw->id }}">
                <div class="flex items-center justify-between border-b border-[#D4AF37]/15 pb-4">
                    <h2 id="slip-heading" class="text-lg font-black text-[#FFF6D6]">{{ __('player.bet_slip_basket') }}</h2>
                    <span class="tl-v2-pill tl-v2-pill--configured font-mono" data-slip-count>0</span>
                </div>
                <div id="bet-items-list" class="mt-4 max-h-80 space-y-3 overflow-y-auto">
                    <p class="rounded-xl border border-dashed border-[#D4AF37]/25 p-4 text-center text-xs text-[#9D937D]" data-empty-slip>{{ __('player.empty_bet_slip') }}</p>
                </div>
                <div class="mt-5 space-y-2 border-t border-[#D4AF37]/15 pt-4 text-sm">
                    <div class="flex justify-between gap-3 text-[#D4CAB3]"><span>{{ __('player.total_stake_label') }}</span><strong id="total-stake-amount" class="font-mono text-[#FFF6D6]" data-tabular-nums>{{ __('player.not_recorded') }}</strong></div>
                </div>
                <div class="mt-1 flex justify-between gap-3 text-sm text-[#D4CAB3]"><span>{{ __('player.max_potential_payout_label') }}</span><strong id="total-payout-amount" class="font-mono text-[#86EFAC]" data-tabular-nums>0.00</strong></div>
                <div class="mt-3 text-sm text-[#FAF6EC]" role="status" aria-live="polite" data-slip-status></div>
                <button id="btn-place-bet" type="button" class="mt-5 tl-v2-btn-gold w-full disabled:cursor-not-allowed disabled:opacity-50" data-submit-slip disabled>
                    {{ __('player.confirm_place_bets') }}
                </button>
                <p class="mt-3 text-center text-[11px] leading-5 text-[#9D937D]">{{ __('player.purchase_fail_closed_note') }}</p>
            </aside>
        </form>
    @endif
</div>
@endsection

@push('scripts')
    @vite(['resources/js/lottery/ticket-selector.js', 'resources/js/lottery/bet-slip.js'])
@endpush
