@props([
    'action' => '',
    'maxLength' => 6,
    'number' => '',
    'date' => '',
    'field' => null,
])
<section class="rounded-2xl border border-[#D4AF37]/25 bg-[#141007] p-6" data-nl-search>
    <h2 class="text-xl font-bold text-[#F5E6B8]">{{ trans('national_lottery.search_heading') }}</h2>
    <p class="mt-2 text-sm text-gray-400">{{ trans('national_lottery.search_intro') }}</p>
    <form method="GET" action="{{ $action }}" role="search" class="mt-6 grid gap-4 md:grid-cols-4">
        <div><label class="mb-2 block text-xs font-semibold text-gray-300" for="nl-search-number">{{ trans('national_lottery.search_number_label') }}</label><input class="w-full rounded-xl border border-white/10 bg-[#0B0904] px-4 py-3 font-mono text-white" id="nl-search-number" name="number" type="text" inputmode="numeric" maxlength="{{ $maxLength }}" value="{{ $number }}" placeholder="{{ trans('national_lottery.search_number_placeholder') }}"></div>
        <div><label class="mb-2 block text-xs font-semibold text-gray-300" for="nl-search-date">{{ trans('national_lottery.search_date_label') }}</label><input class="w-full rounded-xl border border-white/10 bg-[#0B0904] px-4 py-3 font-mono text-white" id="nl-search-date" name="date" type="date" value="{{ $date }}"></div>
        <div><label class="mb-2 block text-xs font-semibold text-gray-300" for="nl-search-field">{{ trans('national_lottery.search_field_label') }}</label><select class="w-full rounded-xl border border-white/10 bg-[#0B0904] px-4 py-3 text-white" id="nl-search-field" name="field"><option value="">{{ trans('national_lottery.search_any_field') }}</option>@foreach ($field === null ? [] : [$field] as $option)<option value="{{ $option }}" selected>{{ trans('national_lottery.field_'.$option) }}</option>@endforeach</select></div>
        <div class="flex items-end"><button class="w-full rounded-xl bg-gradient-to-r from-[#D4AF37] to-[#AA7C11] px-4 py-3 font-bold text-[#0B0904]" type="submit">{{ trans('national_lottery.search_submit') }}</button></div>
    </form>
    <p class="mt-3 text-xs text-gray-500">{{ trans('national_lottery.search_hint') }}</p>
</section>
