@extends('layouts.app')

@section('title', (string) ($meta['title'] ?? trans('contact.meta_title')))
@section('meta_description', (string) ($meta['description'] ?? trans('contact.meta_description')))
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/contact')))
@section('meta_robots', (string) ($meta['robots'] ?? 'index,follow'))
@section('meta_og_title', (string) ($meta['title'] ?? trans('contact.meta_title')))
@section('meta_og_description', (string) ($meta['description'] ?? trans('contact.meta_description')))
@section('meta_og_type', 'website')
@section('meta_og_url', (string) ($meta['canonical'] ?? url('/contact')))

@push('styles')
    @vite('resources/css/pages/contact.css')
@endpush

@section('content')
@php
    $supportStatus = (string) ($support['status'] ?? 'NOT_CONFIGURED');
    $supportHasDetails = ! empty($support['name']) || ! empty($support['email']) || ! empty($support['hours']);
    $contactEnabled = (bool) ($enabled ?? true);
    $status = session('contact_status');
    $statusState = is_array($status) ? (string) ($status['state'] ?? '') : '';
    $limitName = (int) (($limits['name'] ?? 120));
    $limitEmail = (int) (($limits['email'] ?? 190));
    $limitSubject = (int) (($limits['subject'] ?? 160));
    $limitMessage = (int) (($limits['message'] ?? 4000));
@endphp
<div class="next-public-page next-public-page--contact" data-next-public-page="contact">
    <a class="pp-skip-link" href="#contact-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header">
        <div class="next-shell next-page-header__inner">
            <a class="next-brand" href="{{ route('home') }}" aria-label="{{ trans('contact.brand_aria') }}"><span class="next-brand__mark">TL</span><span>{{ trans('contact.brand_title') }}<small>{{ trans('contact.brand_subtitle') }}</small></span></a>
            <nav class="next-nav" aria-label="{{ trans('contact.nav_primary') }}">
                <a href="{{ route('home') }}">{{ trans('contact.nav_home') }}</a><a href="{{ route('about') }}">{{ trans('contact.nav_about') }}</a><a href="{{ route('terms') }}">{{ trans('contact.nav_terms') }}</a><a href="{{ route('privacy') }}">{{ trans('contact.nav_privacy') }}</a><a class="is-active" href="{{ route('contact') }}" aria-current="page">{{ trans('contact.nav_contact') }}</a>
            </nav>
            @guest<a class="next-button next-button--gold" href="{{ route('login') }}">{{ trans('contact.nav_login') }}</a>@else<a class="next-button next-button--gold" href="{{ route('player.dashboard') }}">{{ trans('contact.nav_dashboard') }}</a>@endguest
        </div>
    </header>

    <div id="contact-main" class="next-shell next-content" tabindex="-1">
        <section class="next-hero" aria-labelledby="contact-title">
            <div><p class="next-eyebrow">{{ trans('contact.eyebrow_support_channel') }}</p><h1 id="contact-title">{{ trans('contact.title') }}</h1><p>{{ trans('contact.intro') }}</p><p class="next-note">{{ trans('contact.note_configured_only') }}</p></div>
            <div class="next-hero-object next-hero-object--headset" aria-hidden="true"><span>?</span><i></i><i></i></div>
        </section>

        <section class="next-meta-strip" aria-label="{{ trans('contact.status_aria') }}">
            <div><span>{{ trans('contact.status_support_identity') }}</span><strong>{{ ($supportHasDetails && $supportStatus === 'CONFIGURED') ? 'CONFIGURED' : 'NOT_CONFIGURED' }}</strong></div>
            <div><span>{{ trans('contact.status_form') }}</span><strong>{{ $contactEnabled ? 'AVAILABLE' : 'NOT_CONFIGURED' }}</strong></div>
            <div><span>{{ trans('contact.status_delivery') }}</span><strong>{{ (($delivery['destination_configured'] ?? false) ? 'CONFIGURED' : 'NOT_CONFIGURED') }}</strong></div>
        </section>

        <div class="contact-grid">
            <section class="next-panel" aria-labelledby="support-details-title">
                <p class="next-eyebrow">{{ trans('contact.configured_channels') }}</p><h2 id="support-details-title">{{ trans('contact.get_in_touch') }}</h2>
                @if ($supportStatus === 'CONFIGURED' && $supportHasDetails)
                    <dl class="contact-details">
                        @if (!empty($support['name']))<div><dt>{{ trans('contact.support_team') }}</dt><dd>{{ $support['name'] }}</dd></div>@endif
                        @if (!empty($support['email']))<div><dt>{{ trans('contact.email') }}</dt><dd><a href="mailto:{{ $support['email'] }}">{{ $support['email'] }}</a></dd></div>@endif
                        @if (!empty($support['hours']))<div><dt>{{ trans('contact.support_hours') }}</dt><dd>{{ $support['hours'] }}</dd></div>@endif
                    </dl>
                @else
                    <p class="next-empty" role="status">{{ trans('contact.support_unavailable') }}</p>
                @endif
                <p class="next-muted">{{ (($delivery['destination_configured'] ?? false) ? trans('contact.delivery_note_configured') : trans('contact.delivery_note_unconfigured')) }}</p>
            </section>

            <section class="next-panel" id="contact-form" aria-labelledby="contact-form-title">
                <p class="next-eyebrow">{{ trans('contact.secure_form') }}</p><h2 id="contact-form-title">{{ trans('contact.form_heading') }}</h2>
                @if ($statusState !== '')<div class="next-alert" role="status">{{ trans('contact.status.'.$statusState) }}@if (!empty($status['reference'])) <span>{{ $status['reference'] }}</span>@endif</div>@endif
                @if (! $contactEnabled)<p class="next-empty" role="status">{{ trans('contact.status.disabled') }}</p>@else
                    <form method="POST" action="{{ route('contact.submit') }}" class="next-form" novalidate>
                        @csrf
                        <div class="next-form__honeypot" aria-hidden="true"><label for="{{ $honeypotField }}">{{ trans('contact.honeypot_field_label') }}</label><input id="{{ $honeypotField }}" name="{{ $honeypotField }}" type="text" tabindex="-1" autocomplete="off"></div>
                        <div class="next-form__grid">
                            <div><label for="contact-name">{{ trans('contact.name') }}</label><input id="contact-name" name="name" value="{{ old('name') }}" maxlength="{{ $limitName }}" autocomplete="name" required>@error('name')<small class="next-error">{{ $message }}</small>@enderror</div>
                            <div><label for="contact-email">{{ trans('contact.email') }}</label><input id="contact-email" name="email" value="{{ old('email') }}" maxlength="{{ $limitEmail }}" type="email" autocomplete="email" required>@error('email')<small class="next-error">{{ $message }}</small>@enderror</div>
                        </div>
                        <div><label for="contact-subject">{{ trans('contact.subject') }}</label><input id="contact-subject" name="subject" value="{{ old('subject') }}" maxlength="{{ $limitSubject }}" required>@error('subject')<small class="next-error">{{ $message }}</small>@enderror</div>
                        <div><label for="contact-message">{{ trans('contact.message') }}</label><textarea id="contact-message" name="message" maxlength="{{ $limitMessage }}" rows="7" required>{{ old('message') }}</textarea>@error('message')<small class="next-error">{{ $message }}</small>@enderror</div>
                        <p class="next-form__note">{{ trans('contact.privacy_notice') }}</p>
                        <button class="next-button next-button--gold" type="submit">{{ trans('contact.submit') }}</button>
                    </form>
                @endif
            </section>
        </div>

        <section class="next-bottom" aria-labelledby="contact-links-title">
            <div><p class="next-eyebrow">{{ trans('contact.useful_links_eyebrow') }}</p><h2 id="contact-links-title">{{ trans('contact.useful_links') }}</h2><p>{{ trans('contact.continue_notice') }}</p></div>
            <div class="next-bottom__links">
                <a class="next-button" href="{{ route('home') }}">{{ trans('contact.link_home') }}</a>
                <a class="next-button" href="{{ route('about') }}">{{ trans('contact.link_about') }}</a>
                <a class="next-button" href="{{ route('vision') }}">{{ trans('contact.link_vision') }}</a>
                <a class="next-button" href="{{ route('fees') }}">{{ trans('contact.link_fees') }}</a>
                <a class="next-button" href="{{ route('results.index') }}">{{ trans('contact.link_results') }}</a>
                <a class="next-button" href="{{ route('prize-verification') }}">{{ trans('contact.link_prize_verification') }}</a>
                <a class="next-button" href="{{ route('national-lottery.index') }}">{{ trans('contact.link_national') }}</a>
                <a class="next-button" href="{{ route('weekly-lottery.index') }}">{{ trans('contact.link_weekly') }}</a>
                <a class="next-button" href="{{ route('bingo-lottery.index') }}">{{ trans('contact.link_mega') }}</a>
                <a class="next-button" href="{{ route('pcso-lottery.index') }}">{{ trans('contact.link_pcso') }}</a>
                <a class="next-button" href="{{ route('terms') }}">{{ trans('contact.link_terms') }}</a>
            </div>
        </section>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/contact.js')
@endpush
