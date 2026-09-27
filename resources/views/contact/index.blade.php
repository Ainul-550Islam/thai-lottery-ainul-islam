@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_robots', $meta['robots'])
@section('canonical', $meta['canonical'])

{{--
    Public Contact / Support page (PROMPT 10, file 13).

    INDEXABLE. This is ordinary public content and there is nothing
    query-dependent about it, so it carries index,follow. Only the POST
    endpoint is not crawlable, and robots.txt has no say over a POST anyway.

    EVERY USER VALUE IS ESCAPED. There is no {!! !!} anywhere in this lane. A
    contact message is text typed by a stranger; treating it as trusted markup
    is how a support page becomes an XSS vector.

    NO GOVERNMENT IDENTITY. The wording is "Customer Support". This platform
    has no verified relationship with any lottery authority, and a contact page
    implying one would be a claim made to exactly the people least able to
    check it.
--}}

@section('content')
    <div class="ct-page">
        <header class="ct-page__header">
            <h1 class="ct-page__title">{{ trans('contact.title') }}</h1>
            <p class="ct-page__intro">{{ trans('contact.intro') }}</p>
        </header>

        {{-- Set by the POST redirect. Says what actually happened. --}}
        <x-contact.delivery-status :status="session('contact_status')" />

        <div class="ct-page__grid">
            <div class="ct-page__main">
                @if ($enabled)
                    <section class="ct-form-section" aria-labelledby="contact-form-heading">
                        <h2 class="ct-form-section__heading" id="contact-form-heading">{{ trans('contact.form_heading') }}</h2>
                        <p class="ct-form-section__hint">{{ trans('contact.form_hint') }}</p>

                        <x-contact.contact-form :limits="$limits" :honeypot-field="$honeypotField" />
                    </section>
                @else
                    <p class="ct-page__disabled" role="status">{{ trans('contact.status.disabled') }}</p>
                @endif
            </div>

            <aside class="ct-page__aside">
                <x-contact.support-details :support="$support" :delivery="$delivery" />
                <x-contact.useful-links />
            </aside>
        </div>
    </div>
@endsection
