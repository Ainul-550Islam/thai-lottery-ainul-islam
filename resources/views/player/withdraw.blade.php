@extends('layouts.app')

@section('title', __('player.withdraw_title'))

@section('content')
@php
    $formattedMinimum = \App\Services\Finance\Money::of((string) $limits['min'], $currency)->format();
    $formattedMaximum = \App\Services\Finance\Money::of((string) $limits['max'], $currency)->format();
    $requestedMethod = (string) old('method', '');
    $selectedMethod = array_key_exists($requestedMethod, $methods)
        ? $requestedMethod
        : (string) (array_key_first($methods) ?? '');
    $isPromptPay = $selectedMethod === \App\Enums\PaymentMethod::PromptPay->value;
    $isBankTransfer = $selectedMethod === \App\Enums\PaymentMethod::BankTransfer->value;
    $promptPayType = (string) old('destination_type', 'promptpay_mobile');
@endphp
<div class="mx-auto max-w-4xl space-y-8 px-4 py-10 sm:px-6 lg:px-8">
    <header class="rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-xl">
        <h1 class="text-2xl font-bold text-white">{{ __('player.withdraw_title') }}</h1>
        <p class="mt-2 text-sm text-slate-300">{{ __('player.withdraw_lead') }}</p>
        @if ($availableBalance !== null)
            <p class="mt-4 text-sm text-slate-200">{{ __('player.available_to_withdraw') }}: <span class="font-semibold">{{ $availableBalance }} {{ $currency->value }}</span></p>
        @endif
        <p class="mt-2 text-sm text-slate-400">{{ __('player.min_withdrawal') }} {{ $formattedMinimum }} {{ $currency->value }} · {{ __('player.max_withdrawal') }} {{ $formattedMaximum }} {{ $currency->value }} · {{ __('player.processing_time') }} {{ __('player.within_hours', ['hours' => $limits['processing_hours']]) }}</p>
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

    @if (count($methods) > 0)
        <form id="withdrawal-form" method="POST" action="{{ route('player.withdraw.store') }}" class="space-y-5 rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-xl">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
            <div>
                <label for="method" class="mb-1 block text-sm font-medium text-slate-200">{{ __('player.payout_method') }}</label>
                <select id="method" name="method" required class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    @foreach ($methods as $value => $label)
                        <option value="{{ $value }}" @selected($selectedMethod === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('method')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="amount" class="mb-1 block text-sm font-medium text-slate-200">{{ __('player.withdrawal_amount_label') }} ({{ $currency->value }})</label>
                <input id="amount" name="amount" type="text" inputmode="decimal" autocomplete="off" required value="{{ old('amount') }}" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white placeholder-slate-500 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                @error('amount')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="account_name" class="mb-1 block text-sm font-medium text-slate-200">{{ __('player.account_holder_name') }}</label>
                <input id="account_name" name="account_name" type="text" autocomplete="name" maxlength="120" required value="{{ old('account_name') }}" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <p class="mt-1 text-xs text-slate-400">{{ __('player.kyc_name_match') }}</p>
                @error('account_name')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror
            </div>

            <div id="bank-name-group" @if (! $isBankTransfer) hidden @endif>
                <label for="bank_name" class="mb-1 block text-sm font-medium text-slate-200">{{ __('player.bank_name') }} <span class="text-slate-400">{{ __('player.bank_transfer_only') }}</span></label>
                <select id="bank_name" name="bank_name" @disabled(! $isBankTransfer) class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <option value="">{{ __('player.select_bank_prompt') }}</option>
                    @foreach ($supportedBanks as $code => $name)
                        <option value="{{ $code }}" @selected(old('bank_name') === (string) $code || old('bank_name') === (string) $name)>{{ $name }}</option>
                    @endforeach
                </select>
                @error('bank_name')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror
            </div>

            <div id="account-number-group" @if ($isPromptPay) hidden @endif>
                <label for="account_number" class="mb-1 block text-sm font-medium text-slate-200">{{ __('player.account_label') }}</label>
                <input id="account_number" name="account_number" type="text" autocomplete="off" maxlength="255" value="{{ old('account_number') }}" @disabled($isPromptPay) @required(! $isPromptPay) class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                @error('account_number')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror
            </div>

            <div id="promptpay-group" @if (! $isPromptPay) hidden @endif>
                <div>
                    <label for="destination_type" class="mb-1 block text-sm font-medium text-slate-200">{{ __('payment_ops.promptpay_destination_type') }}</label>
                    <select id="destination_type" name="destination_type" @disabled(! $isPromptPay) class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        <option value="promptpay_mobile" @selected($promptPayType === 'promptpay_mobile')>{{ __('payment_ops.promptpay_mobile') }}</option>
                        <option value="promptpay_national_id" @selected($promptPayType === 'promptpay_national_id')>{{ __('payment_ops.promptpay_national_id') }}</option>
                    </select>
                    @error('destination_type')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror
                </div>
                <div id="promptpay-mobile-group" class="mt-4" @if (! $isPromptPay || $promptPayType !== 'promptpay_mobile') hidden @endif>
                    <label for="mobile" class="mb-1 block text-sm font-medium text-slate-200">{{ __('payment_ops.mobile_number') }}</label>
                    <input id="mobile" name="mobile" type="tel" inputmode="numeric" autocomplete="tel" value="{{ old('mobile') }}" @disabled(! $isPromptPay || $promptPayType !== 'promptpay_mobile') @required($isPromptPay && $promptPayType === 'promptpay_mobile') class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    @error('mobile')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror
                </div>
                <div id="promptpay-national-id-group" class="mt-4" @if (! $isPromptPay || $promptPayType !== 'promptpay_national_id') hidden @endif>
                    <label for="national_id" class="mb-1 block text-sm font-medium text-slate-200">{{ __('payment_ops.national_id') }}</label>
                    <input id="national_id" name="national_id" type="text" inputmode="numeric" autocomplete="off" maxlength="13" value="{{ old('national_id') }}" @disabled(! $isPromptPay || $promptPayType !== 'promptpay_national_id') @required($isPromptPay && $promptPayType === 'promptpay_national_id') class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    @error('national_id')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror
                </div>
            </div>

            <button type="submit" class="rounded-xl bg-emerald-600 px-5 py-3 font-semibold text-white transition hover:bg-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2 focus:ring-offset-slate-900">{{ __('player.submit_withdrawal') }}</button>
            <p class="text-xs text-slate-400">{{ __('player.withdrawal_review_note', ['hours' => $limits['processing_hours']]) }}</p>
        </form>
    @else
        <div role="status" class="rounded-xl border border-amber-700 bg-amber-950/40 p-5 text-amber-100">{{ __('payment_ops.no_payment_method_available') }}</div>
    @endif

    <section class="rounded-2xl border border-slate-800 bg-slate-900 p-6">
        <h2 class="text-lg font-semibold text-white">{{ __('player.recent_withdrawals') }}</h2>
        @if ($recentWithdrawals->isEmpty())
            <p class="mt-4 text-sm text-slate-400">{{ __('payment_ops.no_recent_withdrawals') }}</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
                    <thead class="text-slate-400"><tr><th class="px-3 py-2">{{ __('player.col_transaction_ref') }}</th><th class="px-3 py-2">{{ __('player.col_amount') }}</th><th class="px-3 py-2">{{ __('player.status_badge') }}</th><th class="px-3 py-2">{{ __('player.col_date') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-800 text-slate-200">
                        @foreach ($recentWithdrawals as $withdrawal)
                            <tr><td class="px-3 py-3"><a class="text-emerald-300 underline" href="{{ route('player.withdrawal.status', $withdrawal->reference_number) }}">{{ $withdrawal->reference_number }}</a></td><td class="px-3 py-3">{{ $withdrawal->amount }} {{ $withdrawal->currency->value }}</td><td class="px-3 py-3">{{ $withdrawal->status->value }}</td><td class="px-3 py-3">{{ $withdrawal->created_at?->format('Y-m-d H:i') }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
<script>
(() => {
    const method = document.getElementById('method');
    const bankGroup = document.getElementById('bank-name-group');
    const accountGroup = document.getElementById('account-number-group');
    const account = document.getElementById('account_number');
    const promptGroup = document.getElementById('promptpay-group');
    const destinationType = document.getElementById('destination_type');
    const mobileGroup = document.getElementById('promptpay-mobile-group');
    const nationalGroup = document.getElementById('promptpay-national-id-group');
    const mobile = document.getElementById('mobile');
    const nationalId = document.getElementById('national_id');
    if (!method) return;

    const update = () => {
        const isPromptPay = method.value === 'promptpay';
        const isBankTransfer = method.value === 'bank_transfer';
        const isMobilePrompt = isPromptPay && destinationType.value === 'promptpay_mobile';
        const isNationalPrompt = isPromptPay && destinationType.value === 'promptpay_national_id';
        bankGroup.hidden = !isBankTransfer;
        bankGroup.querySelector('select').disabled = !isBankTransfer;
        accountGroup.hidden = isPromptPay;
        account.disabled = isPromptPay;
        account.required = !isPromptPay;
        promptGroup.hidden = !isPromptPay;
        destinationType.disabled = !isPromptPay;
        mobileGroup.hidden = !isMobilePrompt;
        mobile.disabled = !isMobilePrompt;
        mobile.required = isMobilePrompt;
        nationalGroup.hidden = !isNationalPrompt;
        nationalId.disabled = !isNationalPrompt;
        nationalId.required = isNationalPrompt;
    };
    method.addEventListener('change', update);
    destinationType.addEventListener('change', update);
    update();
})();
</script>
@endsection
