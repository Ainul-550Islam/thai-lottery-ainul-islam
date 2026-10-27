@props([
    'product' => [],
    'currency' => 'THB',
    'showPeriod' => true,
])

{{--
    One product's published discount rules (PROMPT 4).

    EVERY VALUE IS A SERVER STRING. discount_value, minimum_amount and
    maximum_discount arrive as exact decimal strings from
    App\Services\Pricing\LottoDiscountService and are printed verbatim: no
    number_format, no float cast, no client-side arithmetic. The browser is
    never the source of a price.

    IMMUTABLE PRODUCTS. A product flagged immutable_price (GLO L6 / N3) can
    never carry a rule row - the service refuses to build one - so this
    component renders its fixed-price notice instead of a table.
--}}

@php
    $productKey = (string) ($product['product'] ?? '');
    $rules = (array) ($product['rules'] ?? []);
    $immutable = (bool) ($product['immutable_price'] ?? false);
    $labelKey = (string) ($product['label_key'] ?? '');
@endphp

<section
    class="pd-product"
    data-pd-product="{{ $productKey }}"
    data-pd-immutable="{{ $immutable ? 'true' : 'false' }}"
>
    <header class="pd-product__header">
        <h3 class="pd-product__title">
            {{ $labelKey !== '' && trans()->has($labelKey) ? trans($labelKey) : $productKey }}
        </h3>

        @if ($immutable)
            <span class="pd-badge pd-badge--immutable">{{ trans('prize_discount.immutable_badge') }}</span>
        @endif
    </header>

    @if ($immutable)
        <p class="pd-disclaimer pd-disclaimer--strong">{{ trans('prize_discount.immutable_notice') }}</p>
    @elseif ($rules === [])
        <p class="pd-muted">{{ trans('prize_discount.discount_no_rules') }}</p>
    @else
        <table class="pd-table">
            <caption class="pd-sr-only">
                {{ $labelKey !== '' && trans()->has($labelKey) ? trans($labelKey) : $productKey }}
            </caption>
            <thead>
                <tr>
                    <th scope="col">{{ trans('prize_discount.discount_table_rule') }}</th>
                    <th scope="col">{{ trans('prize_discount.discount_table_market') }}</th>
                    <th scope="col">{{ trans('prize_discount.discount_table_type') }}</th>
                    <th scope="col">{{ trans('prize_discount.discount_table_value') }}</th>
                    <th scope="col">{{ trans('prize_discount.discount_table_minimum') }}</th>
                    <th scope="col">{{ trans('prize_discount.discount_table_maximum') }}</th>
                    @if ($showPeriod)
                        <th scope="col">{{ trans('prize_discount.discount_table_period') }}</th>
                    @endif
                    <th scope="col">{{ trans('prize_discount.discount_table_stackable') }}</th>
                    <th scope="col">{{ trans('prize_discount.discount_table_version') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rules as $rule)
                    @php
                        $descriptionKey = (string) ($rule['description_key'] ?? '');
                        $type = (string) ($rule['discount_type'] ?? '');
                        $market = (string) ($rule['market'] ?? '*');
                        $from = $rule['effective_from'] ?? null;
                        $to = $rule['effective_to'] ?? null;
                    @endphp
                    <tr data-pd-rule="{{ $rule['rule_id'] ?? '' }}">
                        <th scope="row">
                            <span class="pd-mono">{{ $rule['rule_id'] ?? '' }}</span>
                            @if ($descriptionKey !== '' && trans()->has($descriptionKey))
                                <span class="pd-rule__description">{{ trans($descriptionKey) }}</span>
                            @endif
                        </th>
                        <td>{{ $market === '*' ? trans('prize_discount.discount_market_any') : $market }}</td>
                        <td>
                            {{ $type === 'percentage'
                                ? trans('prize_discount.discount_type_percentage')
                                : trans('prize_discount.discount_type_fixed') }}
                        </td>
                        <td class="pd-mono" data-pd-rule-value>
                            {{ $rule['discount_value'] }}@if ($type === 'percentage'){{ trans('prize_discount.percent_suffix') }}@else {{ $currency }}@endif
                        </td>
                        <td class="pd-mono">
                            @if ($rule['minimum_amount'] === null)
                                {{ trans('prize_discount.discount_no_limit') }}
                            @else
                                {{ $rule['minimum_amount'] }} {{ $currency }}
                            @endif
                        </td>
                        <td class="pd-mono">
                            @if ($rule['maximum_discount'] === null)
                                {{ trans('prize_discount.discount_no_limit') }}
                            @else
                                {{ $rule['maximum_discount'] }} {{ $currency }}
                            @endif
                        </td>
                        @if ($showPeriod)
                            <td>
                                @if ($from === null && $to === null)
                                    {{ trans('prize_discount.discount_period_open') }}
                                @elseif ($to === null)
                                    {{ trans('prize_discount.discount_period_from', ['from' => $from]) }}
                                @else
                                    {{ trans('prize_discount.discount_period_between', ['from' => $from, 'to' => $to]) }}
                                @endif
                            </td>
                        @endif
                        <td>
                            {{ ($rule['stackable'] ?? true)
                                ? trans('prize_discount.discount_stackable_yes')
                                : trans('prize_discount.discount_stackable_no') }}
                        </td>
                        <td class="pd-mono">{{ $rule['rule_version'] ?? '1' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
