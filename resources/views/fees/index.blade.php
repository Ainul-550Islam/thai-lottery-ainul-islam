@extends('layouts.app')

@section('title', $meta['title'])

@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@section('content')
    <div class="pp-page account-services" data-pp-page="fees" data-pp-status="{{ $fees['status'] ?? 'UNAVAILABLE' }}">
        <a class="pp-skip-link" href="#pp-main">{{ trans('public_pages.skip_to_content') }}</a>

        <main id="pp-main" class="pp-main" tabindex="-1">
            <x-public-page.header
                :title="$fees['title'] ?? ''"
                :lead="$fees['lead'] ?? null"
                eyebrow="Fees"
            />

            <x-public.fee-table
                :title="$fees['title'] ?? 'Fees'"
                :caption="$fees['title'] ?? 'Fees'"
                :rows="$fees['rows'] ?? []"
                :footer-note="$fees['footer_note'] ?? null"
                :not-configured="$fees['not_configured'] ?? 'NOT_CONFIGURED'"
                :empty-label="trans('account_services.fees_empty')"
                col-category="{{ trans('account_services.fees_col_category') }}"
                col-type="{{ trans('account_services.fees_col_type') }}"
                col-amount="{{ trans('account_services.fees_col_amount') }}"
                col-description="{{ trans('account_services.fees_col_description') }}"
                col-effective="{{ trans('account_services.fees_col_effective') }}"
            />
        </main>

        <x-public-page.footer />
    </div>
@endsection
