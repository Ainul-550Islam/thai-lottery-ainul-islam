{{--
    Which way the money is going.

    Rendered as links rather than as a select so each direction has a real URL
    the member can bookmark or share with support: `?direction=win_to_cash`
    lands on a page that is already showing the win-to-cash price, without a
    round trip through JavaScript. The engine is not consulted here; the switch
    only chooses which page the member is reading, and a direction that is
    switched off is shown as a disabled label rather than a live link that would
    bounce them back to a refusal.
--}}
<nav class="wc-switch" aria-label="{{ __('wallet_conversion.direction_switch_label') }}">
    @foreach ($directions as $value => $label)
        @php
            $isCurrent = $value === $direction->value;
            $configPath = 'conversions.directions.'.$value.'.enabled';
            $isAvailable = (bool) config($configPath, true);
        @endphp

        @if ($isCurrent)
            <span class="wc-switch__item wc-switch__item--current" aria-current="page">{{ $label }}</span>
        @elseif ($isAvailable)
            <a class="wc-switch__item" href="{{ route('player.wallet.conversion', ['direction' => $value]) }}">{{ $label }}</a>
        @else
            <span class="wc-switch__item wc-switch__item--disabled" title="{{ __('wallet_conversion.direction_disabled') }}">{{ $label }}</span>
        @endif
    @endforeach
</nav>
