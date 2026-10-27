@extends('layouts.app')

{{--
    Head block.

    Every key is read with a fallback. The earlier revision indexed $meta
    directly, so a controller that supplied five of the eight keys turned this
    public page into an "Undefined array key" error page instead of a page with
    a slightly thinner <head>. The page's own lang catalogue is the fallback
    source, so a missing meta value degrades to the visible heading rather than
    to a stack trace.
--}}
@php
    $meta = is_array($meta ?? null) ? $meta : [];
    $metaTitle = (string) ($meta['title'] ?? trans('services.meta_title'));
    $metaDescription = (string) ($meta['description'] ?? trans('services.meta_description'));
    $metaCanonical = (string) ($meta['canonical'] ?? url('/services'));
@endphp

@section('title', $metaTitle.' | '.config('app.name'))
@section('meta_description', $metaDescription)
@section('meta_canonical', $metaCanonical)
@section('meta_robots', (string) ($meta['robots'] ?? 'index,follow'))
@section('meta_og_title', (string) ($meta['og_title'] ?? $metaTitle))
@section('meta_og_description', (string) ($meta['og_description'] ?? $metaDescription))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@section('meta_og_url', (string) ($meta['og_url'] ?? $metaCanonical))

@section('content')
<section class="mx-auto max-w-6xl space-y-10" aria-labelledby="services-title">
    <header class="max-w-3xl space-y-4">
        <p class="text-sm font-bold uppercase tracking-[0.2em] text-amber-400">{{ trans('services.eyebrow') }}</p>
        <h1 id="services-title" class="text-4xl font-black tracking-tight text-white sm:text-5xl">{{ trans('services.heading') }}</h1>
        <p class="text-lg leading-8 text-slate-300">{{ trans('services.intro') }}</p>
    </header>

    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        <article class="rounded-2xl border border-slate-700 bg-slate-900/70 p-6">
            <h2 class="text-xl font-bold text-white">{{ trans('services.catalogue_title') }}</h2>
            <p class="mt-3 leading-7 text-slate-300">{{ trans('services.catalogue_body') }}</p>
            <a class="mt-5 inline-flex font-semibold text-emerald-300 hover:text-emerald-200" href="{{ route('lotteries.index') }}">{{ trans('services.catalogue_link') }} <span class="ml-2" aria-hidden="true">→</span></a>
        </article>
        <article class="rounded-2xl border border-slate-700 bg-slate-900/70 p-6">
            <h2 class="text-xl font-bold text-white">{{ trans('services.results_title') }}</h2>
            <p class="mt-3 leading-7 text-slate-300">{{ trans('services.results_body') }}</p>
            <a class="mt-5 inline-flex font-semibold text-emerald-300 hover:text-emerald-200" href="{{ route('results.index') }}">{{ trans('services.results_link') }} <span class="ml-2" aria-hidden="true">→</span></a>
        </article>
        <article class="rounded-2xl border border-slate-700 bg-slate-900/70 p-6">
            <h2 class="text-xl font-bold text-white">{{ trans('services.ticket_title') }}</h2>
            <p class="mt-3 leading-7 text-slate-300">{{ trans('services.ticket_body') }}</p>
            <a class="mt-5 inline-flex font-semibold text-emerald-300 hover:text-emerald-200" href="{{ route('ticket-check') }}">{{ trans('services.ticket_link') }} <span class="ml-2" aria-hidden="true">→</span></a>
        </article>
        <article class="rounded-2xl border border-slate-700 bg-slate-900/70 p-6">
            <h2 class="text-xl font-bold text-white">{{ trans('services.discount_title') }}</h2>
            <p class="mt-3 leading-7 text-slate-300">{{ trans('services.discount_body') }}</p>
            <a class="mt-5 inline-flex font-semibold text-emerald-300 hover:text-emerald-200" href="{{ route('lotto-discount') }}">{{ trans('services.discount_link') }} <span class="ml-2" aria-hidden="true">→</span></a>
        </article>
        <article class="rounded-2xl border border-slate-700 bg-slate-900/70 p-6">
            <h2 class="text-xl font-bold text-white">{{ trans('services.account_help_title') }}</h2>
            <p class="mt-3 leading-7 text-slate-300">{{ trans('services.account_help_body') }}</p>
            <div class="mt-5 flex flex-wrap gap-x-5 gap-y-2">
                <a class="font-semibold text-emerald-300 hover:text-emerald-200" href="{{ route('how-to-play') }}">{{ trans('services.how_to_play') }}</a>
                <a class="font-semibold text-emerald-300 hover:text-emerald-200" href="{{ route('faq') }}">{{ trans('services.faq') }}</a>
            </div>
        </article>
        <article class="rounded-2xl border border-slate-700 bg-slate-900/70 p-6">
            <h2 class="text-xl font-bold text-white">{{ trans('services.fees_support_title') }}</h2>
            <p class="mt-3 leading-7 text-slate-300">{{ trans('services.fees_support_body') }}</p>
            <div class="mt-5 flex flex-wrap gap-x-5 gap-y-2">
                <a class="font-semibold text-emerald-300 hover:text-emerald-200" href="{{ route('fees') }}">{{ trans('services.view_fees') }}</a>
                <a class="font-semibold text-emerald-300 hover:text-emerald-200" href="{{ route('contact') }}">{{ trans('services.contact_us') }}</a>
            </div>
        </article>
        <article class="rounded-2xl border border-slate-700 bg-slate-900/70 p-6">
            <h2 class="text-xl font-bold text-white">{{ trans('services.agent_inquiries_title') }}</h2>
            <p class="mt-3 leading-7 text-slate-300">{{ trans('services.agent_inquiries_body') }}</p>
            <a class="mt-5 inline-flex font-semibold text-emerald-300 hover:text-emerald-200" href="{{ route('contact') }}">{{ trans('services.agent_inquiries_link') }} <span class="ml-2" aria-hidden="true">→</span></a>
        </article>
        <article class="rounded-2xl border border-slate-700 bg-slate-900/70 p-6">
            <h2 class="text-xl font-bold text-white">{{ trans('services.policies_title') }}</h2>
            <p class="mt-3 leading-7 text-slate-300">{{ trans('services.policies_body') }}</p>
            <div class="mt-5 flex flex-wrap gap-x-5 gap-y-2">
                <a class="font-semibold text-emerald-300 hover:text-emerald-200" href="{{ route('terms') }}">{{ trans('services.terms') }}</a>
                <a class="font-semibold text-emerald-300 hover:text-emerald-200" href="{{ route('account-verification-guide') }}">{{ trans('services.verification') }}</a>
                <a class="font-semibold text-emerald-300 hover:text-emerald-200" href="{{ route('account-grades') }}">{{ trans('services.grades') }}</a>
                <a class="font-semibold text-emerald-300 hover:text-emerald-200" href="{{ route('prize-verification') }}">{{ trans('services.prize_verification') }}</a>
            </div>
        </article>
    </div>

    <aside class="rounded-2xl border border-amber-500/30 bg-amber-950/20 p-5 text-sm leading-7 text-amber-100" aria-label="{{ trans('services.important_title') }}">
        <strong class="font-bold">{{ trans('services.important_title') }}</strong>
        {{ trans('services.important_body') }}
        <a class="underline underline-offset-4 hover:text-white" href="{{ route('terms') }}">{{ trans('services.terms_link') }}</a>
    </aside>
</section>
@endsection
