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

<div class="space-y-8" data-bet-slip>
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

            <section class="rounded-3xl border border-slate-800 bg-slate-900/90 p-6 shadow-xl sm:p-8" aria-labelledby="bet-builder-heading">
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
                        <select id="market" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100 focus:border-amber-400 focus:outline-none" data-market>
                            @forelse ($marketOptions as $key => $market)
                                <option value="{{ $key }}" data-digits="{{ $market['digits'] }}">{{ $market['label'] }} · {{ $market['digits'] }} {{ __('player.digits_suffix') }}</option>
                            @empty
                                <option value="">{{ __('player.not_configured') }}</option>
                            @endforelse
                        </select>
                    </div>
                    <div>
                        <label for="number" class="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('player.enter_digits') }}</label>
                        <input id="number" type="text" inputmode="numeric" autocomplete="off" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 font-mono text-lg tracking-[0.2em] text-white focus:border-amber-400 focus:outline-none" data-number maxlength="6" pattern="[0-9]+">
                    </div>
                    <div>
                        <label for="stake" class="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('player.stake_thb') }}</label>
                        <input id="stake" type="text" inputmode="decimal" autocomplete="off" value="" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 font-mono text-lg text-white focus:border-amber-400 focus:outline-none" data-stake>
                    </div>
                    <div class="flex items-end">
                        <button type="button" class="w-full rounded-xl bg-gradient-to-r from-amber-400 to-amber-600 px-4 py-3 text-sm font-black text-slate-950 shadow-lg hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-50" data-add-item @disabled(empty($marketOptions))>
                            {{ __('player.add_to_slip') }}
                        </button>
                    </div>
                </div>

                <p class="mt-4 text-xs leading-5 text-slate-500">{{ __('player.purchase_server_authoritative_note') }}</p>
                <div class="mt-5 hidden rounded-xl border border-rose-500/30 bg-rose-950/30 p-3 text-sm text-rose-200" role="alert" data-bet-error></div>
            </section>

            <aside class="rounded-3xl border border-amber-500/25 bg-slate-900/95 p-6 shadow-2xl" aria-labelledby="slip-heading">
                <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                    <h2 id="slip-heading" class="text-lg font-black text-white">{{ __('player.bet_slip_basket') }}</h2>
                    <span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-xs font-bold text-amber-300" data-slip-count>0</span>
                </div>
                <div class="mt-4 max-h-80 space-y-3 overflow-y-auto" data-slip-items>
                    <p class="rounded-xl border border-dashed border-slate-700 p-4 text-center text-xs text-slate-500" data-empty-slip>{{ __('player.empty_bet_slip') }}</p>
                </div>
                <div class="mt-5 space-y-2 border-t border-slate-800 pt-4 text-sm">
                    <div class="flex justify-between gap-3 text-slate-400"><span>{{ __('player.total_stake_label') }}</span><strong class="font-mono text-white" data-total-stake>{{ __('player.not_recorded') }}</strong></div>
                </div>
                <button type="submit" class="mt-5 w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-black text-white shadow-lg hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50" data-submit-slip disabled>
                    {{ __('player.confirm_place_bets') }}
                </button>
                <p class="mt-3 text-center text-[11px] leading-5 text-slate-500">{{ __('player.purchase_fail_closed_note') }}</p>
            </aside>
        </form>
    @endif
</div>
@endsection

@push('scripts')
<script>
(function () {
    const form = document.querySelector('[data-bet-form]');
    if (!form) return;

    const market = form.querySelector('[data-market]');
    const number = form.querySelector('[data-number]');
    const stake = form.querySelector('[data-stake]');
    const add = form.querySelector('[data-add-item]');
    const itemsNode = form.querySelector('[data-slip-items]');
    const countNode = form.querySelector('[data-slip-count]');
    const totalNode = form.querySelector('[data-total-stake]');
    const submit = form.querySelector('[data-submit-slip]');
    const errorNode = form.querySelector('[data-bet-error]');
    const keyNode = form.querySelector('[data-client-key]');
    const items = [];

    function clientKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes).map(function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
    }

    keyNode.value = clientKey();

    function selectedDigits() {
        const selected = market.options[market.selectedIndex];
        return Number(selected ? selected.dataset.digits : 0);
    }

    function decimalToCents(value) {
        const parts = value.split('.');
        const whole = parts[0] || '0';
        const fraction = (parts[1] || '').padEnd(2, '0').slice(0, 2);
        return BigInt(whole) * 100n + BigInt(fraction || '0');
    }

    function centsToDecimal(cents) {
        const whole = cents / 100n;
        const fraction = String(cents % 100n).padStart(2, '0');
        return String(whole) + '.' + fraction;
    }

    function render() {
        itemsNode.innerHTML = '';
        if (items.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'rounded-xl border border-dashed border-slate-700 p-4 text-center text-xs text-slate-500';
            empty.textContent = @json(__('player.empty_bet_slip'));
            itemsNode.appendChild(empty);
        }

        items.forEach(function (item, index) {
            const row = document.createElement('div');
            row.className = 'rounded-xl border border-slate-800 bg-slate-950 p-3 text-xs';
            row.innerHTML = '<div class="flex items-start justify-between gap-3"><div><strong class="font-mono text-amber-300">' + item.number + '</strong><span class="ml-2 text-slate-400">' + item.label + '</span></div><button type="button" class="text-rose-300 hover:text-rose-200" data-remove="' + index + '">' + @json(__('player.remove_item')) + '</button></div><div class="mt-2 font-mono text-slate-300">' + item.stake + ' THB</div>';
            itemsNode.appendChild(row);
        });

        const total = items.reduce(function (sum, item) { return sum + decimalToCents(item.stake); }, 0n);
        totalNode.textContent = items.length === 0 ? @json(__('player.not_recorded')) : centsToDecimal(total) + ' THB';
        countNode.textContent = String(items.length);
        submit.disabled = items.length === 0;
        itemsNode.querySelectorAll('[data-remove]').forEach(function (button) {
            button.addEventListener('click', function () {
                items.splice(Number(button.dataset.remove), 1);
                render();
            });
        });
    }

    add.addEventListener('click', function () {
        const digits = selectedDigits();
        const selected = market.options[market.selectedIndex];
        const rawNumber = number.value.trim();
        const rawStake = stake.value.trim();
        errorNode.classList.add('hidden');
        if (!selected || !digits || !new RegExp('^\d{' + digits + '}$').test(rawNumber)) {
            errorNode.textContent = @json(__('player.invalid_number_selection'));
            errorNode.classList.remove('hidden');
            return;
        }
        let stakeCents = 0n;
        try {
            stakeCents = decimalToCents(rawStake);
        } catch (exception) {
            stakeCents = 0n;
        }
        if (!/^\d+(?:\.\d{1,2})?$/.test(rawStake) || stakeCents <= 0n) {
            errorNode.textContent = @json(__('player.invalid_stake_selection'));
            errorNode.classList.remove('hidden');
            return;
        }
        items.push({ market: market.value, label: selected.textContent, number: rawNumber, stake: rawStake });
        number.value = '';
        stake.value = '';
        render();
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        errorNode.classList.add('hidden');
        if (items.length === 0) return;

        const body = new URLSearchParams();
        body.append('_token', form.querySelector('input[name="_token"]').value);
        body.append('draw_reference', form.querySelector('input[name="draw_reference"]').value);
        body.append('client_key', keyNode.value);
        items.forEach(function (item, index) {
            body.append('items[' + index + '][market]', item.market);
            body.append('items[' + index + '][number]', item.number);
            body.append('items[' + index + '][stake]', item.stake);
        });

        submit.disabled = true;
        try {
            const response = await fetch(form.action, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: body });
            const payload = await response.json();
            if (!response.ok || payload.success !== true) {
                throw new Error(payload.message || @json(__('player.purchase_refused')));
            }
            window.location.href = @json(route('player.bets'));
        } catch (exception) {
            errorNode.textContent = exception.message || @json(__('player.purchase_refused'));
            errorNode.classList.remove('hidden');
            submit.disabled = false;
        }
    });

    render();
}());
</script>
@endpush
