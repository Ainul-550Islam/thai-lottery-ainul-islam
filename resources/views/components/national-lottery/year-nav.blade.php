@props([
    'years' => [],
    'activeYear' => null,
    'route' => 'national-lottery.year',
])

@php $years = is_array($years) ? $years : []; @endphp
<nav class="my-6" aria-label="{{ trans('national_lottery.year_nav_heading') }}" data-nl-years>
    <h2 class="mb-3 text-sm font-bold uppercase tracking-wider text-[#D4AF37]">{{ trans('national_lottery.year_nav_heading') }}</h2>
    @if ($years === [])
        <p class="text-sm text-gray-400">{{ trans('national_lottery.year_nav_empty') }}</p>
    @else
        <ul class="flex flex-wrap gap-2">
            @foreach ($years as $year)
                @php $gregorian = is_array($year) ? (int) ($year['gregorian'] ?? 0) : (int) $year; @endphp
                @if ($gregorian > 0)
                    <li><a class="inline-flex items-center rounded-xl border px-4 py-2 text-sm font-semibold {{ (int) $activeYear === $gregorian ? 'border-[#D4AF37] bg-[#2A2312] text-[#F5E6B8]' : 'border-white/10 bg-white/[0.03] text-gray-400 hover:border-[#D4AF37]/50' }}" href="{{ route($route, ['year' => $gregorian]) }}" @if ((int) $activeYear === $gregorian) aria-current="page" @endif>{{ is_array($year) ? ($year['label_en'] ?? $gregorian) : $gregorian }}</a></li>
                @endif
            @endforeach
        </ul>
    @endif
</nav>
