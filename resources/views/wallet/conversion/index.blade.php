@extends('layouts.app')

{{--
    Cash <-> Win conversion: the member's page.

    WHAT THIS PAGE IS FOR
    A member holds cash (withdrawable) and win (playable) balances. They are not
    the same money: one can be taken out of the platform, the other can only be
    staked. Moving between them is therefore a priced operation, and the whole
    job of this page is to state the price before the member agrees to it, then
    show exactly what happened afterwards.

    THE PRICE IS NOT COMPUTED HERE
    Every figure on this page — the fee, the net amount, the limits, the daily
    head-room — comes from the same services the engine charges from. The page
    formats and lays out; it never does arithmetic on money. If it did, the day
    the fee row changed the page would show one number and the engine would take
    another, and the member would be right to call that theft.

    THE LIVE PREVIEW IS A SERVER ROUND-TRIP
    As the member types an amount, `wallet-conversion.js` asks
    `/wallet/convert/preview` for the numbers. That endpoint runs the real
    calculator. The alternative — mirroring the fee formula in JavaScript —
    would be a second implementation of the tariff living in a file nobody tests
    against the first one.

    WHAT HAPPENS WHEN THE LANE IS CLOSED
    The page still renders. The form is replaced by an explanation and the
    published fee schedule is still shown, because a member who bookmarked this
    page deserves to know what it will cost when it returns rather than being
    bounced to a login screen that has nothing to do with their question.
--}}

@section('title', __('wallet_conversion.page_title'))
@section('meta_description', __('wallet_conversion.page_lead'))
@section('meta_robots', 'noindex,nofollow')

@push('styles')
    @vite('resources/css/wallet-conversion.css')
@endpush

@section('content')
@php
    /** @var \App\Enums\WalletConversionDirection $direction */
    /**
     * The form is rendered only when pressing Convert could actually work. A
     * member the policy refuses for a reason no amount can change — a suspension,
     * an unverified identity, an active self-exclusion, a locked wallet — is told
     * the reason instead. The lane switches and the standing block are separate
     * questions and produce separate messages: "the platform has paused this" is
     * not the same sentence as "your account cannot do this today", and a member
     * reading the wrong one will contact support about the wrong thing.
     */
    $laneClosed = ! $conversionEnabled || ! $directionEnabled;
    $laneOpen = ! $laneClosed && $blockedReason === null;

    // Enum labels are stable English identifiers; the lang files carry the
    // member-facing wording. `Lang::has` is checked first so a locale that has
    // not translated a brand-new status shows the English label rather than the
    // raw key `wallet_conversion.status_foo` — a missing translation is a gap to
    // fill, not something to put in front of a member.
    $label = static fn (object $enum): string => (method_exists($enum, 'labelKey') && \Illuminate\Support\Facades\Lang::has($enum->labelKey()))
        ? __($enum->labelKey())
        : $enum->label();
    $money = static function (?string $amount) use ($currency): string {
        if ($amount === null || $amount === '') {
            return '—';
        }

        return \App\Services\Finance\Money::of($amount, $currency)->format();
    };
@endphp

<div class="wc-shell">
    <header class="wc-header">
        <div>
            <h1 class="wc-title">{{ __('wallet_conversion.page_title') }}</h1>
            <p class="wc-lead">{{ __('wallet_conversion.page_lead') }}</p>
        </div>

        <a class="wc-back" href="{{ route('player.wallet') }}">{{ __('wallet_conversion.back_to_wallet') }}</a>
    </header>

    @if (session('status'))
        <div class="wc-flash wc-flash--ok" role="status">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="wc-flash wc-flash--error" role="alert">
            <span>{{ session('error') }}</span>
            @if (session('conversion_error_code'))
                <code class="wc-flash__code">{{ session('conversion_error_code') }}</code>
            @endif
        </div>
    @endif

    @include('wallet.conversion.partials.direction-switch', [
        'direction' => $direction,
        'directions' => $directions,
    ])

    <div class="wc-grid">
        <section class="wc-card wc-card--balances" aria-labelledby="wc-balances-heading">
            <h2 class="wc-card__title" id="wc-balances-heading">{{ __('wallet_conversion.balances_heading') }}</h2>

            <dl class="wc-balances">
                <div class="wc-balances__row @if ($direction->fromType()->value === ($sourceWallet?->type?->value ?? '')) wc-balances__row--active @endif">
                    <dt>{{ $label($direction->fromType()) }}</dt>
                    <dd class="wc-money wc-money--out">
                        {{ $sourceWallet === null ? __('wallet_conversion.not_open') : $money((string) $sourceWallet->balance) }}
                    </dd>
                </div>

                <div class="wc-balances__row @if ($direction->toType()->value === ($destinationWallet?->type?->value ?? '')) wc-balances__row--active @endif">
                    <dt>{{ $label($direction->toType()) }}</dt>
                    <dd class="wc-money wc-money--in">
                        {{ $destinationWallet === null ? __('wallet_conversion.not_open') : $money((string) $destinationWallet->balance) }}
                    </dd>
                </div>
            </dl>

            @if ($summary['total'] > 0)
                <p class="wc-balances__meta">
                    {{ trans_choice('wallet_conversion.summary_line', (int) $summary['completed'], [
                        'completed' => (int) $summary['completed'],
                        'volume' => $money((string) $summary['completed_volume_in']),
                    ]) }}
                </p>
            @endif
        </section>

        @if ($laneOpen)
            @include('wallet.conversion.partials.quote-form', [
                'direction' => $direction,
                'currency' => $currency,
                'feeBreakdown' => $feeBreakdown,
                'limits' => $limits,
                'dailyRemaining' => $dailyRemaining,
                'dailyTotal' => $dailyTotal,
                'monthlyTotal' => $monthlyTotal,
                'quoteTtlSeconds' => $quoteTtlSeconds,
                'sourceWallet' => $sourceWallet,
            ])
        @elseif ($laneClosed)
            @include('wallet.conversion.partials.limitations-notice', [
                'conversionEnabled' => $conversionEnabled,
                'direction' => $direction,
            ])
        @elseif ($blockedReason !== null)
            <section class="wc-card wc-card--notice" aria-labelledby="wc-blocked-heading">
                <h2 class="wc-card__title" id="wc-blocked-heading">{{ __('wallet_conversion.blocked_title') }}</h2>

                <p class="wc-notice wc-notice--warn" role="status">
                    {{ (method_exists($blockedReason, 'messageKey') && \Illuminate\Support\Facades\Lang::has($blockedReason->messageKey())) ? __($blockedReason->messageKey()) : $blockedReason->label() }}
                </p>

                <p class="wc-card__hint">{{ __('wallet_conversion.blocked_hint') }}</p>
            </section>
        @endif

        @if ($showFeeBreakdown)
            @include('wallet.conversion.partials.fee-breakdown', [
                'direction' => $direction,
                'feeBreakdown' => $feeBreakdown,
                'currency' => $currency,
            ])
        @endif
    </div>

    @if ($showLimits)
        @include('wallet.conversion.partials.limits-panel', [
            'limits' => $limits,
            'currency' => $currency,
            'dailyTotal' => $dailyTotal,
            'monthlyTotal' => $monthlyTotal,
            'dailyRemaining' => $dailyRemaining,
        ])
    @endif

    @if (count($openQuotes) > 0)
        <section class="wc-card" aria-labelledby="wc-open-quotes-heading">
            <h2 class="wc-card__title" id="wc-open-quotes-heading">{{ __('wallet_conversion.open_quotes_heading') }}</h2>
            <p class="wc-card__hint">{{ trans_choice('wallet_conversion.open_quotes_hint', count($openQuotes), ['count' => count($openQuotes)]) }}</p>

            <ul class="wc-quotes">
                @foreach ($openQuotes as $quote)
                    @php
                        $quoteCurrency = isset($quote['currency']) && is_string($quote['currency'])
                            ? (\App\Enums\Currency::tryFrom($quote['currency']) ?? $currency)
                            : $currency;
                        $quoteAmountIn = isset($quote['amount_in']) && (string) $quote['amount_in'] !== ''
                            ? \App\Services\Finance\Money::of((string) $quote['amount_in'], $quoteCurrency)->format()
                            : '—';
                        $quoteAmountOut = isset($quote['amount_out']) && (string) $quote['amount_out'] !== ''
                            ? \App\Services\Finance\Money::of((string) $quote['amount_out'], $quoteCurrency)->format()
                            : '—';
                    @endphp
                    <li class="wc-quotes__item">
                        <span class="wc-quotes__amount">{{ $quoteAmountIn }}</span>
                        <span class="wc-quotes__arrow" aria-hidden="true">→</span>
                        <span class="wc-quotes__net">{{ $quoteAmountOut }}</span>
                        <span class="wc-quotes__expiry" data-wc-expiry="{{ $quote['expires_at'] }}">
                            {{ __('wallet_conversion.expires') }} <time datetime="{{ $quote['expires_at'] }}">{{ $quote['expires_at'] }}</time>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @include('wallet.conversion.partials.history-table', [
        'history' => $history,
        'currency' => $currency,
        'direction' => $direction,
    ])
</div>
@endsection

@push('scripts')
    @vite('resources/js/wallet-conversion.js')
@endpush
