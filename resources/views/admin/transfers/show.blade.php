@extends('layouts.app')

@section('title', __('transfer.desk.detail_title', ['reference' => $transfer['reference']]))
@section('meta_robots', 'noindex,nofollow')

{{--
    One transfer, and the only place in the application that reverses one.

    WHY THE REVERSAL FORM LIVES ON THE DETAIL PAGE AND NOT IN THE LIST
    Reversing moves money. It is placed where the operator has just read the amount,
    the direction, the fee and both balances, and where they must type a reason that is
    stored against their own operator id. A one-click reverse button in a table row
    would make the most consequential action on this desk the easiest one to take by
    accident.

    WHAT THE FORM POSTS
    Only `reason`. The transfer is named by the route's reference, the amount and the
    direction come from the stored row, and the operator comes from the session. Nothing
    about the money movement is submitted by the browser, so nothing about it can be
    tampered with in the payload.

    WHY A DISABLED BUTTON SHOWS ITS REASON
    $reverseBlockers is computed in the controller. An unexplained disabled control
    reads as a bug, and an operator who cannot tell "you lack the permission" from "this
    was already reversed" will go and find another way to move the money.
--}}

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-slate-800 pb-6">
        <div>
            <div class="flex items-center space-x-2">
                <a href="{{ route('admin.dashboard') }}" class="text-xs text-emerald-400 hover:underline">&larr; Admin Console</a>
                <span class="text-xs text-slate-600">/</span>
                <a href="{{ route('admin.transfers.index') }}" class="text-xs text-emerald-400 hover:underline">{{ __('transfer.desk.breadcrumb') }}</a>
                <span class="text-xs text-slate-600">/</span>
                <span class="text-xs text-slate-400 font-mono">{{ $transfer['reference'] }}</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight mt-1">
                {{ __('transfer.desk.detail_title', ['reference' => $transfer['reference']]) }}
            </h1>
            <p class="text-sm text-slate-400 mt-2 max-w-3xl">{{ __('transfer.desk.detail_lead') }}</p>
        </div>

        <div class="flex items-center gap-2">
            @if ($transfer['is_reversal'])
                <span class="px-3 py-1 rounded-full text-xs font-bold font-mono bg-sky-500/10 text-sky-400 border border-sky-500/30">
                    {{ __('transfer.desk.badge_reversal') }}
                </span>
            @endif
            @if ($transfer['status'] === 'completed')
                <span class="px-3 py-1 rounded-full text-xs font-bold uppercase bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">{{ $transfer['status_label'] }}</span>
            @elseif ($transfer['status'] === 'failed')
                <span class="px-3 py-1 rounded-full text-xs font-bold uppercase bg-rose-500/10 text-rose-400 border border-rose-500/30">{{ $transfer['status_label'] }}</span>
            @else
                <span class="px-3 py-1 rounded-full text-xs font-bold uppercase bg-slate-500/10 text-slate-400 border border-slate-500/30">{{ $transfer['status_label'] }}</span>
            @endif
        </div>
    </div>

    @if ($transfer['is_reversal'])
        <div class="tl-glass-panel p-4 border-l-4 border-sky-500 text-sm text-sky-200">
            {{ __('transfer.desk.reversal_of', ['reference' => (string) $transfer['reverses_reference']]) }}
            <a href="{{ route('admin.transfers.show', ['reference' => (string) $transfer['reverses_reference']]) }}"
               class="underline font-mono">{{ $transfer['reverses_reference'] }}</a>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            {{-- Summary --}}
            <div class="tl-glass-panel p-6 shadow-xl">
                <h2 class="text-sm font-black text-white uppercase tracking-wider mb-4">{{ __('transfer.desk.section_summary') }}</h2>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.col_reference') }}</dt>
                        <dd class="text-white font-mono">{{ $transfer['reference'] }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_direction') }}</dt>
                        <dd class="text-white">{{ $transfer['direction_label'] ?? $transfer['direction'] }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_amount') }}</dt>
                        <dd class="text-white font-mono">{{ $transfer['amount'] }} {{ $transfer['currency'] }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_fee') }}</dt>
                        <dd class="font-mono {{ $transfer['fee_charged'] ? 'text-amber-300' : 'text-slate-500' }}">{{ $transfer['fee'] }} {{ $transfer['currency'] }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_fee_rate') }}</dt>
                        <dd class="text-white font-mono">
                            {{ $transfer['fee_rate_percent'] === null ? __('transfer.desk.none') : __('transfer.fee_rate_percent', ['percent' => (string) $transfer['fee_rate_percent']]) }}
                        </dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_fee_category') }}</dt>
                        <dd class="text-white font-mono text-xs">{{ $transfer['fee_category'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_total_debited') }}</dt>
                        <dd class="text-white font-mono">{{ $transfer['total_debited'] }} {{ $transfer['currency'] }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_currency') }}</dt>
                        <dd class="text-white font-mono">{{ $transfer['currency'] }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_status') }}</dt>
                        <dd class="text-white">{{ $transfer['status_label'] }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_created') }}</dt>
                        <dd class="text-white font-mono text-xs">{{ $transfer['created_at'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_completed') }}</dt>
                        <dd class="text-white font-mono text-xs">{{ $transfer['completed_at'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                    @if ($transfer['failed_at'] !== null)
                        <div class="flex justify-between border-b border-slate-800/70 pb-2">
                            <dt class="text-slate-400">{{ __('transfer.desk.field_failed') }}</dt>
                            <dd class="text-rose-300 font-mono text-xs">{{ $transfer['failed_at'] }}</dd>
                        </div>
                    @endif
                    @if ($transfer['failure_code'] !== null || $transfer['failure_message'] !== null)
                        <div class="flex justify-between border-b border-slate-800/70 pb-2 sm:col-span-2">
                            <dt class="text-slate-400">{{ __('transfer.desk.field_failure') }}</dt>
                            <dd class="text-rose-300 font-mono text-xs text-right break-all">
                                {{ $transfer['failure_code'] }}
                            </dd>
                        </div>
                    @endif
                    <div class="flex justify-between border-b border-slate-800/70 pb-2 sm:col-span-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_uuid') }}</dt>
                        <dd class="text-slate-300 font-mono text-xs break-all">{{ $transfer['uuid'] }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Balances --}}
            <div class="tl-glass-panel p-6 shadow-xl">
                <h2 class="text-sm font-black text-white uppercase tracking-wider mb-4">{{ __('transfer.desk.section_balances') }}</h2>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_source_pocket') }}</dt>
                        <dd class="text-white">{{ $transfer['from'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_destination_pocket') }}</dt>
                        <dd class="text-white">{{ $transfer['to'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_source_before') }}</dt>
                        <dd class="text-white font-mono">{{ $transfer['source_balance_before'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_source_after') }}</dt>
                        <dd class="text-white font-mono">{{ $transfer['source_balance_after'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_destination_before') }}</dt>
                        <dd class="text-white font-mono">{{ $transfer['destination_balance_before'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_destination_after') }}</dt>
                        <dd class="text-white font-mono">{{ $transfer['destination_balance_after'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Ledger legs --}}
            <div class="tl-glass-panel p-6 shadow-xl">
                <h2 class="text-sm font-black text-white uppercase tracking-wider mb-4">{{ __('transfer.desk.section_ledger') }}</h2>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_source_wallet') }}</dt>
                        <dd class="text-white font-mono">{{ $transfer['source_wallet_id'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_destination_wallet') }}</dt>
                        <dd class="text-white font-mono">{{ $transfer['destination_wallet_id'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_debit_leg') }}</dt>
                        <dd class="text-white font-mono">{{ $transfer['debit_transaction_id'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_credit_leg') }}</dt>
                        <dd class="text-white font-mono">{{ $transfer['credit_transaction_id'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2 sm:col-span-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_fee_leg') }}</dt>
                        <dd class="text-white font-mono">{{ $transfer['fee_transaction_id'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Player and request attribution --}}
            <div class="tl-glass-panel p-6 shadow-xl">
                <h2 class="text-sm font-black text-white uppercase tracking-wider mb-4">{{ __('transfer.desk.section_player') }}</h2>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_player') }}</dt>
                        <dd class="text-white">{{ $transfer['player_name'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_player_id') }}</dt>
                        <dd class="text-white font-mono">{{ $transfer['user_id'] }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_request_ip') }}</dt>
                        <dd class="text-white font-mono text-xs">{{ $transfer['request_ip'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-800/70 pb-2">
                        <dt class="text-slate-400">{{ __('transfer.desk.field_user_agent') }}</dt>
                        <dd class="text-slate-300 text-xs break-all text-right">{{ $transfer['user_agent'] ?? __('transfer.desk.none') }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="space-y-6">
            {{-- Existing reversal, if any --}}
            @if ($reversal !== null)
                <div class="tl-glass-panel p-6 shadow-xl border-l-4 border-amber-500">
                    <h2 class="text-sm font-black text-white uppercase tracking-wider mb-4">{{ __('transfer.desk.section_reversal') }}</h2>
                    <p class="text-sm text-slate-300">
                        {{ __('transfer.desk.reversed_by', ['reference' => (string) $reversal['reference']]) }}
                    </p>
                    <a href="{{ route('admin.transfers.show', ['reference' => (string) $reversal['reference']]) }}"
                       class="inline-block mt-2 text-emerald-400 hover:underline font-mono text-xs">
                        {{ $reversal['reference'] }}
                    </a>
                    <dl class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between border-b border-slate-800/70 pb-2">
                            <dt class="text-slate-400">{{ __('transfer.desk.field_amount') }}</dt>
                            <dd class="text-white font-mono">{{ $reversal['amount'] }} {{ $reversal['currency'] }}</dd>
                        </div>
                        <div class="flex justify-between border-b border-slate-800/70 pb-2">
                            <dt class="text-slate-400">{{ __('transfer.desk.field_fee') }}</dt>
                            <dd class="text-white font-mono">{{ $reversal['fee'] }}</dd>
                        </div>
                        <div class="flex justify-between border-b border-slate-800/70 pb-2">
                            <dt class="text-slate-400">{{ __('transfer.desk.reversal_operator') }}</dt>
                            <dd class="text-white font-mono">{{ $reversal['reversal_operator_id'] ?? __('transfer.desk.none') }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-400">{{ __('transfer.desk.reversal_reason') }}</dt>
                            <dd class="text-slate-200 text-xs text-right">{{ $reversal['reversal_reason'] ?? __('transfer.desk.none') }}</dd>
                        </div>
                    </dl>
                </div>
            @endif

            {{-- This row's own reversal attribution.
                 A compensating transfer is a first-class row, and an operator who lands
                 on it from a search must be able to see who made it and why without
                 walking back to the original. Rendered escaped: the reason is free text
                 typed by staff, and free text is never trusted. --}}
            @if ($transfer['is_reversal'])
                <div class="tl-glass-panel p-6 shadow-xl border-l-4 border-sky-500">
                    <h2 class="text-sm font-black text-white uppercase tracking-wider mb-4">{{ __('transfer.desk.section_reversal') }}</h2>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between border-b border-slate-800/70 pb-2">
                            <dt class="text-slate-400">{{ __('transfer.desk.reversal_operator') }}</dt>
                            <dd class="text-white font-mono">{{ $transfer['reversal_operator_id'] ?? __('transfer.desk.none') }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-400">{{ __('transfer.desk.reversal_reason') }}</dt>
                            <dd class="text-slate-200 text-xs text-right">{{ $transfer['reversal_reason'] ?? __('transfer.desk.none') }}</dd>
                        </div>
                    </dl>
                </div>
            @endif

            {{-- The reversal form --}}
            <div class="tl-glass-panel p-6 shadow-xl">
                <h2 class="text-sm font-black text-white uppercase tracking-wider mb-2">{{ __('transfer.desk.reverse_title') }}</h2>

                @if (count($reverseBlockers) > 0)
                    <ul class="mt-3 space-y-2 text-xs text-amber-300">
                        @foreach ($reverseBlockers as $blocker)
                            <li class="flex gap-2">
                                <span aria-hidden="true">•</span>
                                <span>{{ $blocker }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <button type="button"
                            disabled
                            class="mt-4 w-full bg-slate-800 text-slate-500 text-sm font-bold px-4 py-3 rounded-lg cursor-not-allowed">
                        {{ __('transfer.desk.reverse_submit') }}
                    </button>
                @elseif (! $transfer['reversible'])
                    <p class="mt-3 text-xs text-slate-400">{{ __('transfer.desk.reverse_unavailable') }}</p>
                    <button type="button"
                            disabled
                            class="mt-4 w-full bg-slate-800 text-slate-500 text-sm font-bold px-4 py-3 rounded-lg cursor-not-allowed">
                        {{ __('transfer.desk.reverse_submit') }}
                    </button>
                @else
                    <p class="text-xs text-amber-300 font-bold uppercase tracking-wide mt-2">{{ __('transfer.desk.reverse_warning') }}</p>
                    <p class="text-xs text-slate-400 mt-2 leading-relaxed">{{ __('transfer.desk.reverse_lead') }}</p>

                    <form method="POST"
                          action="{{ route('admin.transfers.reverse', ['reference' => $transfer['reference']]) }}"
                          class="mt-4 space-y-3">
                        @csrf

                        <div>
                            <label for="reverse-reason" class="block text-xs font-semibold text-slate-400 uppercase mb-1">
                                {{ __('transfer.desk.reason_label') }}
                            </label>
                            <textarea
                                id="reverse-reason"
                                name="reason"
                                rows="4"
                                required
                                minlength="{{ $reasonMin }}"
                                maxlength="{{ $reasonMax }}"
                                placeholder="{{ __('transfer.desk.reason_placeholder') }}"
                                class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-amber-500 @error('reason') border-rose-500 @enderror">{{ old('reason') }}</textarea>
                            <p class="text-[11px] text-slate-500 mt-1">
                                {{ __('transfer.desk.reason_hint', ['min' => $reasonMin]) }}
                            </p>
                            @error('reason')
                                <p class="text-[11px] text-rose-400 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit"
                                class="w-full bg-amber-600 hover:bg-amber-500 text-white text-sm font-bold px-4 py-3 rounded-lg transition-colors">
                            {{ __('transfer.desk.reverse_submit') }}
                        </button>
                    </form>
                @endif
            </div>

            <a href="{{ route('admin.transfers.index') }}"
               class="block text-center bg-slate-800 hover:bg-slate-700 text-slate-200 text-sm font-bold px-4 py-3 rounded-lg transition-colors">
                {{ __('transfer.desk.back_to_desk') }}
            </a>
        </div>
    </div>
</div>
@endsection
