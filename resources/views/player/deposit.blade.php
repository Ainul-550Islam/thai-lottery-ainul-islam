@extends('layouts.app')

@section('title', __('player.deposit_title'))

@section('content')
@php
    // Amounts are formatted using the selected currency and exact decimal
    // arithmetic; the ISO code also removes any ambiguity between symbols.
    $formattedMinimum = \App\Services\Finance\Money::of((string) $limits['min'], $currency)->format();
    $formattedMaximum = \App\Services\Finance\Money::of((string) $limits['max'], $currency)->format();
@endphp
<div class="mx-auto max-w-4xl space-y-8 px-4 py-10 sm:px-6 lg:px-8">
    <header class="rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-xl">
        <h1 class="text-2xl font-bold text-white">{{ __('player.deposit_title') }}</h1>
        <p class="mt-2 text-sm text-slate-300">{{ __('player.deposit_lead') }}</p>
        <p class="mt-3 text-sm text-slate-400">{{ __('player.deposit_limits_note', ['min' => $formattedMinimum, 'max' => $formattedMaximum]) }} ({{ $currency->value }})</p>
    </header>

    @if (session('success'))
        <div role="status" class="rounded-xl border border-emerald-700 bg-emerald-950/50 p-4 text-emerald-200">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div role="alert" class="rounded-xl border border-rose-700 bg-rose-950/50 p-4 text-rose-200">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-rose-700 bg-rose-950/50 p-4 text-rose-200">
            <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @if (is_array(session('instructions')))
        <section class="rounded-xl border border-sky-700 bg-sky-950/40 p-5 text-sky-100" aria-live="polite">
            <h2 class="font-semibold">{{ __('payment_ops.deposit_instructions_title') }}</h2>
            <dl class="mt-3 space-y-2 text-sm">
                @foreach (session('instructions') as $key => $value)
                    @if (is_scalar($value) && $value !== '')
                        <div><dt class="inline font-medium">{{ __('payment_ops.'.(string) $key) }}:</dt> <dd class="inline break-all">{{ $value }}</dd></div>
                    @endif
                @endforeach
            </dl>
        </section>
    @endif

    @if (count($methods) > 0)
        <form method="POST" action="{{ route('player.deposit.store') }}" class="space-y-5 rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-xl">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
            <div>
                <label for="method" class="mb-1 block text-sm font-medium text-slate-200">{{ __('player.select_payment_method') }}</label>
                <select id="method" name="method" required class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    @foreach ($methods as $value => $label)
                        <option value="{{ $value }}" @selected(old('method') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('method')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="amount" class="mb-1 block text-sm font-medium text-slate-200">{{ __('player.deposit_amount_label') }} ({{ $currency->value }})</label>
                <input id="amount" name="amount" type="text" inputmode="decimal" autocomplete="off" required value="{{ old('amount') }}" aria-describedby="deposit-limits" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white placeholder-slate-500 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <p id="deposit-limits" class="mt-1 text-xs text-slate-400">{{ __('player.deposit_limits_note', ['min' => $formattedMinimum, 'max' => $formattedMaximum]) }} ({{ $currency->value }})</p>
                @error('amount')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="rounded-xl bg-emerald-600 px-5 py-3 font-semibold text-white transition hover:bg-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2 focus:ring-offset-slate-900">{{ __('player.submit_deposit') }}</button>
            <p class="text-xs text-slate-400">{{ __('player.deposit_footer_note') }}</p>
        </form>
    @else
        <div role="status" class="rounded-xl border border-amber-700 bg-amber-950/40 p-5 text-amber-100">{{ __('payment_ops.no_payment_method_available') }}</div>
    @endif

    <section class="rounded-2xl border border-slate-800 bg-slate-900 p-6">
        <h2 class="text-lg font-semibold text-white">{{ __('player.recent_deposits') }}</h2>
        @if ($recentDeposits->isEmpty())
            <p class="mt-4 text-sm text-slate-400">{{ __('payment_ops.no_recent_deposits') }}</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
                    <thead class="text-slate-400"><tr><th class="px-3 py-2">{{ __('player.col_transaction_ref') }}</th><th class="px-3 py-2">{{ __('player.col_amount') }}</th><th class="px-3 py-2">{{ __('player.status_badge') }}</th><th class="px-3 py-2">{{ __('player.col_date') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-800 text-slate-200">
                        @foreach ($recentDeposits as $deposit)
                            <tr><td class="px-3 py-3"><a class="text-emerald-300 underline" href="{{ route('player.deposit.status', $deposit->reference_number) }}">{{ $deposit->reference_number }}</a></td><td class="px-3 py-3">{{ $deposit->amount }} {{ $deposit->currency->value ?? $deposit->currency }}</td><td class="px-3 py-3">{{ $deposit->status->value ?? $deposit->status }}</td><td class="px-3 py-3">{{ $deposit->created_at?->format('Y-m-d H:i') }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
