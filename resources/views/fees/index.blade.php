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

            @foreach (($fees['groups'] ?? []) as $group)
                <x-public.fee-table
                    :title="$group['label']"
                    :caption="$group['label'].' — '.trans('account_services.fees_table_caption')"
                    :rows="$group['rows']"
                    :show-provider="($group['has_providers'] ?? false)"
                    :footer-note="null"
                    :not-configured="$fees['not_configured'] ?? 'NOT_CONFIGURED'"
                    :empty-label="trans('account_services.fees_empty')"
                    :provider-none-label="trans('account_services.fees_provider_none')"
                    col-category="{{ trans('account_services.fees_col_category') }}"
                    col-type="{{ trans('account_services.fees_col_type') }}"
                    col-amount="{{ trans('account_services.fees_col_amount') }}"
                    col-provider="{{ trans('account_services.fees_col_provider') }}"
                    col-description="{{ trans('account_services.fees_col_description') }}"
                    col-effective="{{ trans('account_services.fees_col_effective') }}"
                />
            @endforeach

            <section class="pp-section fee-preview" aria-labelledby="pp-fees-preview-title" data-pp-section="fee-preview">
                <div class="pp-section__inner">
                    <h2 class="pp-section__title" id="pp-fees-preview-title">{{ trans('account_services.fees_preview_title') }}</h2>
                    <p class="pp-section__text">{{ trans('account_services.fees_preview_lead') }}</p>

                    <p class="fee-preview__nojs">{{ trans('account_services.fees_preview_nojs') }}</p>

                    <form class="acct-form fee-preview__form" data-pp-fee-preview method="post" action="/api/v1/fees/preview" novalidate
                          data-pp-preview-loading="{{ trans('account_services.fees_preview_loading') }}"
                          data-pp-preview-error="{{ trans('account_services.fees_preview_error') }}"
                          data-pp-preview-network-error="{{ trans('account_services.fees_preview_network_error') }}"
                          data-pp-preview-fee-label="{{ trans('account_services.fees_preview_fee_label') }}"
                          data-pp-preview-state-label="{{ trans('account_services.fees_preview_state_label') }}"
                          data-pp-preview-not-configured="{{ trans('account_services.fees_not_configured') }}">
                        @csrf
                        <div class="acct-form__row">
                            <div class="acct-field">
                                <label for="fee-preview-category">{{ trans('account_services.fees_preview_category_label') }}</label>
                                <select class="acct-input" id="fee-preview-category" name="category" required>
                                    @foreach (($preview['categories'] ?? []) as $option)
                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="acct-field">
                                <label for="fee-preview-provider">{{ trans('account_services.fees_preview_provider_label') }}</label>
                                <select class="acct-input" id="fee-preview-provider" name="provider">
                                    <option value="">{{ trans('account_services.fees_preview_provider_none') }}</option>
                                    @foreach (($preview['providers'] ?? []) as $option)
                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="acct-field">
                            <label for="fee-preview-amount">{{ trans('account_services.fees_preview_amount_label') }}</label>
                            <input class="acct-input" id="fee-preview-amount" name="base_amount" type="text"
                                   inputmode="decimal" autocomplete="off" maxlength="16"
                                   placeholder="100.00" required>
                        </div>

                        <button class="acct-btn" type="submit">{{ trans('account_services.fees_preview_submit') }}</button>
                    </form>

                    <p class="acct-note fee-preview__status" role="status" aria-live="polite" data-pp-fee-preview-status></p>
                </div>
            </section>

            <p class="pp-section__text fee-table__note">{{ $fees['footer_note'] ?? '' }}</p>
        </main>

        <x-public-page.footer />
    </div>
@endsection
