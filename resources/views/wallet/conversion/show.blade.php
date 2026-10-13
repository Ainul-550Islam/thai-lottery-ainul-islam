@extends('layouts.app')

{{--
    One conversion, in full.

    WHY THIS PAGE EXISTS AT ALL
    A wallet transaction list shows "conversion — 1.00 THB". That is not enough
    to resolve a support ticket: the member wants to know what they asked for,
    what they were charged, what rate was in force, how both balances moved, and
    what the platform recorded at the time. All of that exists in the database
    (the conversion row, its fee rule version, its audit rows) and none of it is
    visible anywhere else.

    WHY THE TIMELINE IS THE POINT
    The audit rows are written by the engine as it works — requested, quoted,
    completed, refused, reversed — and they cannot be edited from the panel. When
    a member says "my money disappeared at 14:02", the timeline is the answer:
    each row carries the moment, the status it moved from and to, and the error
    code if there was one. Rendering it here means support does not have to open
    a database client to answer a question the member is entitled to.
--}}

@section('title', __('wallet_conversion.detail_title'))
@section('meta_robots', 'noindex,nofollow')

@push('styles')
    @vite('resources/css/wallet-conversion.css')
@endpush

@section('content')
@php
    $currency = $conversion->currency instanceof \App\Enums\Currency
        ? $conversion->currency
        : \App\Enums\Currency::tryFrom((string) $conversion->currency);

    $money = static function (?string $amount) use ($currency): string {
        if ($amount === null || $amount === '' || ! $currency instanceof \App\Enums\Currency) {
            return $amount ?? '—';
        }

        return \App\Services\Finance\Money::of($amount, $currency)->format();
    };

    $label = static fn (object $enum): string => \Illuminate\Support\Facades\Lang::has($enum->labelKey())
        ? __($enum->labelKey())
        : $enum->label();

    $reference = (string) $conversion->reference;
    $statusValue = $conversion->status?->value ?? 'unknown';
@endphp

<main class="wc-shell" role="main">
    <header class="wc-header">
        <div>
            <h1 class="wc-title">{{ __('wallet_conversion.detail_title') }}</h1>
            <p class="wc-lead">
                <code class="wc-ref">{{ $reference }}</code>
                <span class="wc-badge wc-badge--{{ $statusValue }}">{{ $conversion->status === null ? $statusValue : $label($conversion->status) }}</span>
            </p>
        </div>

        <a class="wc-back" href="{{ route('player.wallet.conversion') }}">{{ __('wallet_conversion.back_to_conversion') }}</a>
    </header>

    @if (session('status'))
        <div class="wc-flash wc-flash--ok" role="status">{{ session('status') }}</div>
    @endif

    <div class="wc-grid">
        <section class="wc-card" aria-labelledby="wc-movement-heading">
            <h2 class="wc-card__title" id="wc-movement-heading">{{ __('wallet_conversion.detail_movement') }}</h2>

            <table class="wc-table">
                <tbody>
                    <tr>
                        <th scope="row">{{ __('wallet_conversion.field_direction') }}</th>
                        <td>{{ $conversion->direction === null ? '—' : $label($conversion->direction) }}</td>
                    </tr>
                    <tr>
                        <th scope="row">{{ __('wallet_conversion.field_amount_in') }}</th>
                        <td class="wc-money">{{ $money((string) $conversion->amount_in) }}</td>
                    </tr>
                    <tr>
                        <th scope="row">{{ __('wallet_conversion.field_fee') }}</th>
                        <td class="wc-money wc-money--fee">
                            {{ $money((string) $conversion->fee_amount) }}
                            <span class="wc-rate">
                                {{ __('wallet_conversion.field_fee_rate') }}
                                {{ \App\ValueObjects\ConversionRate::fromString((string) $conversion->fee_rate, 6)->percentage(2) }}%
                                · {{ __('wallet_conversion.field_rule_version') }} {{ $conversion->fee_rule_version }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">{{ __('wallet_conversion.field_amount_out') }}</th>
                        <td class="wc-money wc-money--in">{{ $money((string) $conversion->amount_out) }}</td>
                    </tr>
                    <tr>
                        <th scope="row">{{ __('wallet_conversion.field_channel') }}</th>
                        <td>{{ $conversion->channel?->value ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="wc-card" aria-labelledby="wc-positions-heading">
            <h2 class="wc-card__title" id="wc-positions-heading">{{ __('wallet_conversion.detail_positions') }}</h2>

            <dl class="wc-balances">
                <div class="wc-balances__row">
                    <dt>{{ $conversion->from_wallet_type }} · {{ __('wallet_conversion.after') }}</dt>
                    <dd class="wc-money">{{ $money($conversion->from_balance_after === null ? null : (string) $conversion->from_balance_after) }}</dd>
                </div>
                <div class="wc-balances__row">
                    <dt>{{ $conversion->to_wallet_type }} · {{ __('wallet_conversion.after') }}</dt>
                    <dd class="wc-money">{{ $money($conversion->to_balance_after === null ? null : (string) $conversion->to_balance_after) }}</dd>
                </div>
            </dl>

            <table class="wc-table wc-table--tight">
                <tbody>
                    <tr>
                        <th scope="row">{{ __('wallet_conversion.field_created') }}</th>
                        <td>{{ $conversion->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                    </tr>
                    <tr>
                        <th scope="row">{{ __('wallet_conversion.field_completed') }}</th>
                        <td>{{ $conversion->completed_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                    </tr>
                    @if ($conversion->reversed_at !== null)
                        <tr>
                            <th scope="row">{{ __('wallet_conversion.field_reversed') }}</th>
                            <td>{{ $conversion->reversed_at->format('Y-m-d H:i:s') }}</td>
                        </tr>
                    @endif
                    @if ($conversion->quote_reference !== null)
                        <tr>
                            <th scope="row">{{ __('wallet_conversion.field_quote') }}</th>
                            <td><code>{{ $conversion->quote_reference }}</code></td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </section>
    </div>

    <section class="wc-card" aria-labelledby="wc-timeline-heading">
        <h2 class="wc-card__title" id="wc-timeline-heading">{{ __('wallet_conversion.detail_timeline') }}</h2>

        @if (count($timeline) === 0)
            <p class="wc-empty">{{ __('wallet_conversion.timeline_empty') }}</p>
        @else
            <ol class="wc-timeline">
                @foreach ($timeline as $entry)
                    <li class="wc-timeline__item @if (! empty($entry['error_code'])) wc-timeline__item--error @endif">
                        <span class="wc-timeline__event">{{ $entry['event'] }}</span>
                        <span class="wc-timeline__status">
                            {{ $entry['status_from'] ?? '—' }}
                            <span aria-hidden="true">→</span>
                            {{ $entry['status_to'] ?? '—' }}
                        </span>
                        @if (! empty($entry['error_code']))
                            <code class="wc-timeline__code">{{ $entry['error_code'] }}</code>
                        @endif
                        <time class="wc-timeline__time" datetime="{{ $entry['recorded_at'] }}">
                            {{ $entry['recorded_at'] }}
                        </time>
                    </li>
                @endforeach
            </ol>
        @endif

        <p class="wc-card__hint">{{ __('wallet_conversion.timeline_hint') }}</p>
    </section>
</main>
@endsection
