@props([
    'support' => [],
    'delivery' => [],
])

{{--
    Public support identity (PROMPT 10, file 15).

    IT RENDERS ONLY WHAT IS CONFIGURED. When no address is set the component
    says support contact is unavailable. It does not fall back to an
    example address, an admin's address or anything borrowed: an address a
    visitor writes to and nobody reads is worse than being told plainly that
    there is no address yet.

    ONE IDENTITY FOR THE WHOLE SITE. The values come from
    App\Services\Support\PublicSupportService, the same service the Home page
    and footer use, so two pages cannot advertise two different support
    addresses.

    NO OPERATOR DETAIL. There is no admin address, no staff name and no
    internal routing here - only the address the platform has chosen to
    publish.
--}}

@php
    // PublicSupportService counts a contact ROUTE as a configured channel,
    // which is right on the Home page - "go to the contact page" is a real
    // answer there. On the contact page it is circular: a link back to this
    // page is not a way to reach support. So this component asks the narrower
    // question, whether there is an address, a team name or stated hours.
    $name = $support['name'] ?? null;
    $email = $support['email'] ?? null;
    $hours = $support['hours'] ?? null;

    $hasChannel = collect([$name, $support['email'] ?? null, $hours])
        ->contains(fn ($value): bool => is_string($value) && trim($value) !== '');

    $status = $hasChannel ? 'CONFIGURED' : 'NOT_CONFIGURED';
    $deliveryConfigured = (bool) ($delivery['destination_configured'] ?? false);
@endphp

<section class="ct-support" aria-labelledby="contact-support-heading">
    <h2 class="ct-support__heading" id="contact-support-heading">{{ trans('contact.get_in_touch') }}</h2>

    @if ($status === 'CONFIGURED')
        <dl class="ct-support__list">
            @if (is_string($name) && $name !== '')
                <div class="ct-support__item">
                    <dt class="ct-support__term">{{ trans('contact.support_team') }}</dt>
                    <dd class="ct-support__value">{{ $name }}</dd>
                </div>
            @endif

            @if (is_string($email) && $email !== '')
                <div class="ct-support__item">
                    <dt class="ct-support__term">{{ trans('contact.email') }}</dt>
                    <dd class="ct-support__value">
                        <a class="ct-support__link" href="mailto:{{ $email }}">{{ $email }}</a>
                    </dd>
                </div>
            @endif

            @if (is_string($hours) && $hours !== '')
                <div class="ct-support__item">
                    <dt class="ct-support__term">{{ trans('contact.support_hours') }}</dt>
                    <dd class="ct-support__value">{{ $hours }}</dd>
                </div>
            @endif
        </dl>
    @else
        <p class="ct-support__unavailable" role="status">{{ trans('contact.support_unavailable') }}</p>
    @endif

    {{-- Sets expectations without describing the mail setup. Whether a
         provider exists is all that is exposed; its address, host and
         credentials are not in scope here. --}}
    <p class="ct-support__delivery-note">
        {{ $deliveryConfigured ? trans('contact.delivery_note_configured') : trans('contact.delivery_note_unconfigured') }}
    </p>
</section>
