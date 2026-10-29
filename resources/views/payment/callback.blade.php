@extends('layouts.app')

@section('title', __('payment_ops.callback_title'))

@section('content')
@php
    $stateKey = in_array($state, ['pending', 'confirmed', 'failed', 'cancelled', 'updated'], true) ? $state : 'unknown';
    $contextKey = in_array($context, ['success', 'failure', 'cancel', 'pending'], true) ? $context : 'pending';
@endphp
<div class="mx-auto max-w-2xl space-y-6 px-4 py-12 sm:px-6 lg:px-8">
    <section class="rounded-2xl border border-slate-800 bg-slate-900 p-7 shadow-xl">
        <p class="text-sm font-semibold uppercase tracking-wide text-emerald-300">{{ __('payment_ops.callback_context.'.$contextKey) }}</p>
        <h1 class="mt-2 text-2xl font-bold text-white">{{ __('payment_ops.callback_title') }}</h1>
        @if ($found)
            <p class="mt-3 text-slate-300">{{ __('payment_ops.callback_internal_state') }}</p>
            <dl class="mt-6 space-y-4">
                <div><dt class="text-sm text-slate-400">{{ __('payment_ops.status') }}</dt><dd class="mt-1 font-semibold text-white" data-testid="payment-state">{{ $stateKey === 'unknown' ? __('payment_ops.statuses.unknown') : __('account_services.payment_callback_state_'.$stateKey) }}</dd></div>
                @if ($reference !== '')<div><dt class="text-sm text-slate-400">{{ __('payment_ops.reference') }}</dt><dd class="mt-1 break-all text-slate-200">{{ $reference }}</dd></div>@endif
                @if ($paymentReference !== '')<div><dt class="text-sm text-slate-400">{{ __('payment_ops.payment_reference') }}</dt><dd class="mt-1 break-all text-slate-200">{{ $paymentReference }}</dd></div>@endif
                @if ($amount !== '')<div><dt class="text-sm text-slate-400">{{ __('payment_ops.amount') }}</dt><dd class="mt-1 text-slate-200">{{ $amount }}</dd></div>@endif
            </dl>
            @if ($paid)
                <p role="status" class="mt-6 rounded-xl border border-emerald-700 bg-emerald-950/40 p-4 text-emerald-100">{{ __('payment_ops.callback_paid_notice') }}</p>
            @else
                <p role="status" class="mt-6 rounded-xl border border-amber-700 bg-amber-950/40 p-4 text-amber-100">{{ __('payment_ops.callback_not_paid_notice') }}</p>
            @endif
        @else
            <div role="status" class="mt-4 rounded-xl border border-amber-700 bg-amber-950/40 p-4 text-amber-100">
                <p class="font-semibold">{{ __('account_services.payment_callback_not_found') }}</p>
                <p>{{ __('account_services.payment_callback_not_found_lead') }}</p>
            </div>
        @endif
        <p class="mt-5 text-xs text-slate-400">{{ __('payment_ops.callback_no_state_change_note') }}</p>
    </section>
    <a href="{{ route('player.dashboard') }}" class="inline-flex rounded-xl bg-emerald-600 px-5 py-3 font-semibold text-white hover:bg-emerald-500">{{ __('payment_ops.return_to_account') }}</a>
</div>
@endsection
