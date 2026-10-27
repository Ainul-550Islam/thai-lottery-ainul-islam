{{--
    Reusable discount-rule row/card for the public Discount page.

    Renders ONE game of the National / Bangkok Weekly matrix:
    lottery, game name, D/R win multipliers (or the single variant
    multiplier), and the discount rate — or the NOT_CONFIGURED state.

    NO ARITHMETIC, NO HARDCODED VALUES. Every figure arrives
    pre-resolved from DiscountParityProjectionService::projectRule();
    the partial escapes and prints. The D/R base stake unit is
    displayed exactly as configured, never computed.
--}}
<tr data-game-rule="{{ $rule['game'] }}"
    data-game-lottery="{{ $rule['lottery'] }}"
    data-game-rule-version="{{ $rule['rule_version'] }}"
    data-discount-state="{{ $rule['discount_state'] }}"
>
    <th scope="row">{{ $rule['label'] }}</th>

    @if ($rule['has_modes'])
        <td class="game-rule__mode">
            <span class="game-rule__mult" data-mode="D">
                <span class="game-rule__mode-label">D</span>
                {{ $rule['base_stake'] }} × {{ $rule['d_multiplier'] }}
            </span>
            <span class="game-rule__mult" data-mode="R">
                <span class="game-rule__mode-label">R</span>
                {{ $rule['base_stake'] }} × {{ $rule['r_multiplier'] }}
            </span>
        </td>
    @else
        <td class="game-rule__mode">
            <span class="game-rule__mult">
                {{ $rule['base_stake'] }} × {{ $rule['multiplier'] }}
            </span>
        </td>
    @endif

    <td class="game-rule__discount">
        @if ($rule['discount_state'] === 'NOT_CONFIGURED')
            <span class="game-rule__nc" data-not-configured>NOT_CONFIGURED</span>
        @else
            {{ $rule['discount_display'] }}
        @endif
    </td>
</tr>
