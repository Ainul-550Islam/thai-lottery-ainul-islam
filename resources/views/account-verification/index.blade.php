@extends('layouts.app')

@section('title', __('account_services.verification_meta_title'))
@section('meta_description', __('account_services.verification_meta_description'))

@section('content')
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
                    <dd class="font-mono font-semibold text-slate-100" data-av-account-number>{{ $account['account_number'] }}</dd>
                </div>
                <div class="flex justify-between sm:justify-start sm:gap-4">
                    <dt class="text-slate-400">{{ __('account_services.verification_account_name') }}</dt>
                    <dd class="font-semibold text-slate-100">{{ $account['name'] }}</dd>
                </div>
                <div class="flex justify-between sm:justify-start sm:gap-4">
                    <dt class="text-slate-400">{{ __('account_services.verification_account_email') }}</dt>
                    <dd class="font-semibold text-slate-100">{{ $account['email'] }}</dd>
                </div>
                <div class="flex justify-between sm:justify-start sm:gap-4">
                    <dt class="text-slate-400">{{ __('account_services.verification_account_join') }}</dt>
                    <dd class="font-semibold text-slate-100">{{ $account['join_date'] }}</dd>
                </div>
                <div class="flex justify-between sm:justify-start sm:gap-4">
                    <dt class="text-slate-400">{{ __('account_services.verification_account_renew') }}</dt>
                    <dd class="font-semibold text-slate-100">{{ $account['renew_date'] }}</dd>
                </div>
                <div class="flex justify-between sm:justify-start sm:gap-4">
                    <dt class="text-slate-400">{{ __('account_services.verification_account_status') }}</dt>
                    <dd class="font-semibold {{ $account['verification_status'] === 'APPROVED' ? 'text-emerald-300' : 'text-amber-300' }}"
                        data-av-status>{{ $account['verification_status'] }}</dd>
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
                <form class="space-y-5" action="{{ route('account.verification.submit') }}" method="POST"
                      enctype="multipart/form-data" novalidate>
                    @csrf

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label for="country_code" class="block text-sm font-medium text-slate-300 mb-1">
                                {{ __('account_services.verification_country_code') }}
                            </label>
                            <select id="country_code" name="country_code"
                                    class="block w-full px-4 py-3 border border-slate-700 bg-slate-950 text-slate-100 rounded-xl sm:text-sm">
                                @foreach ($countryCodes as $code)
                                    <option value="{{ $code }}" @selected($code === $defaultCountryCode)>{{ $code }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="mobile" class="block text-sm font-medium text-slate-300 mb-1">
                                {{ __('account_services.verification_mobile') }}
                            </label>
                            <input id="mobile" name="mobile" type="text" inputmode="numeric" value="{{ old('mobile') }}"
                                   aria-describedby="av-mobile-hint"
                                   @if ($errors->has('mobile')) aria-invalid="true" @endif
                                   class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('mobile') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}"
                                   placeholder="812345678">
                            <p id="av-mobile-hint" class="mt-1 text-xs text-slate-500">
                                {{ __('account_services.verification_mobile_hint') }}
                            </p>
                            @if ($errors->has('mobile'))
                                <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('mobile') }}</p>
                            @endif
                        </div>
                    </div>

                    <div>
                        <label for="document_type" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('account_services.verification_document_type') }}
                        </label>
                        <select id="document_type" name="document_type" required aria-required="true"
                                @if ($errors->has('document_type')) aria-invalid="true" @endif
                                class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('document_type') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100' }}">
                            <option value="">{{ __('account_services.verification_select_type') }}</option>
                            @foreach ($documentTypes as $type)
                                <option value="{{ $type }}" @selected(old('document_type') === $type)>
                                    {{ __('account_services.verification_document_type_'.$type) }}
                                </option>
                            @endforeach
                        </select>
                        @if ($errors->has('document_type'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('document_type') }}</p>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label for="document" class="block text-sm font-medium text-slate-300 mb-1">
                                {{ __('account_services.verification_document_front') }}
                                @if ($requireBack)<span class="text-rose-400" aria-hidden="true">*</span>@endif
                            </label>
                            <input id="document" name="document" type="file" required aria-required="true"
                                   accept=".pdf,.jpg,.jpeg,.png,.webp"
                                   aria-describedby="av-front-hint"
                                   @if ($errors->has('document')) aria-invalid="true" @endif
                                   class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('document') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100' }}">
                            <p id="av-front-hint" class="mt-1 text-xs text-slate-500">
                                {{ __('account_services.verification_upload_hint', ['kb' => $maxFileKb]) }}
                            </p>
                            @if ($errors->has('document'))
                                <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('document') }}</p>
                            @endif
                        </div>

                        <div>
                            <label for="document_back" class="block text-sm font-medium text-slate-300 mb-1">
                                {{ __('account_services.verification_document_back') }}
                                @if ($requireBack)<span class="text-rose-400" aria-hidden="true">*</span>@endif
                            </label>
                            <input id="document_back" name="document_back" type="file"
                                   {{ $requireBack ? 'required aria-required="true"' : '' }}
                                   accept=".pdf,.jpg,.jpeg,.png,.webp"
                                   aria-describedby="av-back-hint"
                                   @if ($errors->has('document_back')) aria-invalid="true" @endif
                                   class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('document_back') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100' }}">
                            <p id="av-back-hint" class="mt-1 text-xs text-slate-500">
                                {{ __('account_services.verification_upload_hint', ['kb' => $maxFileKb]) }}
                            </p>
                            @if ($errors->has('document_back'))
                                <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('document_back') }}</p>
                            @endif
                        </div>
                    </div>

                    <div>
                        <button type="submit" class="w-full sm:w-auto flex justify-center py-3 px-6 border border-transparent text-sm font-bold rounded-xl text-slate-950 bg-gradient-to-r from-amber-400 via-emerald-400 to-emerald-500 hover:from-amber-500 hover:to-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 shadow-lg transition duration-200">
                            {{ __('account_services.verification_submit') }}
                        </button>
                    </div>
                </form>
            @endif
        </section>

        {{-- ================= Submission history (member-safe) ================= --}}
        @if (count($history) > 0)
            <section class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-2xl" aria-labelledby="history-heading">
                <h3 id="history-heading" class="text-lg font-bold text-emerald-300 border-b border-slate-800 pb-2 mb-4">
                    {{ __('account_services.verification_history') }}
                </h3>

                <ul class="space-y-3 text-sm" role="list">
                    @foreach ($history as $entry)
                        <li class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800/60 pb-2">
                            <span class="font-mono text-slate-300">{{ $entry['reference'] }}</span>
                            <span class="font-semibold {{ $entry['status'] === 'APPROVED' ? 'text-emerald-300' : ($entry['status'] === 'REJECTED' ? 'text-rose-300' : 'text-amber-300') }}">
                                {{ $entry['status'] }}
                            </span>
                            <span class="text-slate-500">{{ $entry['submitted_at'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- ================= Documents (metadata only, authorized download) ================= --}}
        @if (count($documents) > 0)
            <section class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-2xl" aria-labelledby="docs-heading">
                <h3 id="docs-heading" class="text-lg font-bold text-emerald-300 border-b border-slate-800 pb-2 mb-4">
                    {{ __('account_services.verification_documents') }}
                </h3>

                <ul class="space-y-3 text-sm" role="list">
                    @foreach ($documents as $doc)
                        <li class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800/60 pb-2">
                            <span class="text-slate-300">{{ __('account_services.verification_document_type_'.$doc['document_type']) }}</span>
                            <span class="font-semibold {{ $doc['status'] === 'verified' ? 'text-emerald-300' : 'text-amber-300' }}">{{ $doc['status'] }}</span>
                            <span class="text-slate-500">{{ $doc['created_at'] }}</span>
                            <a href="{{ route('account.verification.document', ['document' => $doc['id']]) }}"
                               class="font-medium text-emerald-400 hover:text-emerald-300">
                                {{ __('account_services.verification_download') }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</div>
@endsection
