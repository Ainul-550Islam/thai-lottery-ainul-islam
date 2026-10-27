@props(['purpose'])
@php($challenge = app(\App\Services\Security\HoneypotService::class)->issue(request(), $purpose))
@if ($challenge !== null)
    <input type="hidden" name="_hp_token" value="{{ $challenge['token'] }}">
    {{-- Native hidden container needs no CSS, inline styles or JavaScript. --}}
    <div hidden aria-hidden="true">
        <label for="{{ $challenge['field'] }}">{{ __('Leave this field empty') }}</label>
        <input type="text" id="{{ $challenge['field'] }}" name="{{ $challenge['field'] }}"
               value="" tabindex="-1" autocomplete="off">
    </div>
@endif
