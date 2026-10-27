{{--
    What the member sees when the lane is closed.

    TWO DIFFERENT CLOSURES, TWO DIFFERENT MESSAGES
    A direction switch and the master switch are not the same event. The master
    switch means the feature is off platform-wide — an incident, a migration, a
    regulator's question — and nobody can convert in either direction. A
    direction switch means only one way is closed: operators routinely stop money
    entering the win wallet while leaving the exit lane open, precisely so members
    are never trapped holding funds they cannot withdraw.

    Saying "conversions are unavailable" for both would be a lie in the second
    case, and the member would not know their winnings are still mobile. The
    message below names which of the two it is.

    THE FORM IS REMOVED, NOT DISABLED
    A form with a greyed-out button invites an argument with the browser. Removing
    it and saying why is the honest version.
--}}
<section class="wc-card wc-card--notice" aria-labelledby="wc-closed-heading">
    <h2 class="wc-card__title" id="wc-closed-heading">
        @if (! $conversionEnabled)
            {{ __('wallet_conversion.closed_master_title') }}
        @else
            {{ __('wallet_conversion.closed_direction_title', ['direction' => \Illuminate\Support\Facades\Lang::has($direction->labelKey()) ? __($direction->labelKey()) : $direction->label()]) }}
        @endif
    </h2>

    <p class="wc-notice wc-notice--warn" role="status">
        @if (! $conversionEnabled)
            {{ __('wallet_conversion.closed_master_body') }}
        @else
            {{ __('wallet_conversion.closed_direction_body') }}
        @endif
    </p>

    <p class="wc-card__hint">{{ __('wallet_conversion.closed_schedule_note') }}</p>
</section>
