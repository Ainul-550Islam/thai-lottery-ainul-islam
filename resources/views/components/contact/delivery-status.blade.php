@props([
    'status' => null,
])

{{--
    Truthful post-submission status (PROMPT 10, file 16).

    THE ONLY PLACE THAT MAY SAY "SENT". It says it for exactly one state, and
    that state is set only when a configured provider accepted the message.

    Every other outcome says the platform HAS the enquiry and a reply may take
    longer. That is not a softened failure message - it is what actually
    happened, and it is the difference between a visitor who waits and a
    visitor who assumes they were heard when nobody received anything.

    NOTHING FROM THE PROVIDER APPEARS HERE. No host, no exception, no SMTP
    code. The component receives a state string and a reference, and there is
    nothing else in scope for it to leak.

    NOT COLOUR ALONE. Each state renders a word as well as a modifier class,
    so the meaning survives a monochrome screen and a screen reader.
--}}

@php
    $state = is_array($status) ? (string) ($status['state'] ?? '') : '';
    $reference = is_array($status) ? ($status['reference'] ?? null) : null;

    // Closed map. A state not listed here renders nothing rather than
    // guessing, so a new internal state cannot leak a wrong reassurance.
    $tone = match ($state) {
        'sent' => 'success',
        'received', 'received_not_configured', 'received_pending', 'received_delivery_failed' => 'info',
        'rate_limited', 'disabled', 'failed' => 'warning',
        default => null,
    };
@endphp

@if ($tone !== null)
    <div class="ct-status ct-status--{{ $tone }}" role="status" aria-live="polite" data-ct-state="{{ $state }}">
        <p class="ct-status__headline">{{ trans('contact.status.'.$state) }}</p>

        @if ($state !== 'rate_limited' && $state !== 'disabled' && $state !== 'failed')
            <p class="ct-status__detail">{{ trans('contact.status_detail.'.$state) }}</p>
        @endif

        @if (is_string($reference) && $reference !== '')
            <p class="ct-status__reference">
                {{ trans('contact.reference_label') }}
                <span class="ct-status__reference-value">{{ $reference }}</span>
            </p>
        @endif
    </div>
@endif
