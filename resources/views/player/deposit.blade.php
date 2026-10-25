@extends('layouts.app')

@section('title', __('player.deposit_title').' — '.config('app.name', 'Thai Lottery'))
@section('meta_robots', 'noindex, nofollow')

@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">{{ __('player.deposit_title') }}</h1>
        <p class="text-slate-400 text-sm mt-1">{{ __('player.deposit_lead') }}</p>
    </div>

    <!-- Flash feedback: the deposit reference and its real status, or the
         engine's own refusal message. Nothing is invented here. -->
    @if (session('success'))
        <div class="bg-emerald-950/60 border border-emerald-600/40 rounded-xl px-4 py-3 text-sm text-emerald-300">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="bg-rose-950/60 border border-rose-600/40 rounded-xl px-4 py-3 text-sm text-rose-300">
            {{ session('error') }}
        </div>
    @endif
    @if (session('instructions'))
        {{-- Manual settlement instructions (FINAL AUDIT #4): rendered ONLY from
             the operator-configured settlement details the gateway returned;
             the view itself contains no bank identity of its own. --}}
        @php $manual = session('instructions'); @endphp
        <div class="bg-slate-900/80 border border-slate-700 rounded-xl px-4 py-4 text-sm">
            <h3 class="font-bold text-white mb-1">{{ __('account_services.deposit_manual_title') }}</h3>
            <p class="text-slate-400 text-xs mb-3">{{ __('account_services.deposit_manual_lead') }}</p>
            <dl class="grid gap-2">
                @if (! empty($manual['bank_name']))
                    <div class="flex justify-between gap-4 border-b border-slate-800 pb-1">
                        <dt class="text-slate-400">{{ __('account_services.deposit_manual_bank_label') }}</dt>
                        <dd class="font-semibold text-slate-100">{{ $manual['bank_name'] }}</dd>
                    </div>
                @endif
                @if (! empty($manual['account_number']))
                    <div class="flex justify-between gap-4 border-b border-slate-800 pb-1">
                        <dt class="text-slate-400">{{ __('account_services.deposit_manual_account_number_label') }}</dt>
                        <dd class="font-mono font-semibold text-slate-100">{{ $manual['account_number'] }}</dd>
                    </div>
                @endif
                @if (! empty($manual['account_name']))
                    <div class="flex justify-between gap-4 border-b border-slate-800 pb-1">
                        <dt class="text-slate-400">{{ __('account_services.deposit_manual_account_name_label') }}</dt>
                        <dd class="font-semibold text-slate-100">{{ $manual['account_name'] }}</dd>
                    </div>
                @endif
                @if (! empty($manual['reference']))
                    <div class="flex justify-between gap-4 border-b border-slate-800 pb-1">
                        <dt class="text-slate-400">{{ __('account_services.deposit_manual_reference_label') }}</dt>
                        <dd class="font-mono font-semibold text-emerald-400">{{ $manual['reference'] }}</dd>
                    </div>
                @endif
                @if (! empty($manual['instructions']))
                    <div class="pt-1 text-slate-300">{{ $manual['instructions'] }}</div>
                @endif
            </dl>
        </div>
    @endif
    @if ($errors->any())
        <div class="bg-rose-950/60 border border-rose-600/40 rounded-xl px-4 py-3 text-sm text-rose-300">
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Deposit Form Card -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-xl">
        <form method="POST" action="{{ route('player.deposit.store') }}" class="space-y-6">
            @csrf
            {{-- One-time key: a double-submit replays the same key and the
                 finance engine returns the existing deposit row, so no
                 double order can ever be created from one form render. --}}
            <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid()->toString() }}">

            <!-- Method Selection: rendered from the SAME closed config list
                 the DepositService validates against. No advertised gateway
                 that the canonical payment model does not have. -->
            <div>
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-3">{{ __('player.select_payment_method') }}</label>
                @if ($methods === [])
                    <div class="rounded-xl border border-amber-500/30 bg-amber-950/20 p-4 text-sm text-amber-200" role="status">{{ __('player.payment_methods_not_configured') }}</div>
                @else
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($methods as $value => $label)
                            <label class="border border-slate-800 bg-slate-950 p-4 rounded-xl flex flex-col items-center justify-center cursor-pointer hover:border-emerald-500 transition">
                                <input type="radio" name="method" value="{{ $value }}" @checked($loop->first) class="text-emerald-500 focus:ring-emerald-500 mb-2">
                                <span class="font-bold text-sm text-white">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Amount Input: bounds mirror the configured engine limits. -->
            <div>
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">{{ __('player.deposit_amount_label') }}</label>
                <div class="relative rounded-xl shadow-sm">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <span class="text-slate-500 font-bold">฿</span>
                    </div>
                    <input type="number" name="amount" value="{{ old('amount') }}" min="{{ $limits['min'] }}" max="{{ $limits['max'] }}" step="0.01" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl pl-8 pr-4 py-3 text-white font-mono font-bold text-lg focus:outline-none focus:border-emerald-500">
                </div>
                <span class="text-[11px] text-slate-500 mt-1 block">
                    {{ __('player.deposit_limits_note', [
                        'min' => \App\Services\Finance\Money::of($limits['min'], $currency)->format(),
                        'max' => \App\Services\Finance\Money::of($limits['max'], $currency)->format(),
                    ]) }}
                </span>
            </div>

            <button type="submit" @disabled($methods === []) class="w-full py-3.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl shadow-lg transition duration-150 disabled:cursor-not-allowed disabled:opacity-50">
                {{ __('player.submit_deposit') }}
            </button>
            <p class="text-[11px] text-slate-500 text-center">
                {{ __('player.deposit_footer_note') }}
            </p>
        </form>
    </div>


{{-- Batch 08: merged deposit backends. Every endpoint below comes from a named
     route; the JS reads the data-* attributes and never hard-codes an API path.
     The <noscript> copy explains the honest fallback when JavaScript is off. --}}
@php
    $depositL10n = [
        'notConfigured' => __('deposit.not_configured'),
        'notConfiguredHelp' => __('deposit.not_configured_help'),
        'methodsLoading' => __('deposit.methods_loading'),
        'methodsEmpty' => __('deposit.methods_empty'),
        'methodsFailed' => __('deposit.methods_failed'),
        'authenticationRequired' => __('deposit.authentication_required'),
        'serviceUnavailable' => __('deposit.service_unavailable'),
        'networkError' => __('deposit.network_error'),
        'validationError' => __('deposit.validation_error'),
        'laneAvailable' => __('deposit.lane_available'),
        'laneUnavailable' => __('deposit.lane_unavailable'),
        'promptpaySubmit' => __('deposit.promptpay_submit'),
        'promptpaySubmitting' => __('deposit.promptpay_submitting'),
        'amountInvalid' => __('deposit.amount_invalid'),
        'noQrPayload' => __('deposit.no_qr_payload'),
        'qrRenderFailed' => __('deposit.qr_render_failed'),
        'qrAlt' => __('deposit.qr_alt'),
        'referenceLabel' => __('deposit.reference_label'),
        'amountLabel' => __('deposit.amount_label'),
        'expiresLabel' => __('deposit.expires_label'),
        'expired' => __('deposit.expired'),
        'statusLabel' => __('deposit.status_label'),
        'copyReference' => __('deposit.copy_reference'),
        'copied' => __('deposit.copied'),
        'copyFailed' => __('deposit.copy_failed'),
        'pollingHint' => __('deposit.polling_hint'),
        'realtimeHint' => __('deposit.realtime_hint'),
        'status_pending' => __('deposit.status_pending'),
        'status_processing' => __('deposit.status_processing'),
        'status_confirmed' => __('deposit.status_confirmed'),
        'status_approved' => __('deposit.status_approved'),
        'status_completed' => __('deposit.status_completed'),
        'status_failed' => __('deposit.status_failed'),
        'status_rejected' => __('deposit.status_rejected'),
        'status_cancelled' => __('deposit.status_cancelled'),
        'status_canceled' => __('deposit.status_cancelled'),
        'status_expired' => __('deposit.status_expired'),
        'status_refunded' => __('deposit.status_refunded'),
        'voucherUrlInvalid' => __('deposit.voucher_url_invalid'),
        'redeeming' => __('deposit.redeeming'),
        'submit' => __('deposit.submit_redeem'),
        'creditedTitle' => __('deposit.credited_title'),
        'creditedAmount' => __('deposit.credited_amount'),
        'depositReference' => __('deposit.deposit_reference'),
        'pendingReview' => __('deposit.pending_review'),
        'error_truemoney_not_configured' => __('deposit.error_truemoney_not_configured'),
        'error_truemoney_api_not_acknowledged' => __('deposit.error_truemoney_api_not_acknowledged'),
        'error_truemoney_voucher_invalid' => __('deposit.error_truemoney_voucher_invalid'),
        'error_truemoney_voucher_already_processed' => __('deposit.error_truemoney_voucher_already_processed'),
        'error_truemoney_daily_count_limit' => __('deposit.error_truemoney_daily_count_limit'),
        'error_truemoney_daily_amount_limit' => __('deposit.error_truemoney_daily_amount_limit'),
        'error_truemoney_fraud_denied' => __('deposit.error_truemoney_fraud_denied'),
        'error_truemoney_outcome_unknown' => __('deposit.error_truemoney_outcome_unknown'),
        'error_truemoney_credit_unconfirmed' => __('deposit.error_truemoney_credit_unconfirmed'),
        'error_truemoney_review_required' => __('deposit.error_truemoney_review_required'),
        'error_truemoney_processing_failed' => __('deposit.error_truemoney_processing_failed'),
        'error_unauthenticated' => __('deposit.error_unauthenticated'),
        'intentNotConfigured' => __('deposit.intent_not_configured'),
        'intentLoading' => __('deposit.intent_loading'),
        'intentFailed' => __('deposit.intent_failed'),
        'imageInvalidType' => __('deposit.image_invalid_type'),
        'imageTooLarge' => __('deposit.image_too_large'),
        'qrInvalid' => __('deposit.qr_invalid'),
        'previewAlt' => __('deposit.preview_alt'),
        'submitting' => __('deposit.submitting'),
        'submitSlip' => __('deposit.submit_slip'),
        'statusLabelSlip' => __('deposit.slip_status_label'),
        'status_verified' => __('deposit.slip_status_verified'),
        'status_creditedSlip' => __('deposit.slip_status_credited'),
        'status_needs_review' => __('deposit.slip_status_needs_review'),
        'status_duplicate' => __('deposit.slip_status_duplicate'),
        'reasonPrefix' => __('deposit.reason_prefix'),
        'reason_unknown' => __('deposit.reason_unknown'),
        'reason_upload_scan_not_clean' => __('deposit.reason_upload_scan_not_clean'),
        'reason_quarantined_file_unavailable' => __('deposit.reason_quarantined_file_unavailable'),
        'reason_provider_unreachable' => __('deposit.reason_provider_unreachable'),
        'reason_provider_not_configured' => __('deposit.reason_provider_not_configured'),
        'reason_provider_rejected_slip' => __('deposit.reason_provider_rejected_slip'),
        'reason_provider_error' => __('deposit.reason_provider_error'),
        'reason_provider_invalid' => __('deposit.reason_provider_invalid'),
        'reason_duplicateReason' => __('deposit.reason_duplicate'),
        'reason_provider_duplicate' => __('deposit.reason_provider_duplicate'),
        'reason_trans_ref_already_seen' => __('deposit.reason_trans_ref_already_seen'),
        'reason_amount_mismatch' => __('deposit.reason_amount_mismatch'),
        'reason_provider_amount_invalid' => __('deposit.reason_provider_amount_invalid'),
        'reason_currency_mismatch' => __('deposit.reason_currency_mismatch'),
        'reason_receiver_mismatch' => __('deposit.reason_receiver_mismatch'),
        'reason_expired' => __('deposit.reason_expired'),
        'reason_slip_expired' => __('deposit.reason_slip_expired'),
        'reason_future_dated' => __('deposit.reason_future_dated'),
        'reason_fraud_denied' => __('deposit.reason_fraud_denied'),
        'reason_deposit_mismatch' => __('deposit.reason_deposit_mismatch'),
        'reason_fraud_check_unavailable' => __('deposit.reason_fraud_check_unavailable'),
        'reason_manual_review_required' => __('deposit.reason_manual_review_required'),
        'reason_matching_pending_deposit_not_found' => __('deposit.reason_matching_pending_deposit_not_found'),
        'reason_automatic_credit_pending' => __('deposit.reason_automatic_credit_pending'),
        'reason_deposit_completion_failed' => __('deposit.reason_deposit_completion_failed'),
        'error_slip_verification_not_configured' => __('deposit.error_slip_verification_not_configured'),
        'error_slip_invalid' => __('deposit.error_slip_invalid'),
        'error_slip_credit_unconfirmed' => __('deposit.error_slip_credit_unconfirmed'),
        'error_slip_verification_failed' => __('deposit.error_slip_verification_failed'),
        'error_deposit_not_found' => __('deposit.error_deposit_not_found'),
        'error_slip_verification_not_found' => __('deposit.error_slip_verification_not_found'),
        'pollingHintSlip' => __('deposit.polling_hint'),
    ];

    $truemoneyL10n = [
        'notConfigured' => __('deposit.not_configured'),
        'notConfiguredHelp' => __('deposit.not_configured_help'),
        'authenticationRequired' => __('deposit.authentication_required'),
        'networkError' => __('deposit.network_error'),
        'serviceUnavailable' => __('deposit.service_unavailable'),
        'validationError' => __('deposit.validation_error'),
        'voucherUrlInvalid' => __('deposit.voucher_url_invalid'),
        'redeeming' => __('deposit.redeeming'),
        'submit' => __('deposit.submit_redeem'),
        'creditedTitle' => __('deposit.credited_title'),
        'creditedAmount' => __('deposit.credited_amount'),
        'depositReference' => __('deposit.deposit_reference'),
        'pendingReview' => __('deposit.pending_review'),
        'error_truemoney_not_configured' => __('deposit.error_truemoney_not_configured'),
        'error_truemoney_api_not_acknowledged' => __('deposit.error_truemoney_api_not_acknowledged'),
        'error_truemoney_voucher_invalid' => __('deposit.error_truemoney_voucher_invalid'),
        'error_truemoney_voucher_already_processed' => __('deposit.error_truemoney_voucher_already_processed'),
        'error_truemoney_daily_count_limit' => __('deposit.error_truemoney_daily_count_limit'),
        'error_truemoney_daily_amount_limit' => __('deposit.error_truemoney_daily_amount_limit'),
        'error_truemoney_fraud_denied' => __('deposit.error_truemoney_fraud_denied'),
        'error_truemoney_outcome_unknown' => __('deposit.error_truemoney_outcome_unknown'),
        'error_truemoney_credit_unconfirmed' => __('deposit.error_truemoney_credit_unconfirmed'),
        'error_truemoney_review_required' => __('deposit.error_truemoney_review_required'),
        'error_truemoney_processing_failed' => __('deposit.error_truemoney_processing_failed'),
        'error_unauthenticated' => __('deposit.error_unauthenticated'),
    ];
    $slipL10n = [
        'notConfigured' => __('deposit.not_configured'),
        'notConfiguredHelp' => __('deposit.not_configured_help'),
        'intentNotConfigured' => __('deposit.intent_not_configured'),
        'intentLoading' => __('deposit.intent_loading'),
        'intentFailed' => __('deposit.intent_failed'),
        'authenticationRequired' => __('deposit.authentication_required'),
        'networkError' => __('deposit.network_error'),
        'serviceUnavailable' => __('deposit.service_unavailable'),
        'validationError' => __('deposit.validation_error'),
        'imageInvalidType' => __('deposit.image_invalid_type'),
        'imageTooLarge' => __('deposit.image_too_large'),
        'qrInvalid' => __('deposit.qr_invalid'),
        'previewAlt' => __('deposit.preview_alt'),
        'submitting' => __('deposit.submitting'),
        'submit' => __('deposit.submit_slip'),
        'statusLabel' => __('deposit.slip_status_label'),
        'status_pending' => __('deposit.slip_status_pending'),
        'status_verified' => __('deposit.slip_status_verified'),
        'status_credited' => __('deposit.slip_status_credited'),
        'status_needs_review' => __('deposit.slip_status_needs_review'),
        'status_rejected' => __('deposit.slip_status_rejected'),
        'status_duplicate' => __('deposit.slip_status_duplicate'),
        'status_expired' => __('deposit.slip_status_expired'),
        'reasonPrefix' => __('deposit.reason_prefix'),
        'reason_unknown' => __('deposit.reason_unknown'),
        'reason_upload_scan_not_clean' => __('deposit.reason_upload_scan_not_clean'),
        'reason_quarantined_file_unavailable' => __('deposit.reason_quarantined_file_unavailable'),
        'reason_provider_unreachable' => __('deposit.reason_provider_unreachable'),
        'reason_provider_not_configured' => __('deposit.reason_provider_not_configured'),
        'reason_provider_rejected_slip' => __('deposit.reason_provider_rejected_slip'),
        'reason_provider_error' => __('deposit.reason_provider_error'),
        'reason_provider_invalid' => __('deposit.reason_provider_invalid'),
        'reason_duplicate' => __('deposit.reason_duplicate'),
        'reason_provider_duplicate' => __('deposit.reason_provider_duplicate'),
        'reason_trans_ref_already_seen' => __('deposit.reason_trans_ref_already_seen'),
        'reason_amount_mismatch' => __('deposit.reason_amount_mismatch'),
        'reason_provider_amount_invalid' => __('deposit.reason_provider_amount_invalid'),
        'reason_currency_mismatch' => __('deposit.reason_currency_mismatch'),
        'reason_receiver_mismatch' => __('deposit.reason_receiver_mismatch'),
        'reason_expired' => __('deposit.reason_expired'),
        'reason_slip_expired' => __('deposit.reason_slip_expired'),
        'reason_future_dated' => __('deposit.reason_future_dated'),
        'reason_fraud_denied' => __('deposit.reason_fraud_denied'),
        'reason_deposit_mismatch' => __('deposit.reason_deposit_mismatch'),
        'reason_fraud_check_unavailable' => __('deposit.reason_fraud_check_unavailable'),
        'reason_manual_review_required' => __('deposit.reason_manual_review_required'),
        'reason_matching_pending_deposit_not_found' => __('deposit.reason_matching_pending_deposit_not_found'),
        'reason_automatic_credit_pending' => __('deposit.reason_automatic_credit_pending'),
        'reason_deposit_completion_failed' => __('deposit.reason_deposit_completion_failed'),
        'error_slip_verification_not_configured' => __('deposit.error_slip_verification_not_configured'),
        'error_slip_invalid' => __('deposit.error_slip_invalid'),
        'error_slip_credit_unconfirmed' => __('deposit.error_slip_credit_unconfirmed'),
        'error_slip_verification_failed' => __('deposit.error_slip_verification_failed'),
        'error_deposit_not_found' => __('deposit.error_deposit_not_found'),
        'error_slip_verification_not_found' => __('deposit.error_slip_verification_not_found'),
        'pollingHint' => __('deposit.polling_hint'),
    ];
@endphp
@php
    $availableMethodIds = array_keys(is_array($methods) ? $methods : []);
    $depositLaneStates = [
        'promptpay' => in_array('promptpay', $availableMethodIds, true),
        'truemoney' => (bool) config('truemoney.enabled', false),
        'bank_transfer' => in_array('bank_transfer', $availableMethodIds, true),
    ];
    $depositLaneOrder = ['promptpay', 'truemoney', 'bank_transfer'];
    $depositFirstLane = null;
    foreach ($depositLaneOrder as $_lane) {
        if ($depositLaneStates[$_lane] ?? false) {
            $depositFirstLane = $_lane;
            break;
        }
    }
@endphp

<section id="deposit-lanes" data-deposit-lanes class="tl-lanes"
    data-methods-url="{{ route('api.v1.deposit.methods') }}"
    data-deposit-url="{{ route('api.v1.wallet.deposit') }}"
    data-status-url="{{ route('api.v1.deposit.status', ['depositId' => '__DEPOSIT_ID__']) }}"
    data-poll-interval-ms="6000"
    data-currency="THB"
    aria-labelledby="deposit-lanes-title">
    <h2 id="deposit-lanes-title" class="text-lg font-bold text-white">{{ __('deposit.lanes_title') }}</h2>
    <p class="tl-help">{{ __('deposit.lanes_lead') }}</p>
    <p class="tl-status" data-lanes-global-notice role="status" aria-live="polite">{{ __('deposit.methods_loading') }}</p>

    <div class="tl-lane-tabs" role="tablist" aria-label="{{ __('deposit.lanes_title') }}">
        @if ($depositLaneStates['promptpay'])
        <button type="button" class="tl-lane-tab" role="tab" id="lane-tab-promptpay" aria-controls="lane-panel-promptpay" aria-selected="{{ $depositFirstLane === 'promptpay' ? 'true' : 'false' }}" tabindex="{{ $depositFirstLane === 'promptpay' ? '0' : '-1' }}" data-lane-tab="promptpay" data-lane-state="AVAILABLE">{{ __('deposit.tab_promptpay') }}</button>
        @endif
        @if ($depositLaneStates['truemoney'])
        <button type="button" class="tl-lane-tab" role="tab" id="lane-tab-truemoney" aria-controls="lane-panel-truemoney" aria-selected="{{ $depositFirstLane === 'truemoney' ? 'true' : 'false' }}" tabindex="{{ $depositFirstLane === 'truemoney' ? '0' : '-1' }}" data-lane-tab="truemoney" data-lane-state="AVAILABLE">{{ __('deposit.tab_truemoney') }}</button>
        @endif
        @if ($depositLaneStates['bank_transfer'])
        <button type="button" class="tl-lane-tab" role="tab" id="lane-tab-bank-transfer" aria-controls="lane-panel-bank-transfer" aria-selected="{{ $depositFirstLane === 'bank_transfer' ? 'true' : 'false' }}" tabindex="{{ $depositFirstLane === 'bank_transfer' ? '0' : '-1' }}" data-lane-tab="bank_transfer" data-lane-state="AVAILABLE">{{ __('deposit.tab_bank_transfer') }}</button>
        @endif
    </div>

    @if ($depositLaneStates['promptpay'])
    <div class="tl-lane-panel" role="tabpanel" id="lane-panel-promptpay" aria-labelledby="lane-tab-promptpay" data-lane-panel="promptpay" data-lane-state="AVAILABLE" {{ $depositFirstLane === 'promptpay' ? '' : 'hidden inert' }}>
        <h3 class="text-base font-bold text-white">{{ __('deposit.promptpay_title') }}</h3>
        <p class="tl-help">{{ __('deposit.promptpay_lead') }}</p>
        <p class="tl-lane-notice" data-lane-notice role="status" aria-live="polite" hidden></p>
        <form class="tl-lane-form" data-lane-form method="post" action="#" novalidate>
            @csrf
            <div class="tl-field">
                <label for="promptpay-amount">{{ __('deposit.amount_label') }}</label>
                <input class="tl-input" id="promptpay-amount" name="amount" type="text" inputmode="decimal" autocomplete="off" pattern="[0-9]{1,13}([.][0-9]{1,2})?" maxlength="16" required placeholder="100.00" data-promptpay-amount>
                <span class="tl-help">{{ __('deposit.amount_hint') }}</span>
            </div>
            <button class="tl-button" type="submit" data-promptpay-submit>{{ __('deposit.promptpay_submit') }}</button>
        </form>
        <div class="tl-result" data-promptpay-result hidden aria-live="polite">
            <div class="tl-qr-frame">
                <canvas data-promptpay-qr width="232" height="232" role="img" aria-label="{{ __('deposit.qr_alt') }}" hidden></canvas>
            </div>
            <dl class="tl-key-values">
                <dt>{{ __('deposit.amount_label') }}</dt><dd data-promptpay-amount-display></dd>
                <dt>{{ __('deposit.reference_label') }}</dt><dd data-promptpay-reference></dd>
                <dt>{{ __('deposit.expires_label') }}</dt><dd><time data-promptpay-expires-at></time> <span data-promptpay-countdown aria-live="off"></span></dd>
                <dt>{{ __('deposit.status_label') }}</dt><dd><span class="tl-status-badge" data-promptpay-status-label role="status"></span></dd>
            </dl>
            <div class="tl-button-row">
                <button class="tl-button tl-button--ghost" type="button" data-promptpay-copy>{{ __('deposit.copy_reference') }}</button>
            </div>
            <p class="tl-status" data-promptpay-status role="status" aria-live="polite"></p>
        </div>
    </div>
    @endif

    @if ($depositLaneStates['truemoney'])
    <div class="tl-lane-panel" role="tabpanel" id="lane-panel-truemoney" aria-labelledby="lane-tab-truemoney" data-lane-panel="truemoney" data-lane-state="AVAILABLE" {{ $depositFirstLane === 'truemoney' ? '' : 'hidden inert' }}>
        <div id="truemoney-redeem"
            data-redeem-url="{{ route('api.v1.deposit.truemoney.redeem') }}"
            data-voucher-host="{{ config('truemoney.allowed_host') }}"
            data-currency="THB">
            <h3 class="text-base font-bold text-white">{{ __('deposit.truemoney_title') }}</h3>
            <p class="tl-help">{{ __('deposit.truemoney_lead') }}</p>
            <p class="tl-lane-notice" data-lane-notice role="status" aria-live="polite" hidden></p>
            <form class="tl-lane-form" data-lane-form method="post" action="#" novalidate>
                @csrf
                <div class="tl-field">
                    <label for="truemoney-voucher-url">{{ __('deposit.voucher_url_label') }}</label>
                    <input class="tl-input" id="truemoney-voucher-url" name="voucher_url" type="url" inputmode="url" autocomplete="off" maxlength="200" required placeholder="https://gift.truemoney.com/campaign/?v=..." data-truemoney-url aria-describedby="truemoney-voucher-help">
                    <span class="tl-help" id="truemoney-voucher-help">{{ __('deposit.voucher_url_help') }}</span>
                </div>
                <button class="tl-button" type="submit" data-truemoney-submit>{{ __('deposit.submit_redeem') }}</button>
            </form>
            <p class="tl-status" data-truemoney-status role="status" aria-live="polite"></p>
            <div class="tl-result" data-truemoney-result hidden>
                <h4 class="font-bold text-emerald-300">{{ __('deposit.credited_title') }}</h4>
                <dl class="tl-key-values">
                    <dt>{{ __('deposit.credited_amount') }}</dt><dd data-truemoney-amount></dd>
                    <dt>{{ __('deposit.deposit_reference') }}</dt><dd data-truemoney-reference></dd>
                </dl>
            </div>
        </div>
    </div>
    @endif

    @if ($depositLaneStates['bank_transfer'])
    <div class="tl-lane-panel" role="tabpanel" id="lane-panel-bank-transfer" aria-labelledby="lane-tab-bank-transfer" data-lane-panel="bank_transfer" data-lane-state="AVAILABLE" {{ $depositFirstLane === 'bank_transfer' ? '' : 'hidden inert' }}>
        <div id="slip-upload"
            data-intent-url="{{ route('api.v1.deposit.bank-transfer.intent') }}"
            data-verify-url="{{ route('api.v1.deposit.slip.verify') }}"
            data-show-url="{{ route('api.v1.deposit.slip.show', ['verification' => '__VERIFICATION_ID__']) }}"
            data-poll-interval-ms="5000"
            data-currency="THB">
            <h3 class="text-base font-bold text-white">{{ __('deposit.slip_title') }}</h3>
            <p class="tl-help">{{ __('deposit.slip_lead') }}</p>
            <p class="tl-status" data-slip-bank-notice role="status" aria-live="polite">{{ __('deposit.intent_loading') }}</p>
            <div class="tl-result" data-slip-bank-details hidden>
                <h4 class="font-bold text-white">{{ __('deposit.bank_details_title') }}</h4>
                <dl class="tl-key-values">
                    <div data-slip-detail hidden><dt>{{ __('deposit.bank_name_label') }}</dt><dd data-slip-bank-name></dd></div>
                    <div data-slip-detail hidden><dt>{{ __('deposit.account_name_label') }}</dt><dd data-slip-account-name></dd></div>
                    <div data-slip-detail hidden><dt>{{ __('deposit.account_number_label') }}</dt><dd data-slip-account-number></dd></div>
                    <div data-slip-detail hidden><dt>{{ __('deposit.reference_label') }}</dt><dd data-slip-reference></dd></div>
                    <div data-slip-detail hidden><dt>{{ __('deposit.instructions_label') }}</dt><dd data-slip-instructions></dd></div>
                </dl>
            </div>
            <p class="tl-lane-notice" data-lane-notice role="status" aria-live="polite" hidden></p>
            <form class="tl-lane-form" data-lane-form method="post" action="#" enctype="multipart/form-data" novalidate>
                @csrf
                <div class="tl-field">
                    <label for="slip-amount">{{ __('deposit.slip_amount_label') }}</label>
                    <input class="tl-input" id="slip-amount" name="amount" type="text" inputmode="decimal" autocomplete="off" pattern="[0-9]{1,12}([.][0-9]{1,2})?" maxlength="16" required placeholder="100.00" data-slip-amount>
                </div>
                <div class="tl-field">
                    <label for="slip-deposit-reference">{{ __('deposit.deposit_reference_label') }}</label>
                    <input class="tl-input" id="slip-deposit-reference" name="deposit_reference" type="text" autocomplete="off" maxlength="64" data-slip-deposit-reference>
                    <span class="tl-help">{{ __('deposit.deposit_reference_help') }}</span>
                </div>
                <fieldset class="tl-field">
                    <legend class="tl-field-label">{{ __('deposit.slip_mode_label') }}</legend>
                    <label><input type="radio" name="slip_mode" value="image" data-slip-mode="image" checked> {{ __('deposit.slip_mode_image') }}</label>
                    <label><input type="radio" name="slip_mode" value="qr" data-slip-mode="qr"> {{ __('deposit.slip_mode_qr') }}</label>
                </fieldset>
                <div class="tl-field" data-slip-image-field>
                    <label for="slip-image">{{ __('deposit.slip_image_label') }}</label>
                    <input class="tl-input" id="slip-image" name="slip_image" type="file" accept="image/jpeg,image/png" data-slip-image>
                    <span class="tl-help">{{ __('deposit.slip_image_help') }}</span>
                    <img class="tl-slip-preview" data-slip-preview alt="{{ __('deposit.preview_alt') }}" hidden>
                </div>
                <div class="tl-field" data-slip-qr-field hidden>
                    <label for="slip-qr-payload">{{ __('deposit.qr_payload_label') }}</label>
                    <textarea class="tl-input" id="slip-qr-payload" name="qr_payload" rows="4" maxlength="1000" data-slip-qr-payload placeholder="{{ __('deposit.qr_payload_placeholder') }}"></textarea>
                </div>
                <button class="tl-button" type="submit" data-slip-submit>{{ __('deposit.submit_slip') }}</button>
            </form>
            <p class="tl-status" data-slip-status role="status" aria-live="polite"></p>
            <div class="tl-result" data-slip-result hidden>
                <h4 class="font-bold text-white">{{ __('deposit.slip_status_title') }}</h4>
                <dl class="tl-key-values">
                    <dt>{{ __('deposit.status_label') }}</dt><dd><span class="tl-status-badge" data-slip-status-label role="status"></span></dd>
                    <dt>{{ __('deposit.amount_label') }}</dt><dd data-slip-amount-display></dd>
                </dl>
                <p class="tl-help" data-slip-reason hidden></p>
            </div>
        </div>
    </div>
    @endif

    <noscript>
        <p class="tl-status" role="status">{{ __('deposit.noscript_notice') }}</p>
    </noscript>

    @if ($depositLaneStates['promptpay'])
    <script type="application/json" id="deposit-l10n">@json($depositL10n)</script>
    @endif
    @if ($depositLaneStates['truemoney'])
    <script type="application/json" id="truemoney-l10n">@json($truemoneyL10n)</script>
    @endif
    @if ($depositLaneStates['bank_transfer'])
    <script type="application/json" id="slip-l10n">@json($slipL10n)</script>
    @endif
</section>

    @if ($recentDeposits->isNotEmpty())
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <h2 class="text-base font-bold text-white mb-4">{{ __('player.recent_deposits') }}</h2>
            <div class="space-y-2">
                    @foreach ($recentDeposits as $deposit)
                    @php
                        $statusKey = 'status_'.$deposit->status->value;
                        $statusLabel = trans()->has('player.'.$statusKey) ? __('player.'.$statusKey) : __('player.not_configured');
                        $depositCurrency = $deposit->currency instanceof \App\Enums\Currency ? $deposit->currency : null;
                        $depositAmountLabel = $depositCurrency instanceof \App\Enums\Currency
                            ? \App\Services\Finance\Money::of((string) $deposit->amount, $depositCurrency)->format()
                            : __('player.not_configured');
                    @endphp
                    <div class="flex items-center justify-between bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-sm">
                        <a class="font-mono text-emerald-300 hover:underline" href="{{ route('player.deposit.status', ['deposit' => $deposit->reference_number]) }}">{{ $deposit->reference_number }}</a>
                        <span class="font-mono font-bold text-white">{{ $depositAmountLabel }}</span>
                        <span class="text-xs font-bold uppercase tracking-wider px-2 py-1 rounded-full border
                            {{ $deposit->status->value === 'confirmed' || $deposit->status->value === 'approved' ? 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10' : 'border-amber-500/30 text-amber-400 bg-amber-500/10' }}">
                            {{ $statusLabel }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
