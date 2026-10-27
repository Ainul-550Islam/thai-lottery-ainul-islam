@props([
    'variant' => 'default',
    'padding' => 'md',
    'as' => 'div',
    'heading' => null,
    'headingLevel' => 'h3',
])

@php
    /*
     * Card (C5.4).
     *
     * A 2026-era code audit counted 250 card-like containers across this
     * codebase spread over 148 distinct class strings. The repetition was not
     * one pattern copied 250 times; it was one pattern drifting into 148
     * slightly different ones, because every page hand-rolled its own.
     *
     * This component collapses the drift. The class strings below are the
     * exact strings that appeared most often, so adopting `<x-ui.card>`
     * where one of them matched was a pure substitution with no visual change.
     *
     * `as` exists so a card can be a <section>, <article> or <li> where that is
     * the correct element; semantics should not be sacrificed for a shared
     * shell. When a heading is supplied it is rendered at `headingLevel`, so a
     * card on a page with no h2 still produces a sensible document outline.
     */

    $variants = [
        // The house card: near-black panel, gold-ish border, generous padding.
        'default' => 'rounded-3xl border border-line-soft bg-surface-1/90 shadow-xl',
        // Slimmer, for lists and dense layouts.
        'flat'    => 'rounded-xl border border-line-soft bg-surface-0',
        // Emphasis: lottery draws, promotions, prize callouts.
        'gold'    => 'rounded-3xl border border-amber-400/30 bg-ink-400',
        // Barely-there tint, for nested groups inside another card.
        'panel'   => 'rounded-xl border border-line-soft/5 bg-content-strong/[0.03]',
        // No chrome at all: the caller supplies everything else.
        'bare'    => 'rounded-xl',
    ];

    $paddings = [
        'none' => '',
        'xs'   => 'p-3',
        'sm'   => 'p-4',
        'md'   => 'p-6 sm:p-8',
        'lg'   => 'p-8 sm:p-10',
    ];

    $classes = ($variants[$variant] ?? $variants['default'])
        . ' ' . ($paddings[$padding] ?? $paddings['md']);
@endphp

<{{ $as }} {{ $attributes->merge(['class' => $classes]) }}>
    @if ($heading)
        <{{ $headingLevel }} class="mb-4 text-lg font-bold text-content-strong">{{ $heading }}</{{ $headingLevel }}>
    @endif

    {{ $slot }}
</{{ $as }}>
