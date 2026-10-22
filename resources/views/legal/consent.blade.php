@extends('layouts.app')

@section('title', trans('public_pages.legal_consent_title'))
@section('meta_description', trans('public_pages.legal_consent_summary'))

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
    <header class="mb-8">
        <p class="text-sm font-semibold uppercase tracking-widest text-emerald-400">{{ config('app.name') }}</p>
        <h1 class="mt-2 text-3xl font-bold text-slate-100">{{ trans('public_pages.legal_consent_title') }}</h1>
        <p class="mt-3 text-sm leading-6 text-slate-300">{{ trans('public_pages.legal_consent_summary') }}</p>
        @if ($accepted)
            <p class="mt-4 rounded-lg border border-emerald-700 bg-emerald-950/40 p-3 text-sm text-emerald-200" role="status">
                {{ trans('public_pages.legal_consent_recorded') }}
            </p>
        @endif
    </header>

    <div class="grid gap-6 md:grid-cols-2">
        @foreach (['terms' => $terms, 'privacy' => $privacy] as $documentType => $document)
            <section class="rounded-xl border border-slate-700 bg-slate-900 p-5">
                <div class="flex items-start justify-between gap-4">
                    <h2 class="text-xl font-semibold text-slate-100">{{ $document['title'] ?? ucfirst($documentType) }}</h2>
                    <span class="shrink-0 rounded-full bg-slate-800 px-3 py-1 text-xs text-slate-300">
                        {{ trans('public_pages.legal_document_version') }} {{ $document['version'] ?? 'NOT_CONFIGURED' }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-slate-400">{{ trans('public_pages.legal_effective_date') }} {{ $document['effective_at'] ?? 'NOT_CONFIGURED' }}</p>
                <div class="mt-4 space-y-4 text-sm leading-6 text-slate-300">
                    @foreach (($document['sections'] ?? []) as $section)
                        <article>
                            <h3 class="font-semibold text-slate-100">{{ $section['title'] ?? '' }}</h3>
                            <p class="mt-1">{{ $section['body'] ?? '' }}</p>
                            @foreach (($section['extra'] ?? []) as $paragraph)
                                <p class="mt-1">{{ $paragraph }}</p>
                            @endforeach
                        </article>
                    @endforeach
                </div>
                <p class="mt-5 text-sm">
                    <a class="text-emerald-400 underline" href="{{ $documentType === 'terms' ? route('terms') : route('privacy') }}" target="_blank" rel="noopener noreferrer">
                        {{ $documentType === 'terms' ? trans('public_pages.register_terms_link') : trans('public_pages.register_privacy_link') }}
                    </a>
                </p>
            </section>
        @endforeach
    </div>

    @if ($available)
    <form method="POST" action="{{ route('legal.consent.store') }}" class="mt-8 space-y-5 rounded-xl border border-slate-700 bg-slate-900 p-6">
        @csrf
        <input type="hidden" name="terms_version" value="{{ $terms['version'] }}">
        <input type="hidden" name="terms_hash" value="{{ $terms['content_hash'] }}">
        <input type="hidden" name="privacy_version" value="{{ $privacy['version'] }}">
        <input type="hidden" name="privacy_hash" value="{{ $privacy['content_hash'] }}">
        @error('legal_documents') <p class="text-sm text-rose-300" role="alert">{{ $message }}</p> @enderror
        <div class="flex items-start gap-3">
            <input id="terms" name="terms" type="checkbox" value="1" required @checked(old('terms')) class="mt-1 h-4 w-4 rounded border-slate-600 bg-slate-950 text-emerald-500">
            <label for="terms" class="text-sm text-slate-200">{{ trans('public_pages.legal_terms_acceptance') }}</label>
        </div>
        @error('terms') <p class="text-sm text-rose-300" role="alert">{{ $message }}</p> @enderror

        <div class="flex items-start gap-3">
            <input id="privacy_notice" name="privacy_notice" type="checkbox" value="1" required @checked(old('privacy_notice')) class="mt-1 h-4 w-4 rounded border-slate-600 bg-slate-950 text-emerald-500">
            <label for="privacy_notice" class="text-sm text-slate-200">{{ trans('public_pages.legal_privacy_acknowledgement') }}</label>
        </div>
        @error('privacy_notice') <p class="text-sm text-rose-300" role="alert">{{ $message }}</p> @enderror

        <button type="submit" class="rounded-lg bg-emerald-500 px-5 py-3 font-semibold text-slate-950 hover:bg-emerald-400">
            {{ trans('public_pages.legal_consent_submit') }}
        </button>
    </form>
    @else
        <p class="mt-8 rounded-xl border border-rose-700 bg-rose-950/40 p-4 text-sm text-rose-200" role="alert">
            {{ trans('public_pages.legal_content_unavailable') }}
        </p>
    @endif
</div>
@endsection
