@props([
    'action' => '',
    'types' => [],
    'maxLength' => 32,
    'type' => null,
    'term' => '',
])

{{--
    Public search form (PROMPT 9, file 23).

    GET, NOT POST. A search is a read. GET keeps the query in the URL so a
    result page can be linked and bookmarked, and it keeps the form out of
    CSRF territory for an operation that changes nothing.

    THE TYPE IS AN EXPLICIT CHOICE, NOT AN INFERENCE. The visitor picks 6D, 4D, 3D, 2D or Draw date. The server re-checks the value against a closed
    whitelist. Nothing guesses from the length of the term, because with three
    numeric fields a guess means answering a question the visitor did not ask.

    THE INPUT IS A STRING, ALL THE WAY DOWN. type="text" with
    inputmode="numeric", NOT type="number". A number input strips leading
    zeros in several browsers and hands the server 49 when the visitor typed
    049 - which would silently search for a different draw. The maxlength
    mirrors the configured bound so the form cannot offer to submit something
    the validator will refuse.

    ACCESSIBILITY: every control has a real <label> bound by id, the hint text
    is linked with aria-describedby, and the form is fully keyboard operable
    with no custom widgets.
--}}

@php
    $types = is_array($types) ? $types : [];
    $maxLength = max(1, (int) $maxLength);
    $term = (string) $term;
@endphp

<form
    class="wl-search"
    method="GET"
    action="{{ $action }}"
    role="search"
    data-wl-search
    data-wl-max-length="{{ $maxLength }}"
>
    <h2 class="wl-search__heading">{{ trans('pcso_lottery.search_heading') }}</h2>
    <p class="wl-search__intro">{{ trans('pcso_lottery.search_intro') }}</p>

    <div class="wl-search__row">
        <div class="wl-search__group">
            <label class="wl-search__label" for="wl-search-type">
                {{ trans('pcso_lottery.search_type_label') }}
            </label>
            <select class="wl-search__select" id="wl-search-type" name="type" required>
                @foreach ($types as $option)
                    <option value="{{ $option }}" @selected($type === $option)>
                        {{ trans('pcso_lottery.search_type.'.$option) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="wl-search__group">
            <label class="wl-search__label" for="wl-search-term">
                {{ trans('pcso_lottery.search_term_label') }}
            </label>
            <input
                class="wl-search__input"
                id="wl-search-term"
                name="term"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                maxlength="{{ $maxLength }}"
                placeholder="{{ trans('pcso_lottery.search_term_placeholder') }}"
                value="{{ $term }}"
                aria-describedby="wl-search-term-hint"
            >
            <p class="wl-search__hint" id="wl-search-term-hint">{{ trans('pcso_lottery.search_hint') }}</p>
        </div>
    </div>

    <div class="wl-search__actions">
        <button class="wl-search__submit" type="submit">{{ trans('pcso_lottery.search_submit') }}</button>
        <a class="wl-search__reset" href="{{ $action }}">{{ trans('pcso_lottery.search_reset') }}</a>
    </div>
</form>
