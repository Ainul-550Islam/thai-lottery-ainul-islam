@extends('layouts.app')

@section('title', __('payment_ops.deposit_status_title'))

@section('content')
@php
    $depositStatus = $deposit->status instanceof \BackedEnum ? $deposit->status->value : (string) $deposit->status;
    $depositEnum = $deposit->status instanceof \App\Enums\DepositStatus ? $deposit->status : \App\Enums\DepositStatus::tryFrom($depositStatus);
    $depositCurrency = $deposit->currency instanceof \BackedEnum ? $deposit->currency->value : (string) $deposit->currency;
@endphp
<div class="mx-auto max-w-3xl space-y-6 px-4 py-10 sm:px-6 lg:px-8">
    <header class="rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-xl">
        <p class="text-sm font-semibold uppercase tracking-wide text-emerald-300">{{ __('payment_ops.deposit_status_title') }}</p>
        <h1 class="mt-2 break-all text-2xl font-bold text-white">{{ $deposit->reference_number }}</h1>
        <p class="mt-3 text-slate-300">{{ __('payment_ops.deposit_status_description') }}</p>
    </header>

    <section class="rounded-2xl border border-slate-800 bg-slate-900 p-6">
        <dl class="grid gap-5 sm:grid-cols-2">
            <div><dt class="text-sm text-slate-400">{{ __('payment_ops.status') }}</dt><dd class="mt-1 font-semibold text-white" data-testid="deposit-status">{{ __('payment_ops.statuses.'.$depositStatus) }}</dd></div>
            <div><dt class="text-sm text-slate-400">{{ __('payment_ops.amount') }}</dt><dd class="mt-1 font-semibold text-white">{{ $deposit->amount }} {{ $depositCurrency }}</dd></div>
            <div><dt class="text-sm text-slate-400">{{ __('payment_ops.payment_method') }}</dt><dd class="mt-1 text-slate-200">{{ $deposit->method?->label() ?? (string) $deposit->method }}</dd></div>
            <div><dt class="text-sm text-slate-400">{{ __('payment_ops.created_at') }}</dt><dd class="mt-1 text-slate-200">{{ $deposit->created_at?->format('Y-m-d H:i:s') }}</dd></div>
        </dl>
        @if ($depositEnum?->isSuccessful())
            <p role="status" class="mt-6 rounded-xl border border-emerald-700 bg-emerald-950/40 p-4 text-emerald-100">{{ __('payment_ops.deposit_confirmed_notice') }}</p>
        @elseif ($depositEnum !== null && ! $depositEnum->isFinal())
            <p role="status" class="mt-6 rounded-xl border border-amber-700 bg-amber-950/40 p-4 text-amber-100">{{ __('payment_ops.deposit_pending_notice') }}</p>
        @endif
    </section>

    @if (! empty($manualWallet))
        @php $methodLabel = $deposit->method?->label() ?? (string) $deposit->method; @endphp
        <section class="space-y-5 rounded-2xl border border-slate-800 bg-slate-900 p-6" data-testid="manual-wallet-panel">
            <h2 class="text-lg font-semibold text-white">{{ __('payment_ops.manual_wallet.title') }}</h2>
            <p class="text-slate-300">{{ __('payment_ops.manual_wallet.send_exactly', ['amount' => $deposit->amount, 'currency' => $depositCurrency, 'method' => $methodLabel]) }}</p>
            <dl class="grid gap-5 sm:grid-cols-2">
                <div><dt class="text-sm text-slate-400">{{ __('payment_ops.manual_wallet.send_to') }}</dt><dd class="mt-1 text-xl font-bold tracking-wide text-white" data-testid="merchant-number">{{ $manualWallet['merchant_number'] }}</dd></div>
                @if ($manualWallet['merchant_name'] !== '')
                    <div><dt class="text-sm text-slate-400">{{ __('payment_ops.manual_wallet.account_name') }}</dt><dd class="mt-1 text-slate-200">{{ $manualWallet['merchant_name'] }}</dd></div>
                @endif
            </dl>
            <p class="text-sm text-slate-400">{{ __('payment_ops.manual_wallet.reference_note', ['reference' => $deposit->reference_number]) }}</p>

            @if ($manualWallet['proof'])
                <div role="status" class="rounded-xl border border-amber-700 bg-amber-950/40 p-4 text-amber-100">
                    <p class="font-semibold">{{ __('payment_ops.manual_wallet.submitted_title') }}</p>
                    <p class="mt-1">{{ __('payment_ops.manual_wallet.submitted_notice', ['sender' => $manualWallet['proof']->sender_number, 'trx' => $manualWallet['proof']->trx_id]) }}</p>
                </div>
            @elseif ($manualWallet['can_submit'])
                <form method="POST" action="{{ route('player.deposit.proof', ['deposit' => $deposit->reference_number]) }}" class="space-y-4">
                    @csrf
                    <h3 class="font-semibold text-white">{{ __('payment_ops.manual_wallet.form_title') }}</h3>
                    <div>
                        <label for="sender_number" class="block text-sm text-slate-300">{{ __('payment_ops.manual_wallet.sender_number', ['method' => $methodLabel]) }}</label>
                        <input id="sender_number" name="sender_number" type="tel" inputmode="numeric" maxlength="20" required autocomplete="tel" value="{{ old('sender_number') }}" placeholder="01XXXXXXXXX" class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white">
                        @error('sender_number')<p class="mt-1 text-sm text-rose-300" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="trx_id" class="block text-sm text-slate-300">{{ __('payment_ops.manual_wallet.trx_id') }}</label>
                        <input id="trx_id" name="trx_id" type="text" maxlength="32" required autocomplete="off" value="{{ old('trx_id') }}" class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white">
                        @error('trx_id')<p class="mt-1 text-sm text-rose-300" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="rounded-xl bg-emerald-500 px-5 py-3 font-semibold text-slate-950 hover:bg-emerald-400">{{ __('payment_ops.manual_wallet.submit') }}</button>
                </form>
            @endif
        </section>
    @endif

    <a href="{{ route('player.deposit') }}" class="inline-flex rounded-xl border border-slate-700 px-4 py-2 font-medium text-slate-200 hover:bg-slate-800">{{ __('payment_ops.back_to_deposits') }}</a>
</div>
@endsection
