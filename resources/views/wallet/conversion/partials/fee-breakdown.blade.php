{{--
    The published price, on the page where it is about to be charged.

    WHY THIS IS SHOWN AND NOT JUST LINKED
    The fee schedule lives at /fees. Linking to it would be defensible — one
    source of truth, no duplication — but a member is about to pay this number
    and should not have to leave the page to check it. The figures below come
    from the same calculator the engine charges from, so the table is not a copy
    of the schedule: it IS the schedule, rendered where it applies.

    WHAT HAPPENS WHEN NO RULE IS PUBLISHED
    `isConfigured` false means there is no live, published rule for this
    direction. The table then says so plainly and the engine refuses to convert.
    Showing "0.00" or "—" would be worse than an explicit gap: a member reading a
    zero fee would expect a free conversion and be refused, with no way to tell
    which of the two screens was lying.
--}}
<section class="wc-card" aria-labelledby="wc-fee-heading">
    <h2 class="wc-card__title" id="wc-fee-heading">{{ __('wallet_conversion.fee_heading') }}</h2>

    @php
        // The worked example is not typed into this file. It is the live
        // calculator's own answer for 100.00 in this direction, asked for here
        // and now — so if the tariff changes, this row changes with it, and it
        // can never disagree with what the member is about to be charged.
        $example = \App\Services\Finance\Money::of('100.00', $currency);
        $exampleBreakdown = app(\App\Services\Wallet\Conversion\ConversionFeeCalculator::class)
            ->breakdownFor($direction, '100.00', $currency->value);
    @endphp

    @if (! $feeBreakdown->isConfigured)
        <p class="wc-notice wc-notice--warn" role="status">
            {{ __('wallet_conversion.fee_not_published') }}
        </p>
    @else
        <table class="wc-table wc-table--rates">
            <caption class="wc-table__caption">{{ __('wallet_conversion.fee_caption', ['direction' => (method_exists($direction, 'labelKey') && \Illuminate\Support\Facades\Lang::has($direction->labelKey())) ? __($direction->labelKey()) : $direction->label()]) }}</caption>
            <tbody>
                <tr>
                    <th scope="row">{{ __('wallet_conversion.fee_rate_row') }}</th>
                    <td>{{ $feeBreakdown->percentage }}%</td>
                </tr>
                <tr>
                    <th scope="row">{{ __('wallet_conversion.fee_on_example', ['amount' => $example->format()]) }}</th>
                    <td class="wc-money">
                        {{ \App\Services\Finance\Money::of($exampleBreakdown->feeAmount, $currency)->format() }}
                        <span class="wc-rate">→ {{ \App\Services\Finance\Money::of($exampleBreakdown->netAmount, $currency)->format() }}</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">{{ __('wallet_conversion.fee_bearer_row') }}</th>
                    <td>{{ (method_exists($feeBreakdown->bearer, 'labelKey') && \Illuminate\Support\Facades\Lang::has($feeBreakdown->bearer->labelKey())) ? __($feeBreakdown->bearer->labelKey()) : $feeBreakdown->bearer->label() }}</td>
                </tr>
                <tr>
                    <th scope="row">{{ __('wallet_conversion.fee_rule_row') }}</th>
                    <td>
                        <code>{{ $feeBreakdown->ruleKey }}</code>
                        · v{{ $feeBreakdown->ruleVersion }}
                        @if ($feeBreakdown->effectiveFrom !== null)
                            · {{ $feeBreakdown->effectiveFrom }}
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="wc-card__hint">{{ __('wallet_conversion.fee_parity_note') }}</p>
    @endif
</section>
