@props([
    'action' => '',
    'fields' => [],
    'maxLength' => 32,
    'number' => '',
    'date' => '',
    'field' => null,
])

{{--
    Public search form (PROMPT 5, file 23).

    GET, NOT POST. A search is a read. GET keeps the query in the URL so a
    result page can be linked and bookmarked, and it keeps the form out of
    CSRF territory for an operation that changes nothing.

    THE INPUT IS A STRING, ALL THE WAY DOWN. type="text" with
    inputmode="numeric", NOT type="number". A number input strips leading
    zeros in several browsers and hands the server 4615 when the visitor
    typed 004615 - which would silently search for a different draw. The
    pattern attribute constrains the shape client-side; the server re-checks
    it, because a client-side pattern is a convenience, never a control.

    BOUNDED. maxlength mirrors config('national_lottery.page.max_search_length')
    so the form cannot offer to submit something the validator will refuse.

    THE FIELD SELECT IS A CLOSED LIST supplied by the controller from
    NationalLotterySearchService::searchableFields(). A value outside it is
    rejected server-side against a const map, never interpolated into a
    query.
--}}

@php
    $fields = is_array($fields) ? $fields : [];
    $maxLength = max(1, (int) $maxLength);
    $number = (string) $number;
    $date = (string) $date;
@endphp

<form
    class="nl-search"
    method="GET"
    action="{{ $action }}"
    role="search"
    data-nl-search
    data-nl-max-length="{{ $maxLength }}"
>
    <h2 class="nl-search__heading">{{ trans('national_lottery.search_heading') }}</h2>
    <p class="nl-search__intro">{{ trans('national_lottery.search_intro') }}</p>

    <div class="nl-search__row">
        <div class="nl-search__group">
            <label class="nl-search__label" for="nl-search-number">
                {{ trans('national_lottery.search_number_label') }}
            </label>
            <input
                class="nl-search__input"
                id="nl-search-number"
                name="number"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                pattern="[0-9]{2,6}"
                maxlength="6"
                placeholder="{{ trans('national_lottery.search_number_placeholder') }}"
                value="{{ $number }}"
                aria-describedby="nl-search-number-hint"
            >
            <p class="nl-search__hint" id="nl-search-number-hint">{{ trans('national_lottery.search_hint') }}</p>
        </div>

        <div class="nl-search__group">
            <label class="nl-search__label" for="nl-search-date">
                {{ trans('national_lottery.search_date_label') }}
            </label>
            <input
                class="nl-search__input"
                id="nl-search-date"
                name="date"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                maxlength="{{ $maxLength }}"
                placeholder="{{ trans('national_lottery.search_date_placeholder') }}"
                value="{{ $date }}"
            >
        </div>

        <div class="nl-search__group">
            <label class="nl-search__label" for="nl-search-field">
                {{ trans('national_lottery.search_field_label') }}
            </label>
            <select class="nl-search__select" id="nl-search-field" name="field">
                <option value="">{{ trans('national_lottery.search_any_field') }}</option>
                @foreach ($fields as $option)
                    <option value="{{ $option }}" @selected($field === $option)>
                        {{ trans('national_lottery.field_'.$option) }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="nl-search__actions">
        <button class="nl-search__submit" type="submit">{{ trans('national_lottery.search_submit') }}</button>
        <a class="nl-search__reset" href="{{ $action }}">{{ trans('national_lottery.search_reset') }}</a>
    </div>
</form>
