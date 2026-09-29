{{--
    Public grade-tier renderer (reusable partial).

    Renders ONE programme tier of the public grade ladder:
    SL | Grade | 30-day minimum spend | grade icon | Discount (%) |
    an accessible "Discount Of Game" trigger that opens the
    discount-games partial for this tier.

    NO ARITHMETIC EVER HAPPENS HERE. Every figure (SL, threshold,
    percent, icon, entitlement list) arrives pre-resolved from
    DiscountParityProjectionService; the partial only escapes and
    prints. Dynamic output is escaped by default — no {!! !!} anywhere.
--}}
<tr data-grade-tier="{{ $tier['key'] }}"
    data-grade-sl="{{ $tier['sl'] }}"
    data-grade-discount="{{ $tier['discount_display'] }}"
    data-grade-rule-version="{{ $tier['rule_version'] }}"
>
    <th scope="row" class="grade-tier__sl">
        <span aria-hidden="true">{{ $tier['sl'] }}</span>
        <span class="visually-hidden">{{ trans('account_info.sl_label', ['sl' => $tier['sl']]) }}</span>
    </th>
    <td class="grade-tier__name">
        <span class="grade-tier__icon" data-grade-icon="{{ $tier['icon'] }}" aria-hidden="true"></span>
        {{ $tier['name'] }}
    </td>
    <td class="grade-tier__spend">
        {{ $tier['min_spend'] }} {{ $ladder['currency'] ?? '' }}
        <span class="visually-hidden">{{ trans('account_info.min_spend_a11y', ['days' => $ladder['period_days'] ?? 30]) }}</span>
    </td>
    <td class="grade-tier__discount">{{ $tier['discount_display'] }}</td>
    <td class="grade-tier__games">
        <button type="button"
                class="grade-tier__toggle"
                aria-expanded="false"
                aria-controls="grade-games-{{ $tier['key'] }}"
                data-grade-games-toggle
        >
            {{ trans('account_info.discount_of_game') }}
            <span class="visually-hidden">— {{ $tier['name'] }}</span>
        </button>
    </td>
</tr>
