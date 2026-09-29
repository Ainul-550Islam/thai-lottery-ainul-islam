@extends('layouts.app')

@section('title', __('account_services.payment_callback_title').' — Thai Lottery')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">
            {{ __('account_services.payment_callback_title') }}
        </h1>
        <p class="text-slate-400 text-sm mt-1">{{ __('account_services.payment_callback_lead') }}</p>
    </div>

    @if (! $found)
        {{-- Unknown, expired or someone else's reference: no details leak. --}}
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-xl text-center">
            <div class="w-14 h-14 mx-auto rounded-full bg-slate-800 flex items-center justify-center mb-4">
                <svg class="w-7 h-7 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <h2 class="text-lg font-bold text-white mb-2">{{ __('account_services.payment_callback_not_found') }}</h2>
            <p class="text-sm text-slate-400 max-w-md mx-auto">{{ __('account_services.payment_callback_not_found_lead') }}</p>
        </div>
    @else
        {{-- The STATE badge: authoritative internal status, never the landing URL. --}}
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-xl">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider font-semibold block mb-1">
                        {{ __('account_services.payment_callback_state_label') }}
                    </span>
                    @if ($paid)
                        <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                            {{ __('account_services.payment_callback_state_confirmed') }}
                        </span>
                    @elseif ($state === 'failed')
                        <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-bold bg-rose-500/10 text-rose-400 border border-rose-500/30">
                            {{ __('account_services.payment_callback_state_failed') }}
                        </span>
                    @elseif ($state === 'cancelled')
                        <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-bold bg-slate-500/10 text-slate-300 border border-slate-500/30">
                            {{ __('account_services.payment_callback_state_cancelled') }}
                        </span>
                    @elseif ($state === 'updated')
                        <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-bold bg-amber-500/10 text-amber-400 border border-amber-500/30">
                            {{ __('account_services.payment_callback_state_updated') }}
                        </span>
                    @else
                        <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-bold bg-amber-500/10 text-amber-400 border border-amber-500/30">
                            {{ __('account_services.payment_callback_state_pending') }}
                        </span>
                    @endif
                </div>
                <div class="text-right">
                    @if ($reference !== '')
                        <span class="text-xs text-slate-400 uppercase tracking-wider font-semibold block">{{ __('account_services.payment_callback_reference_label') }}</span>
                        <span class="font-mono text-sm text-slate-200">{{ $reference }}</span>
                    @endif
                    @if ($amount !== '')
                        <span class="text-xs text-slate-400 uppercase tracking-wider font-semibold block mt-2">{{ __('account_services.payment_callback_amount_label') }}</span>
                        <span class="font-mono text-sm font-bold text-white">{{ $amount }}</span>
                    @endif
                </div>
            </div>

            <p class="text-sm text-slate-300 mt-6">
                @if ($paid)
                    {{ __('account_services.payment_callback_success_lead') }}
                @elseif ($context === 'cancel' && $state === 'cancelled')
                    {{ __('account_services.payment_callback_cancel_lead') }}
                @elseif ($state === 'failed')
                    {{ __('account_services.payment_callback_failure_lead') }}
                @elseif ($state === 'pending')
                    {{ __('account_services.payment_callback_pending_lead') }}
                @else
                    {{ __('account_services.payment_callback_updated_lead') }}
                @endif
            </p>

            <p class="text-[11px] text-slate-500 mt-4">{{ __('account_services.payment_callback_disclaimer') }}</p>
        </div>
    @endif

    <div class="flex flex-col sm:flex-row gap-3">
        <a href="{{ route('player.wallet') }}" class="flex-1 px-5 py-3 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl shadow-lg transition text-center text-sm">
            {{ __('account_services.payment_callback_back_to_wallet') }}
        </a>
        <a href="{{ route('player.deposit') }}" class="flex-1 px-5 py-3 bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold rounded-xl border border-slate-700 transition text-center text-sm">
            {{ __('account_services.payment_callback_back_to_deposit') }}
        </a>
    </div>
</div>
@endsection
