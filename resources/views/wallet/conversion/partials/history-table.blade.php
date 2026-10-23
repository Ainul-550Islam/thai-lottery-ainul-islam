{{--
    What this member has already done.

    WHY THE MEMBER'S OWN HISTORY IS ON THE CONVERSION PAGE
    Three reasons, all of them practical. First, a member who converted yesterday
    and cannot find the fee they were charged will conclude the platform hid it.
    Second, the fee is charged per conversion, so a table is the only way to see
    a month's total cost. Third, the reversal window is short and a member who
    believes they were charged in error needs to see the row to quote it.

    WHY THE TABLE IS NOT PAGINATED WITH JAVASCRIPT
    Laravel's paginator renders plain links. A member on a bad connection can
    still move between pages, and the page works with the script unavailable —
    which is the same reason the fee preview degrades to the server-rendered
    figures rather than to nothing.
--}}
@php
    $label = static fn (object $enum): string => \Illuminate\Support\Facades\Lang::has($enum->labelKey())
        ? __($enum->labelKey())
        : $enum->label();
    $money = static function (?string $amount) use ($currency): string {
        if ($amount === null || $amount === '') {
            return '—';
        }

        return \App\Services\Finance\Money::of($amount, $currency)->format();
    };
@endphp

<section class="wc-card" aria-labelledby="wc-history-heading">
    <h2 class="wc-card__title" id="wc-history-heading">{{ __('wallet_conversion.history_heading') }}</h2>

    @if ($history->total() === 0)
        <p class="wc-empty">{{ __('wallet_conversion.history_empty') }}</p>
    @else
        <div class="wc-table-wrap">
            <table class="wc-table wc-table--history">
                <caption class="wc-table__caption">
                    {{ __('wallet_conversion.history_caption', ['direction' => \Illuminate\Support\Facades\Lang::has($direction->labelKey()) ? __($direction->labelKey()) : $direction->label(), 'total' => $history->total()]) }}
                </caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('wallet_conversion.col_reference') }}</th>
                        <th scope="col">{{ __('wallet_conversion.col_direction') }}</th>
                        <th scope="col">{{ __('wallet_conversion.col_amount_in') }}</th>
                        <th scope="col">{{ __('wallet_conversion.col_fee') }}</th>
                        <th scope="col">{{ __('wallet_conversion.col_amount_out') }}</th>
                        <th scope="col">{{ __('wallet_conversion.col_status') }}</th>
                        <th scope="col">{{ __('wallet_conversion.col_date') }}</th>
                        <th scope="col"><span class="wc-visually-hidden">{{ __('wallet_conversion.col_actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($history as $row)
                        <tr>
                            <td><code class="wc-ref wc-ref--small">{{ $row->reference }}</code></td>
                            <td>{{ $row->direction === null ? '—' : $label($row->direction) }}</td>
                            <td class="wc-money">{{ (string) $row->amount_in }} {{ $row->currency ?? $currency->value }}</td>
                            <td class="wc-money wc-money--fee">{{ (string) $row->fee_amount }} {{ $row->currency ?? $currency->value }}</td>
                            <td class="wc-money wc-money--in">{{ (string) $row->amount_out }} {{ $row->currency ?? $currency->value }}</td>
                            <td>
                                <span class="wc-badge wc-badge--{{ $row->status?->value ?? 'unknown' }}">
                                    {{ $row->status === null ? '—' : $label($row->status) }}
                                </span>
                            </td>
                            <td>{{ $row->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>
                                <a class="wc-link" href="{{ route('player.wallet.conversion.show', ['reference' => $row->reference]) }}">
                                    {{ __('wallet_conversion.action_view') }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="wc-pagination">
            {{ $history->withQueryString()->links() }}
        </div>
    @endif
</section>
