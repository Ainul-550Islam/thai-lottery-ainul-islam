@extends('layouts.app')

@php
    /*
     | THIS FILE SERVES TWO SURFACES. READ THIS BEFORE EDITING.
     |
     | $account is set  -> the authenticated member page, rendered by
     |                     App\Http\Controllers\Verification\AccountVerificationController.
     |                     It supplies $documents as a list of document ARRAYS
     |                     (document_type, status, download_token, ...), plus
     |                     $history, $countryCodes, $documentTypes, $requireBack.
     |
     | $verification is set -> the public, unauthenticated guide, rendered by
     |                     App\Http\Controllers\PublicVerificationController.
     |                     It supplies nothing else, and its $documents are
     |                     plain STRINGS naming the configured document types.
     |
     | The two shapes are not interchangeable, and the @if (isset($account))
     | further down is what picks between them.
     |
     | THE DEFECT THIS BLOCK USED TO CAUSE.
     | These three variables were derived from $verification unconditionally.
     | On a member request $verification does not exist, so $documents - the
     | real list the controller had just built - was silently overwritten with
     | an empty array. A member's own uploaded documents therefore never
     | appeared on their verification page, and the page reported having none.
     | Nothing failed loudly; the section simply rendered empty.
     |
     | Each variable is now derived only when it is actually absent, so the
     | public guide keeps its defaults and the member surface keeps its data.
     */
    $verificationGuide = is_array($verification ?? null) ? $verification : [];

    $steps = $steps
        ?? (is_array($verificationGuide['steps']['steps'] ?? null) ? $verificationGuide['steps']['steps'] : []);

    $documents = $documents
        ?? (is_array($verificationGuide['documents'] ?? null) ? $verificationGuide['documents'] : []);

    $status = $status
        ?? (string) ($verificationGuide['status'] ?? 'UNAVAILABLE');
@endphp

@section('title', (string) ($meta['title'] ?? $verification['meta_title'] ?? 'Account Verification'))
@section('meta_description', (string) ($meta['description'] ?? $verification['meta_description'] ?? 'Account verification guidance.'))
@if (isset($account))
@section('meta_robots', 'noindex,nofollow')
@else
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/account-verification')))
@endif
@section('meta_og_title', (string) ($meta['og_title'] ?? $verification['meta_title'] ?? 'Account Verification'))
@section('meta_og_description', (string) ($meta['og_description'] ?? $verification['meta_description'] ?? ''))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@if (!isset($account))
@section('meta_og_url', (string) ($meta['og_url'] ?? url('/account-verification')))
@endif

@push('styles')
    @vite('resources/css/pages/account-verification.css')
@endpush

@section('content')
@if (isset($account))
<div class="min-h-[75vh] py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-3xl mx-auto space-y-8">

        <div class="text-center">
            <h2 class="text-3xl font-extrabold tracking-tight text-white">
                {{ __('account_services.verification_heading') }}
            </h2>
            <p class="mt-2 text-sm text-slate-400">
                {{ __('account_services.verification_lead') }}
            </p>
        </div>

        @if (session('success'))
            <div class="bg-emerald-950/60 border border-emerald-600/50 rounded-xl p-4 text-emerald-200 text-sm" role="status">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="bg-rose-950/60 border border-rose-600/50 rounded-xl p-4 text-rose-200 text-sm" role="alert" aria-live="polite">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ================= Account summary (owner-only) ================= --}}
        <section class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-2xl" aria-labelledby="acct-heading">
            <h3 id="acct-heading" class="text-lg font-bold text-emerald-300 border-b border-slate-800 pb-2 mb-4">
                {{ __('account_services.verification_account_section') }}
            </h3>

            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
                <div class="flex justify-between sm:justify-start sm:gap-4">
                    <dt class="text-slate-400">{{ __('account_services.verification_account_number') }}</dt>
                    <dd class="font-mono font-semibold text-slate-100" data-av-account-number>{{ $account['account_number'] ?? __('account_services.not_recorded') }}</dd>
                </div>
                <div class="flex justify-between sm:justify-start sm:gap-4">
                    <dt class="text-slate-400">{{ __('account_services.verification_account_name') }}</dt>
                    <dd class="font-semibold text-slate-100">{{ $account['name'] ?: __('account_services.not_recorded') }}</dd>
                </div>
                <div class="flex justify-between sm:justify-start sm:gap-4">
                    <dt class="text-slate-400">{{ __('account_services.verification_account_email') }}</dt>
                    <dd class="font-semibold text-slate-100">{{ $account['email'] ?: __('account_services.not_recorded') }}</dd>
                </div>
                <div class="flex justify-between sm:justify-start sm:gap-4">
                    <dt class="text-slate-400">{{ __('account_services.verification_account_join') }}</dt>
                    <dd class="font-semibold text-slate-100">{{ $account['join_date'] ?: __('account_services.not_recorded') }}</dd>
                </div>
                <div class="flex justify-between sm:justify-start sm:gap-4">
                    <dt class="text-slate-400">{{ __('account_services.verification_account_renew') }}</dt>
                    <dd class="font-semibold text-slate-100">{{ $account['renew_date'] ?: __('account_services.not_recorded') }}</dd>
                </div>
                <div class="flex justify-between sm:justify-start sm:gap-4">
                    <dt class="text-slate-400">{{ __('account_services.verification_account_status') }}</dt>
                    <dd class="font-semibold {{ $account['verification_status'] === 'APPROVED' ? 'text-emerald-300' : 'text-amber-300' }}" data-av-status="{{ $account['verification_status'] ?? 'NOT_SUBMITTED' }}">{{ __('account_services.verification_status_'.($account['verification_status'] ?? 'NOT_SUBMITTED')) }}</dd>
                </div>
            </dl>

            <p class="mt-4 text-xs text-slate-500">
                {{ __('account_services.verification_method_note') }}:
                <span class="font-mono">{{ $account['method'] }}</span>
                @if ($account['phone_verification'] === 'PHONE_VERIFICATION_NOT_CONFIGURED')
                    — <span class="font-mono">{{ $account['phone_verification'] }}</span>
                @endif
            </p>
        </section>

        {{-- ================= Verify Now (submission) ================= --}}
        <section class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-2xl" aria-labelledby="verify-heading">
            <h3 id="verify-heading" class="text-lg font-bold text-emerald-300 border-b border-slate-800 pb-2 mb-4">
                {{ __('account_services.verification_verify_now') }}
            </h3>

            @if (! $canSubmit)
                <div class="bg-amber-950/50 border border-amber-600/40 rounded-xl p-4 text-amber-200 text-sm" role="status">
                    {{ __('account_services.verification_open_request') }}
                </div>
            @else
                <form class="space-y-5" action="{{ route('account.verification.submit') }}" method="POST" enctype="multipart/form-data" novalidate>
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label for="country_code" class="block text-sm font-medium text-slate-300 mb-1">{{ __('account_services.verification_country_code') }}</label>
                            <select id="country_code" name="country_code" class="block w-full px-4 py-3 border border-slate-700 bg-slate-950 text-slate-100 rounded-xl sm:text-sm">
                                @foreach ($countryCodes as $code)
                                    <option value="{{ $code }}" @selected($code === $defaultCountryCode)>{{ $code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="mobile" class="block text-sm font-medium text-slate-300 mb-1">{{ __('account_services.verification_mobile') }}</label>
                            <input id="mobile" name="mobile" type="text" inputmode="numeric" value="{{ old('mobile') }}" aria-describedby="av-mobile-hint" @if ($errors->has('mobile')) aria-invalid="true" @endif class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('mobile') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}" placeholder="812345678">
                            <p id="av-mobile-hint" class="mt-1 text-xs text-slate-500">{{ __('account_services.verification_mobile_hint') }}</p>
                            @if ($errors->has('mobile'))<p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('mobile') }}</p>@endif
                        </div>
                    </div>
                    <div>
                        <label for="document_type" class="block text-sm font-medium text-slate-300 mb-1">{{ __('account_services.verification_document_type') }}</label>
                        <select id="document_type" name="document_type" required aria-required="true" @if ($errors->has('document_type')) aria-invalid="true" @endif class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('document_type') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100' }}">
                            <option value="">{{ __('account_services.verification_select_type') }}</option>
                            @foreach ($documentTypes as $type)
                                <option value="{{ $type }}" @selected(old('document_type') === $type)>{{ __('account_services.verification_document_type_'.$type) }}</option>
                            @endforeach
                        </select>
                        @if ($errors->has('document_type'))<p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('document_type') }}</p>@endif
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label for="document" class="block text-sm font-medium text-slate-300 mb-1">{{ __('account_services.verification_document_front') }} @if ($requireBack)<span class="text-rose-400" aria-hidden="true">*</span>@endif</label>
                            <input id="document" name="document" type="file" required aria-required="true" accept=".pdf,.jpg,.jpeg,.png,.webp" aria-describedby="av-front-hint" @if ($errors->has('document')) aria-invalid="true" @endif class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('document') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100' }}">
                            <p id="av-front-hint" class="mt-1 text-xs text-slate-500">{{ __('account_services.verification_upload_hint', ['kb' => $maxFileKb]) }}</p>
                            @if ($errors->has('document'))<p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('document') }}</p>@endif
                        </div>
                        <div>
                            <label for="document_back" class="block text-sm font-medium text-slate-300 mb-1">{{ __('account_services.verification_document_back') }} @if ($requireBack)<span class="text-rose-400" aria-hidden="true">*</span>@endif</label>
                            <input id="document_back" name="document_back" type="file" {{ $requireBack ? 'required aria-required="true"' : '' }} accept=".pdf,.jpg,.jpeg,.png,.webp" aria-describedby="av-back-hint" @if ($errors->has('document_back')) aria-invalid="true" @endif class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('document_back') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100' }}">
                            <p id="av-back-hint" class="mt-1 text-xs text-slate-500">{{ __('account_services.verification_upload_hint', ['kb' => $maxFileKb]) }}</p>
                            @if ($errors->has('document_back'))<p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('document_back') }}</p>@endif
                        </div>
                    </div>
                    <div><button type="submit" class="w-full sm:w-auto flex justify-center py-3 px-6 border border-transparent text-sm font-bold rounded-xl text-slate-950 bg-gradient-to-r from-amber-400 via-emerald-400 to-emerald-500 hover:from-amber-500 hover:to-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 shadow-lg transition duration-200">{{ __('account_services.verification_submit') }}</button></div>
                </form>
            @endif
        </section>

        {{-- ================= Submission history (member-safe) ================= --}}
        @if (count($history) > 0)
            <section class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-2xl" aria-labelledby="history-heading">
                <h3 id="history-heading" class="text-lg font-bold text-emerald-300 border-b border-slate-800 pb-2 mb-4">{{ __('account_services.verification_history') }}</h3>
                <ul class="space-y-3 text-sm" role="list">
                    @foreach ($history as $entry)
                        <li class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800/60 pb-2"><span class="font-mono text-slate-300">{{ $entry['reference'] }}</span><span class="font-semibold {{ $entry['status'] === 'APPROVED' ? 'text-emerald-300' : ($entry['status'] === 'REJECTED' ? 'text-rose-300' : 'text-amber-300') }}">{{ __('account_services.verification_status_'.($entry['status'] ?? 'NOT_SUBMITTED')) }}</span><span class="text-slate-500">{{ $entry['submitted_at'] ?: __('account_services.not_recorded') }}</span></li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- ================= Documents (metadata only, authorized download) ================= --}}
        @if (count($documents) > 0)
            <section class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-2xl" aria-labelledby="docs-heading">
                <h3 id="docs-heading" class="text-lg font-bold text-emerald-300 border-b border-slate-800 pb-2 mb-4">{{ __('account_services.verification_documents') }}</h3>
                <ul class="space-y-3 text-sm" role="list">
                    @foreach ($documents as $doc)
                        <li class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800/60 pb-2"><span class="text-slate-300">{{ __('account_services.verification_document_type_'.$doc['document_type']) }}</span><span class="font-semibold {{ $doc['status'] === 'verified' ? 'text-emerald-300' : 'text-amber-300' }}">{{ __('account_services.verification_status_'.strtoupper((string) ($doc['status'] ?? 'NOT_SUBMITTED'))) }}</span><span class="text-slate-500">{{ $doc['created_at'] ?: __('account_services.not_recorded') }}</span><a href="{{ route('account.verification.document', ['document' => $doc['download_token']]) }}" class="font-medium text-emerald-400 hover:text-emerald-300">{{ __('account_services.verification_download') }}</a></li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</div>
@else
<div class="next-public-page next-public-page--verification" data-next-public-page="verification">
    <a class="pp-skip-link" href="#verification-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header"><div class="next-shell next-page-header__inner"><a class="next-brand" href="{{ route('home') }}" aria-label="{{ trans('account_info.verification_home_aria') }}"><span class="next-brand__mark">TL</span><span>{{ config('app.name') }}<small>{{ trans('account_info.verification_brand_subtitle') }}</small></span></a><nav class="next-nav" aria-label="{{ trans('account_info.verification_primary_nav') }}"><a href="{{ route('home') }}">{{ trans('account_info.verification_nav_home') }}</a><a href="{{ route('about') }}">{{ trans('account_info.verification_nav_about') }}</a><a class="is-active" href="{{ route('account.verification') }}" aria-current="page">{{ trans('account_info.verification_nav_verification') }}</a><a href="{{ route('account.grade') }}">{{ trans('account_info.verification_nav_grades') }}</a><a href="{{ route('contact') }}">{{ trans('account_info.verification_nav_contact') }}</a></nav>@guest<a class="next-button next-button--gold" href="{{ route('login') }}">{{ trans('account_info.verification_login') }}</a>@else<a class="next-button next-button--gold" href="{{ route('account.verification') }}">{{ trans('account_info.verification_my_verification') }}</a>@endguest</div></header>
    <main id="verification-main" class="next-shell next-content" tabindex="-1">
        <section class="next-hero" aria-labelledby="verification-title"><div><p class="next-eyebrow">{{ trans('account_info.verification_eyebrow') }}</p><h1 id="verification-title">{{ trans('account_info.verification_title') }}</h1><p>{{ $verification['meta_description'] ?? trans('account_info.verification_meta_description') }}</p><p class="next-note">{{ trans('account_info.verification_public_note') }}</p></div><div class="next-hero-object next-hero-object--verification" aria-hidden="true"><span>✓</span></div></section>
        <section class="next-meta-strip" aria-label="{{ trans('account_info.verification_title') }}"><div><span>{{ trans('account_info.verification_guide_status') }}</span><strong>{{ $status }}</strong></div><div><span>{{ trans('account_info.verification_document_types') }}</span><strong>{{ count($documents) ?: 'NOT_CONFIGURED' }}</strong></div><div><span>{{ trans('account_info.verification_max_upload') }}</span><strong>{{ ($verification['max_upload_mb'] ?? 0) > 0 ? $verification['max_upload_mb'].' MB' : 'NOT_CONFIGURED' }}</strong></div><div><span>{{ trans('account_info.verification_access') }}</span><strong>{{ trans('account_info.verification_authenticated') }}</strong></div></section>
        <div class="next-layout"><aside class="next-sidebar" aria-label="{{ trans('account_info.verification_contents') }}"><p class="next-eyebrow">{{ trans('account_info.verification_guide_map') }}</p>@foreach ($steps as $step)<a href="#verification-step-{{ $loop->iteration }}" data-content-link="verification-step-{{ $loop->iteration }}">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} · {{ trans('account_info.step_'.$step['key'].'_title') }}</a>@endforeach<a href="#verification-documents" data-content-link="verification-documents">{{ trans('account_info.verification_documents') }}</a></aside><div>
            @forelse ($steps as $step)
                <section class="next-section" id="verification-step-{{ $loop->iteration }}" data-content-section aria-labelledby="verification-step-{{ $loop->iteration }}-title"><div class="next-section__heading"><span class="next-section__number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><div><p class="next-eyebrow">{{ trans('account_info.verification_workflow') }}</p><h2 id="verification-step-{{ $loop->iteration }}-title">{{ trans('account_info.step_'.$step['key'].'_title') }}</h2></div></div><div class="next-section__body"><p>{{ trans('account_info.step_'.$step['key'].'_text') }}</p></div></section>
            @empty
                <section class="next-panel" role="status"><h2>{{ trans('account_info.verification_guide_unavailable') }}</h2><p>{{ trans('account_info.verification_no_steps') }}</p></section>
            @endforelse
            <section class="next-section" id="verification-documents" data-content-section aria-labelledby="verification-documents-title"><div class="next-section__heading"><span class="next-section__number">{{ trans('account_info.verification_document_marker') }}</span><div><p class="next-eyebrow">{{ trans('account_info.verification_private_policy') }}</p><h2 id="verification-documents-title">{{ trans('account_info.verification_configured_documents') }}</h2></div></div><div class="next-section__body">@if ($documents === [])<p class="next-empty">NOT_CONFIGURED</p>@else<p>{{ trans('account_info.verification_only_authenticated') }}</p><ul>@foreach ($documents as $document)<li>{{ $document }}</li>@endforeach</ul>@endif<p class="next-muted">{{ trans('account_info.verification_no_public_identity') }}</p></div></section>
        </div></div>
        <section class="next-bottom"><div><p class="next-eyebrow">{{ trans('account_info.verification_private_workflow') }}</p><h2>{{ trans('account_info.verification_continue_title') }}</h2><p>{{ trans('account_info.verification_continue_text') }}</p></div><div class="next-bottom__links">@guest<a class="next-button next-button--gold" href="{{ route('login') }}">{{ trans('account_info.verification_sign_in') }}</a>@else<a class="next-button next-button--gold" href="{{ route('account.verification') }}">{{ trans('account_info.verification_open') }}</a>@endguest<a class="next-button" href="{{ route('contact') }}">{{ trans('account_info.verification_contact') }}</a></div></section>
    </main>
</div>
@endif
@endsection

@push('scripts')
    @vite('resources/js/pages/account-verification.js')
@endpush
