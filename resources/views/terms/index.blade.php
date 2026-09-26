@extends('layouts.app')

@section('title', $meta['title'])

@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@section('content')
    <div class="pp-page" data-pp-page="terms" data-pp-status="{{ $page['status'] ?? 'UNAVAILABLE' }}" data-pp-legal-version="{{ $page['version'] ?? '' }}">
        <a class="pp-skip-link" href="#pp-main">{{ trans('public_pages.skip_to_content') }}</a>

        <main id="pp-main" class="pp-main" tabindex="-1">
            <x-public-page.header
                :title="$page['title'] ?? ''"
                :version="$page['version'] ?? ''"
                :version-label="$page['version_label'] ?? 'Version'"
                :effective-at="$page['effective_at'] ?? ''"
                :effective-label="$page['effective_label'] ?? 'Effective date'"
                :updated-at="$page['updated_at'] ?? ''"
                :updated-label="$page['updated_label'] ?? 'Last updated'"
            />

            <x-public-page.terms-sections
                :title="$page['title'] ?? 'Terms'"
                :sections="$page['sections'] ?? []"
                :operator="$page['operator'] ?? null"
                operator-title="Operator identity"
                :operator-labels="[
                    'legal_name' => 'Legal name',
                    'registration' => 'Registration number',
                    'email' => 'Support email',
                    'phone' => 'Support phone',
                    'address' => 'Address',
                ]"
            />
        </main>

        <x-public-page.footer />
    </div>
@endsection
