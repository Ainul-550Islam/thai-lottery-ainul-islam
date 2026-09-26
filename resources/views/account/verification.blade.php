@extends('layouts.app')

@section('title', $meta['title'])

@section('content')
    <div class="account-services" data-page="account-verification">
        <a class="pp-skip-link" href="#pp-main">{{ trans('public_pages.skip_to_content') }}</a>

        <main id="pp-main" class="pp-main" tabindex="-1">
            <header class="acct-header">
                <h1>{{ trans('account_services.verification_title') }}</h1>
                <p class="acct-header__lead">{{ trans('account_services.verification_lead') }}</p>
            </header>

            @if (session('success'))
                <div class="acct-alert acct-alert--success" role="status" data-verification-flash>
                    {{ session('success') }}
                </div>
            @endif
            @if ($errors->any())
                <div class="acct-alert acct-alert--error" role="alert" data-verification-errors>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="acct-grid">
                <x-account.verification-status
                    :title="trans('account_services.verification_status_label')"
                    :account="$account"
                />

                <x-account.document-upload
                    :title="trans('account_services.verification_form_title')"
                    :action="route('account.verification.submit')"
                    :can-submit="$canSubmit"
                    :document-types="$documentTypes"
                    :max-file-kb="$maxFileKb"
                    :default-country-code="$defaultCountryCode"
                />
            </div>

            <section class="acct-card" aria-labelledby="verif-docs-title">
                <h2 class="acct-card__title" id="verif-docs-title">{{ trans('account_services.verification_documents_title') }}</h2>
                @if (($documents ?? []) === [])
                    <p class="acct-note">{{ trans('account_services.verification_no_documents') }}</p>
                @else
                    <div class="fee-table-wrap" tabindex="0" role="region" aria-label="{{ trans('account_services.verification_documents_title') }}">
                        <table class="fee-table" data-verification-documents>
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">{{ trans('account_services.verification_document_type') }}</th>
                                    <th scope="col">{{ trans('account_services.verification_status_label') }}</th>
                                    <th scope="col">{{ trans('account_services.verification_document_front') }}</th>
                                    <th scope="col"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($documents as $doc)
                                    <tr data-document-id="{{ $doc['id'] }}">
                                        <td>{{ $doc['id'] }}</td>
                                        <td>{{ trans('account_services.verification_document_type_'.$doc['document_type']) }}</td>
                                        <td>{{ $doc['status'] }}</td>
                                        <td>{{ $doc['display_name'] }}</td>
                                        <td>
                                            <a class="acct-link"
                                               href="{{ route('account.verification.document', ['document' => $doc['id']]) }}">
                                                Download
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </main>
    </div>
@endsection
