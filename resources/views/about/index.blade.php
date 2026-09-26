@extends('layouts.app')

@section('title', $meta['title'])

@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@section('content')
    <div class="pp-page" data-pp-page="about" data-pp-status="{{ $page['status'] ?? 'UNAVAILABLE' }}">
        <a class="pp-skip-link" href="#pp-main">{{ trans('public_pages.skip_to_content') }}</a>

        <main id="pp-main" class="pp-main" tabindex="-1">
            <x-public-page.header
                :title="$page['title'] ?? ''"
                :lead="$page['lead'] ?? null"
                eyebrow="About"
            />

            <x-public-page.how-it-works
                :title="$page['how_it_works_title'] ?? trans('public_pages.how_it_works_title')"
                :steps="$page['how_it_works'] ?? []"
            />

            <x-public-page.history
                :title="$page['history']['title'] ?? ''"
                :text="$page['history']['text'] ?? ''"
            />

            <x-public-page.useful-links
                :title="$page['useful_links_title'] ?? trans('public_pages.useful_links_title')"
                :text="$page['useful_links_text'] ?? trans('public_pages.useful_links_text')"
                :links="$page['useful_links'] ?? []"
            />

            <x-public-page.contact
                :title="$page['contact']['title'] ?? ''"
                :text="$page['contact']['text'] ?? ''"
                :href="$page['contact']['href'] ?? route('contact')"
            />
        </main>

        <x-public-page.footer />
    </div>
@endsection
