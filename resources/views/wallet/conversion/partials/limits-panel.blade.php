{{--
    What the member is allowed to move, and how much room is left today.

    WHY THIS IS NOT A FOOTNOTE
    Every refusal this lane can produce for an ordinary member is a limit: too
    small, too large, over the daily ceiling, over the monthly ceiling. Each one
    arrives as an error code after the member has already typed an amount and
    pressed a button. Showing the numbers first turns four disappointing errors
    into one informed decision.

    WHY THE TOTALS ARE SHOWN AS WELL AS THE REMAINDER
    "You have 850,000.00 left today" is the actionable number, but a member who
    has been converting for a week needs to see the 150,000.00 already used to
    understand where it went. Both come from `ConversionLimitService`, which
    counts settled conversions the same way the enforcement path does — a panel
    that counted differently would be worse than no panel.
--}}
<section class="wc-card" aria-labelledby="wc-limits-heading">
    <h2 class="wc-card__title" id="wc-limits-heading">{{ __('wallet_conversion.limits_heading') }}</h2>

    <table class="wc-table">
        <caption class="wc-table__caption">{{ __('wallet_conversion.limits_caption') }}</caption>
        <tbody>
            <tr>
                <th scope="row">{{ __('wallet_conversion.limit_per_conversion') }}</th>
                <td class="wc-money">{{ $limits->minimum() }} – {{ $limits->maximum() ?? '∞' }} {{ $currency->value }}</td>
            </tr>

            @if ($limits->hasDailyLimit())
                <tr>
                    <th scope="row">{{ __('wallet_conversion.limit_daily') }}</th>
                    <td class="wc-money">
                        {{ $limits->dailyLimit() }} {{ $currency->value }}
                        <span class="wc-rate">
                            {{ __('wallet_conversion.limit_used', ['used' => $dailyTotal, 'currency' => $currency->value]) }}
                            @if ($dailyRemaining !== null)
                                · {{ __('wallet_conversion.limit_remaining', ['remaining' => $dailyRemaining, 'currency' => $currency->value]) }}
                            @endif
                        </span>
                    </td>
                </tr>
            @endif

            @if ($limits->hasMonthlyLimit())
                <tr>
                    <th scope="row">{{ __('wallet_conversion.limit_monthly') }}</th>
                    <td class="wc-money">
                        {{ $limits->monthlyLimit() }} {{ $currency->value }}
                        <span class="wc-rate">{{ __('wallet_conversion.limit_used', ['used' => $monthlyTotal, 'currency' => $currency->value]) }}</span>
                    </td>
                </tr>
            @endif

            <tr>
                <th scope="row">{{ __('wallet_conversion.limit_currency') }}</th>
                <td>{{ $currency->value }}</td>
            </tr>
        </tbody>
    </table>

    <p class="wc-card__hint">{{ __('wallet_conversion.limits_hint') }}</p>
</section>
