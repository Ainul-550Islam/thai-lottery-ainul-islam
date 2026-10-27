@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
    'loading' => false,
    'iconOnly' => false,
])

@php
    $baseClasses = 'inline-flex items-center justify-center font-bold tracking-tight transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed select-none';

    $sizeClasses = match ($size) {
        'sm' => $iconOnly ? 'p-1.5 rounded-lg text-xs' : 'px-3 py-1.5 rounded-lg text-xs',
        'lg' => $iconOnly ? 'p-3.5 rounded-2xl text-base' : 'px-6 py-3.5 rounded-2xl text-base',
        default => $iconOnly ? 'p-2.5 rounded-xl text-sm' : 'px-4 py-2.5 rounded-xl text-sm',
    };

    $variantClasses = match ($variant) {
        'secondary' => 'bg-slate-800 hover:bg-slate-700 text-slate-100 border border-slate-700 shadow-sm active:scale-[0.98]',
        'ghost' => 'bg-transparent hover:bg-slate-800/80 text-slate-300 hover:text-white active:scale-[0.98]',
        'success' => 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-lg shadow-emerald-900/30 active:scale-[0.98]',
        'danger' => 'bg-rose-600 hover:bg-rose-500 text-white shadow-lg shadow-rose-900/30 active:scale-[0.98]',
        'accent' => 'bg-amber-500 hover:bg-amber-400 text-slate-950 font-black shadow-lg shadow-amber-900/30 active:scale-[0.98]',
        default => 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white shadow-lg shadow-emerald-950/40 active:scale-[0.98]',
    };

    $classes = "{$baseClasses} {$sizeClasses} {$variantClasses}";
@endphp

@if ($href)
    <a href="{{ $disabled ? '#' : $href }}" {{ $attributes->merge(['class' => $classes]) }} @if($disabled) aria-disabled="true" @endif>
        @if ($loading)
            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-current" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        @endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $disabled || $loading ? 'disabled' : '' }} {{ $attributes->merge(['class' => $classes]) }}>
        @if ($loading)
            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-current" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        @endif
        {{ $slot }}
    </button>
@endif
