@extends('layouts.app')

@section('title', trans('privacy_requests.account.title'))
@section('meta_description', trans('privacy_requests.account.intro'))
@section('meta_robots', 'noindex,nofollow')

{{--
    THE REQUESTER'S OWN AREA.

    Three things this page will not do, each of them deliberate:

    1. It never shows an address or a decrypted description. The list is built
       from the request rows only; the free text a person wrote lives encrypted
       in the database and is visible to the reviewer in the panel, not echoed
       back here where a shared screen could expose it.
    2. It never offers a way to delete a request or an account. Cancelling is a
       lifecycle move (the record stays, the status changes), and erasure is a
       reviewed task - promising one-click deletion would be a promise the
       platform cannot lawfully keep for financial records.
    3. The download link is the SIGNED, EXPIRING one the controller generated and
       only for an export that is ready, unexpired and still within its download
       limit. There is no route that streams an archive from a plain reference.
--}}
@section('content')
@php
    $flash = is_array($status ?? null) ? $status : [];
    $flashState = (string) ($flash['state'] ?? '');
    $flashIsError = in_array($flashState, ['cannot_submit_open_limit', 'store_failed', 'not_owner', 'cannot_cancel', 'disabled'], true);
@endphp

<div class="mx-auto max-w-5xl space-y-8">
    <header class="border-b border-slate-800 pb-6">
        <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-400">{{ trans('privacy_requests.account.title') }}</p>
        <h1 class="mt-1 text-3xl font-black tracking-tight text-white">{{ trans('privacy_requests.account.heading') }}</h1>
        <p class="mt-2 text-sm leading-6 text-slate-400">{{ trans('privacy_requests.account.intro') }}</p>
    </header>

    @if ($flashState !== '')
        <div class="rounded-xl border p-4 text-sm {{ $flashIsError ? 'border-rose-500/30 bg-rose-950/30 text-rose-200' : 'border-emerald-500/30 bg-emerald-950/30 text-emerald-200' }}"
             role="{{ $flashIsError ? 'alert' : 'status' }}"
             data-privacy-flash="{{ $flashState }}">
            {{ trans('privacy_requests.account.'.$flashState) }}
        </div>
    @endif

    @unless ($enabled)
        <div class="rounded-xl border border-amber-500/30 bg-amber-950/30 p-4 text-sm text-amber-200" role="status">
            {{ trans('privacy_requests.account.disabled') }}
        </div>
    @endunless

    <section class="rounded-3xl border border-slate-800 bg-slate-900/90 p-6 shadow-xl sm:p-8" aria-labelledby="privacy-submit-heading">
        <h2 id="privacy-submit-heading" class="text-xl font-black text-white">{{ trans('privacy_requests.account.submit_heading') }}</h2>

        {{-- Said BEFORE the form, not after the fact: erasure here is a reviewed
             task, and a person is entitled to know that before they choose it. --}}
        <p id="privacy-retention-note" class="mt-3 rounded-xl border border-slate-700/70 bg-slate-950/60 p-3 text-xs leading-5 text-slate-400">
            {{ trans('privacy_requests.account.retention_note') }}
        </p>

        <form method="POST" action="{{ route('account.privacy-requests.store') }}" class="mt-6 space-y-5" enctype="application/x-www-form-urlencoded">
            @csrf

            <div>
                <label for="privacy-type" class="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-400">{{ trans('privacy_requests.account.type_label') }}</label>
                <select id="privacy-type" name="type" required class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white focus:border-emerald-400 focus:outline-none">
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ trans($type->labelKey()) }}</option>
                    @endforeach
                </select>
                @error('type')<p class="mt-2 text-xs text-rose-300" role="alert">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="privacy-details" class="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-400">{{ trans('privacy_requests.account.details_label') }}</label>
                <textarea id="privacy-details" name="details" rows="5" maxlength="{{ (int) config('privacy_requests.details_max_length', 2000) }}"
                          class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white focus:border-emerald-400 focus:outline-none"
                          aria-describedby="privacy-details-hint">{{ old('details') }}</textarea>
                <p id="privacy-details-hint" class="mt-2 text-xs text-slate-500">{{ trans('privacy_requests.account.details_hint') }}</p>
                @error('details')<p class="mt-2 text-xs text-rose-300" role="alert">{{ $message }}</p>@enderror
            </div>

            {{-- The public intake's honeypot field is present but unused here: an authenticated
                 submission is already attributable, so a bot trap would only add noise. --}}

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded-xl bg-emerald-500 px-5 py-3 text-sm font-bold text-slate-950 hover:bg-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-300" @disabled(! $enabled)>
                    {{ trans('privacy_requests.account.submit') }}
                </button>
            </div>
        </form>
    </section>

    <section class="rounded-3xl border border-slate-800 bg-slate-900/90 p-6 shadow-xl sm:p-8" aria-labelledby="privacy-list-heading">
        <h2 id="privacy-list-heading" class="text-xl font-black text-white">{{ trans('privacy_requests.account.heading') }}</h2>

        @if ($requests->isEmpty())
            <p class="mt-4 text-sm text-slate-400" data-privacy-empty>{{ trans('privacy_requests.account.empty') }}</p>
        @else
            <ul class="mt-5 divide-y divide-slate-800">
                @foreach ($requests as $request)
                    @php
                        $export = $exports->get((int) $request->id);
                        $exportUrl = $exportUrls[(int) $request->id] ?? null;
                        $isOpen = $request->status->isOpen();
                    @endphp
                    <li class="py-5" data-privacy-request="{{ $request->reference }}">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="font-mono text-sm text-emerald-300" data-privacy-reference>{{ $request->reference }}</p>
                                <p class="mt-1 text-sm font-bold text-white">{{ trans($request->type->labelKey()) }}</p>
                                <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-1 text-xs text-slate-400 sm:grid-cols-3">
                                    <div>
                                        <dt class="inline text-slate-500">{{ trans('privacy_requests.account.status_label') }}:</dt>
                                        <dd class="inline text-slate-200" data-privacy-state>{{ trans('privacy_requests.statuses.'.$request->status->value) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="inline text-slate-500">{{ trans('privacy_requests.account.created_label') }}:</dt>
                                        <dd class="inline">{{ $request->created_at?->format('Y-m-d') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="inline text-slate-500">{{ trans('privacy_requests.account.due_label') }}:</dt>
                                        <dd class="inline">{{ $request->due_at?->format('Y-m-d') ?? '—' }}</dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                @if ($exportUrl !== null)
                                    <a href="{{ $exportUrl }}" class="rounded-xl border border-emerald-500/40 bg-emerald-950/30 px-4 py-2 text-xs font-bold text-emerald-200 hover:bg-emerald-900/40" rel="nofollow" data-privacy-download>
                                        {{ trans('privacy_requests.account.download') }}
                                    </a>
                                @elseif ($request->status->value === 'in_progress')
                                    <span class="text-xs text-slate-500" data-privacy-download-unavailable>{{ trans('privacy_requests.account.download_unavailable') }}</span>
                                @endif

                                @if ($isOpen)
                                    <form method="POST" action="{{ route('account.privacy-requests.cancel', ['reference' => $request->reference]) }}"
                                          onsubmit="return confirm('{{ trans('privacy_requests.account.cancel_confirm') }}');">
                                        @csrf
                                        <button type="submit" class="rounded-xl border border-slate-700 px-4 py-2 text-xs font-bold text-slate-300 hover:border-rose-500/40 hover:text-rose-200">
                                            {{ trans('privacy_requests.account.cancel') }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
@endsection
