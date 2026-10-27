@props([
    'limits' => [],
    'honeypotField' => 'website',
])

{{--
    Public contact form (PROMPT 10, file 14).

    IT WORKS WITH JAVASCRIPT DISABLED. A plain form POSTing to a named route.
    The JS adds a character counter and a submit state and nothing else, so a
    visitor with a blocked script, an old browser or a screen reader that
    fights modern widgets can still ask for help.

    ACCESSIBILITY IS STRUCTURAL
    - every input has a real <label for> pointing at its id;
    - errors set aria-invalid and are announced through aria-describedby, so a
      screen reader reaches the message rather than a red border it cannot see;
    - required fields are marked with the attribute, not only with an asterisk;
    - the error summary is role="alert" so it is read when it appears.

    THE HONEYPOT IS HIDDEN FROM PEOPLE, NOT FROM BOTS. It is off-screen with
    aria-hidden and tabindex="-1" rather than display:none, because a sighted
    user never sees it, a keyboard user never lands on it, a screen reader
    skips it, and a form-walking script still fills it in. autocomplete="off"
    keeps a browser from helpfully filling it for a real person.

    CSRF IS LARAVEL'S. @csrf, inside the normal web middleware group.
--}}

@php
    $maxName = (int) ($limits['name'] ?? 120);
    $maxEmail = (int) ($limits['email'] ?? 190);
    $maxSubject = (int) ($limits['subject'] ?? 160);
    $maxMessage = (int) ($limits['message'] ?? 4000);
@endphp

@if ($errors->any())
    <div class="ct-errors" role="alert" aria-live="assertive">
        <p class="ct-errors__title">{{ trans('contact.validation_failed') }}</p>
        <ul class="ct-errors__list">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form class="ct-form" method="POST" action="{{ route('contact.submit') }}" id="contact-form" data-ct-form>
    @csrf

    <div class="ct-field">
        <label class="ct-field__label" for="contact-name">
            {{ trans('contact.name') }} <span class="ct-field__required" aria-hidden="true">*</span>
        </label>
        <input
            class="ct-field__input"
            id="contact-name"
            name="name"
            type="text"
            required
            maxlength="{{ $maxName }}"
            autocomplete="name"
            value="{{ old('name') }}"
            @error('name') aria-invalid="true" aria-describedby="contact-name-error" @enderror
        >
        @error('name')
            <p class="ct-field__error" id="contact-name-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="ct-field">
        <label class="ct-field__label" for="contact-email">
            {{ trans('contact.email') }} <span class="ct-field__required" aria-hidden="true">*</span>
        </label>
        <input
            class="ct-field__input"
            id="contact-email"
            name="email"
            type="email"
            required
            maxlength="{{ $maxEmail }}"
            autocomplete="email"
            value="{{ old('email') }}"
            @error('email') aria-invalid="true" aria-describedby="contact-email-error" @enderror
        >
        @error('email')
            <p class="ct-field__error" id="contact-email-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="ct-field">
        <label class="ct-field__label" for="contact-subject">
            {{ trans('contact.subject') }} <span class="ct-field__required" aria-hidden="true">*</span>
        </label>
        <input
            class="ct-field__input"
            id="contact-subject"
            name="subject"
            type="text"
            required
            maxlength="{{ $maxSubject }}"
            value="{{ old('subject') }}"
            @error('subject') aria-invalid="true" aria-describedby="contact-subject-error" @enderror
        >
        @error('subject')
            <p class="ct-field__error" id="contact-subject-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="ct-field">
        <label class="ct-field__label" for="contact-message">
            {{ trans('contact.message') }} <span class="ct-field__required" aria-hidden="true">*</span>
        </label>
        <textarea
            class="ct-field__textarea"
            id="contact-message"
            name="message"
            rows="8"
            required
            maxlength="{{ $maxMessage }}"
            data-ct-counter-target="contact-message-count"
            @error('message') aria-invalid="true" aria-describedby="contact-message-error contact-message-count" @else aria-describedby="contact-message-count" @enderror
        >{{ old('message') }}</textarea>

        {{-- Populated by JS. Without JS it still states the limit in text. --}}
        <p class="ct-field__hint" id="contact-message-count" data-ct-counter data-ct-max="{{ $maxMessage }}">
            {{ trans('contact.message_limit_hint', ['max' => $maxMessage]) }}
        </p>

        @error('message')
            <p class="ct-field__error" id="contact-message-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Honeypot. Off-screen rather than display:none; skipped by keyboard and
         by assistive technology; filled in by naive automation. --}}
    <div class="ct-hp" aria-hidden="true">
        <label for="contact-{{ $honeypotField }}">{{ trans('contact.honeypot_label') }}</label>
        <input
            id="contact-{{ $honeypotField }}"
            name="{{ $honeypotField }}"
            type="text"
            value=""
            tabindex="-1"
            autocomplete="off"
        >
    </div>

    <div class="ct-actions">
        <button class="ct-submit" type="submit" data-ct-submit data-ct-sending="{{ trans('contact.sending') }}">
            {{ trans('contact.submit') }}
        </button>
        <p class="ct-actions__privacy">{{ trans('contact.privacy_notice') }}</p>
    </div>
</form>
