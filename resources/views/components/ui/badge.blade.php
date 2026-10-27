@props([
    'variant' => 'neutral',
    'size' => 'md',
])

@php
    /*
     * Badge (C5.4).
     *
     * Only 19 pill-shaped labels existed, but spread across 14 different class
     * strings — the same drift the card component addresses, just at smaller
     * volume. The strings below are the ones that actually appeared.
     *
     * A badge is decorative status, never the only carrier of meaning: callers
     * should still expose the state in text or via aria-label, because colour
     * alone is not accessible.
     */

    $base = 'inline-flex items-center rounded-full font-bold uppercase tracking-wider whitespace-nowrap';

    $sizes = [
        'sm' => 'px-2 py-0.5 text-[10px]',
        'md' => 'px-3 py-1 text-xs',
    ];

    $variants = [
        // Amber: promotions, "new", attention without alarm.
        'accent'  => 'border border-amber-500/30 bg-amber-500/10 text-amber-300 font-black',
        // Gold: draws, prize tiers, anything brand-led.
        'gold'    => 'border border-gold/40 bg-ink-500 text-gold-200 font-semibold',
        // Neutral: metadata, codes, counts.
        'neutral' => 'border border-line bg-surface-2 text-content-primary',
        // Positive: verified, approved, won.
        'success' => 'border border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
        // Negative: rejected, failed, void.
        'danger'  => 'border border-rose-500/30 bg-rose-500/10 text-rose-300',
    ];

    $classes = $base
        . ' ' . ($sizes[$size] ?? $sizes['md'])
        . ' ' . ($variants[$variant] ?? $variants['neutral']);
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</span>
