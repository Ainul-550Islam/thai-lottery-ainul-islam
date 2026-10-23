@extends('layouts.app')

@section('title', trans('public_pages.legal_consent_title'))
@section('meta_description', trans('public_pages.legal_consent_summary'))

@section('content')
<div class="mx-auto max-w-4xl py-6 space-y-8">
    <header class="tl-v2-card tl-v2-card--hero">
        <p class="tl-v2-eyebrow">{{ config('app.name') }}</p>
        <h1 class="mt-2 text-3xl font-black text-[#FFF6D6]">{{ trans('public_pages.legal_consent_title') }}</h1>
        <p class="mt-3 text-sm leading-relaxed text-[#D4CAB3]">{{ trans('public_pages.legal_consent_summary') }}</p>
        @if ($accepted)
            <p class="mt-4 tl-v2-subcard border-emerald-500/40 text-sm text-emerald-200" role="status">
                {{ trans('public_pages.legal_consent_recorded') }}
            </p>
        @endif
    </header>

    <div class="grid gap-6 md:grid-cols-2">
        @foreach (['terms' => $terms, 'privacy' => $privacy] as $documentType => $document)
            <section class="tl-v2-card flex flex-col justify-between">
                <div>
                    <div class="flex items-start justify-between gap-4 border-b border-[#D4AF37]/15 pb-3">
                        <h2 class="text-xl font-extrabold text-[#FFF6D6]">{{ $document['title'] ?? ucfirst($documentType) }}</h2>
                        <span class="tl-v2-pill tl-v2-pill--configured">
                            {{ trans('public_pages.legal_document_version') }} {{ $document['version'] ?? 'NOT_CONFIGURED' }}
                        </span>
                    </div>
                    <p class="mt-2 text-xs text-[#9D937D] font-mono">{{ trans('public_pages.legal_effective_date') }} {{ $document['effective_at'] ?? 'NOT_CONFIGURED' }}</p>
                    <div class="mt-4 space-y-4 text-sm leading-relaxed text-[#D4CAB3]">
                        @foreach (($document['sections'] ?? []) as $section)
                            <article>
                                <h3 class="font-bold text-[#FFF6D6]">{{ $section['title'] ?? '' }}</h3>
                                <p class="mt-1">{{ $section['body'] ?? '' }}</p>
                                @foreach (($section['extra'] ?? []) as $paragraph)
                                    <p class="mt-1">{{ $paragraph }}</p>
                                @endforeach
                            </article>
                        @endforeach
                    </div>
                </div>
                <p class="mt-5 pt-3 border-t border-[#D4AF37]/15 text-sm">
                    <a class="text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4 font-bold" href="{{ $documentType === 'terms' ? route('terms') : route('privacy') }}" target="_blank" rel="noopener noreferrer">
                        {{ $documentType === 'terms' ? trans('public_pages.register_terms_link') : trans('public_pages.register_privacy_link') }}
                    </a>
                </p>
            </section>
        @endforeach
    </div>

    @if ($available)
        <form method="POST" action="{{ route('legal.consent.store') }}" class="tl-v2-card space-y-5">
            @csrf
            <input type="hidden" name="terms_version" value="{{ $terms['version'] }}">
            <input type="hidden" name="terms_hash" value="{{ $terms['content_hash'] }}">
            <input type="hidden" name="privacy_version" value="{{ $privacy['version'] }}">
            <input type="hidden" name="privacy_hash" value="{{ $privacy['content_hash'] }}">
            @error('legal_documents') <p class="text-sm text-rose-300" role="alert">{{ $message }}</p> @enderror

            <div class="flex items-start gap-3 tl-v2-subcard">
                <input id="terms" name="terms" type="checkbox" value="1" required @checked(old('terms')) class="mt-1 h-5 w-5 rounded border-[#D4AF37]/40 bg-[#0B0904] accent-[#D4AF37]">
                <label for="terms" class="text-sm text-[#FAF6EC] leading-relaxed">{{ trans('public_pages.legal_terms_acceptance') }}</label>
            </div>
            @error('terms') <p class="text-sm text-rose-300" role="alert">{{ $message }}</p> @enderror

            <div class="flex items-start gap-3 tl-v2-subcard">
                <input id="privacy_notice" name="privacy_notice" type="checkbox" value="1" required @checked(old('privacy_notice')) class="mt-1 h-5 w-5 rounded border-[#D4AF37]/40 bg-[#0B0904] accent-[#D4AF37]">
                <label for="privacy_notice" class="text-sm text-[#FAF6EC] leading-relaxed">{{ trans('public_pages.legal_privacy_acknowledgement') }}</label>
            </div>
            @error('privacy_notice') <p class="text-sm text-rose-300" role="alert">{{ $message }}</p> @enderror

            <button type="submit" class="tl-v2-btn-gold">
                {{ trans('public_pages.legal_consent_submit') }}
            </button>
        </form>
    @else
        <p class="tl-v2-card border-rose-500/45 text-sm text-rose-200" role="alert">
            {{ trans('public_pages.legal_content_unavailable') }}
        </p>
    @endif
</div>
@endsection
