@extends('layouts.app')

@section('title', trans('privacy_requests.verify.title'))
@section('meta_description', trans('privacy_requests.verify.title'))
@section('meta_robots', 'noindex, nofollow')

{{--
    THE VERIFICATION LANDING PAGE.

    Reachable by anyone holding the link, so it renders the outcome and one
    reference - nothing else. No address, no request type, no description, no
    session cookie requirement, no form. The token is never printed back.

    Four outcomes, each with its own heading and body so that "already used" and
    "expired" are distinguishable to the person who received the mail (they need
    to know whether to submit again), while neither reveals anything about the
    account behind the address.

    The no-store headers the controller sets are what keep this page out of a
    shared browser's history cache; robots noindex keeps it out of a search
    result if the link were ever pasted somewhere public.
--}}
@section('content')
@php
    $outcome = (string) ($outcome ?? 'invalid');
    $headingKey = match ($outcome) {
        'verified' => 'privacy_requests.verify.success_heading',
        'used' => 'privacy_requests.verify.used_heading',
        'expired' => 'privacy_requests.verify.expired_heading',
        default => 'privacy_requests.verify.invalid_heading',
    };
    $bodyKey = match ($outcome) {
        'verified' => 'privacy_requests.verify.success_body',
        'used' => 'privacy_requests.verify.used_body',
        'expired' => 'privacy_requests.verify.expired_body',
        default => 'privacy_requests.verify.invalid_body',
    };
    $isSuccess = $outcome === 'verified';
@endphp

<div class="mx-auto max-w-2xl space-y-6">
    <header class="border-b border-slate-800 pb-6">
        <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-400">{{ trans('privacy_requests.verify.title') }}</p>
        <h1 class="mt-1 text-2xl font-black tracking-tight text-white">{{ trans($headingKey) }}</h1>
    </header>

    <section class="rounded-3xl border p-6 sm:p-8 {{ $isSuccess ? 'border-emerald-500/30 bg-emerald-950/20' : 'border-amber-500/30 bg-amber-950/20' }}"
             role="{{ $isSuccess ? 'status' : 'alert' }}"
             data-privacy-verify-outcome="{{ $outcome }}">
        <p class="text-sm leading-6 {{ $isSuccess ? 'text-emerald-100/85' : 'text-amber-100/85' }}">{{ trans($bodyKey) }}</p>

        @if (is_string($reference ?? null) && $reference !== '')
            <p class="mt-5 text-xs uppercase tracking-wider text-slate-400">{{ trans('privacy_requests.verify.reference_label') }}</p>
            <p class="mt-1 font-mono text-lg text-white" data-privacy-reference>{{ $reference }}</p>
        @endif

        <p class="mt-5 text-xs text-slate-500">{{ trans('privacy_requests.verify.no_store') }}</p>
    </section>

    <div class="flex flex-wrap gap-3">
        <a href="{{ route('privacy') }}" class="rounded-xl border border-slate-700 px-5 py-3 text-sm font-bold text-slate-200 hover:border-emerald-500/40 hover:text-emerald-200">
            {{ trans('public_pages.privacy_request_result_back') }}
        </a>
        <a href="{{ route('contact') }}" class="rounded-xl border border-slate-700 px-5 py-3 text-sm font-bold text-slate-200 hover:border-emerald-500/40 hover:text-emerald-200">
            {{ trans('contact.meta_title') }}
        </a>
    </div>
</div>
@endsection
