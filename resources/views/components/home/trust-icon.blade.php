@props([
    'icon' => 'shield',
])

<span class="th-trust-icon inline-flex items-center justify-center shrink-0" aria-hidden="true">
    @switch($icon)
        @case('lock')
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <rect x="4" y="11" width="16" height="10" rx="2" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 11V7a4 4 0 118 0v4" />
            </svg>
            @break
        @case('check')
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            @break
        @default
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7.5 3.75v5.625c0 4.868-3.208 9.29-7.5 10.875-4.292-1.585-7.5-6.007-7.5-10.875V6.75L12 3z" />
            </svg>
    @endswitch
</span>
