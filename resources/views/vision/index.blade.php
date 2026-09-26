@extends('layouts.app')

@section('title', $meta['title'])

@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@section('content')
    <div class="pp-page" data-pp-page="vision" data-pp-status="{{ $page['status'] ?? 'UNAVAILABLE' }}">
        <a class="pp-skip-link" href="#pp-main">{{ trans('public_pages.skip_to_content') }}</a>

        <main id="pp-main" class="pp-main" tabindex="-1">
            <x-public-page.header
                :title="$page['title'] ?? ''"
                :lead="$page['lead'] ?? null"
                eyebrow="Vision &amp; Mission"
            />

            <x-public-page.vision-mission
                :vision="$page['vision'] ?? ['title' => '', 'text' => '']"
                :mission="$page['mission'] ?? ['title' => '', 'text' => '']"
            />

            <x-public-page.core-values
                :title="$page['core_values_title'] ?? trans('public_pages.core_values_title')"
                :values="$page['core_values'] ?? []"
            />

            <x-public-page.governance
                :governance="$page['governance'] ?? ['title' => '', 'text' => '', 'controls' => [], 'disclaimer' => '']"
            />
        </main>

        <x-public-page.footer />
    </div>
@endsection
