{{-- Secure document upload form: labels + server-facing file inputs only. --}}
<section class="acct-card" aria-labelledby="doc-upload-title" data-account-document-upload>
    <h2 class="acct-card__title" id="doc-upload-title">{{ $title }}</h2>

    @if (! ($canSubmit ?? true))
        <p class="acct-alert acct-alert--info" role="status" data-verification-blocked>
            {{ trans('account_services.verification_submit_disabled') }}
        </p>
    @else
        <form method="POST"
              action="{{ $action }}"
              enctype="multipart/form-data"
              class="acct-form"
              data-account-verification-form
              novalidate>
            @csrf

            <div class="acct-form__row">
                <div class="acct-field">
                    <label for="country_code">{{ trans('account_services.verification_country_code') }}</label>
                    <input type="text"
                           id="country_code"
                           name="country_code"
                           value="{{ old('country_code', $defaultCountryCode ?? '+66') }}"
                           autocomplete="tel-country-code"
                           inputmode="tel"
                           pattern="\+\d{1,4}"
                           class="acct-input">
                    @error('country_code')
                        <p class="acct-error" id="country_code-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="acct-field">
                    <label for="mobile">{{ trans('account_services.verification_mobile') }}</label>
                    <input type="tel"
                           id="mobile"
                           name="mobile"
                           value="{{ old('mobile') }}"
                           autocomplete="tel-national"
                           inputmode="tel"
                           class="acct-input"
                           aria-describedby="mobile-hint">
                    <p class="acct-hint" id="mobile-hint">
                        {{ trans('account_services.verification_phone_hint') }}
                        <code>{{ trans('account_services.verification_phone_otp') }}</code>
                    </p>
                    @error('mobile')
                        <p class="acct-error" id="mobile-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="acct-field">
                <label for="document_type">{{ trans('account_services.verification_document_type') }}</label>
                <select id="document_type" name="document_type" required class="acct-input" aria-describedby="document_type-error">
                    <option value="">{{ '—' }}</option>
                    @foreach (($documentTypes ?? []) as $type)
                        <option value="{{ $type }}" @selected(old('document_type') === $type)>
                            {{ trans('account_services.verification_document_type_'.$type) }}
                        </option>
                    @endforeach
                </select>
                @error('document_type')
                    <p class="acct-error" id="document_type-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="acct-field">
                <label for="document">{{ trans('account_services.verification_document_front') }}</label>
                <input type="file"
                       id="document"
                       name="document"
                       accept="application/pdf,image/jpeg,image/png,image/webp"
                       required
                       class="acct-input acct-input--file"
                       aria-describedby="document-hint document-error"
                       data-document-input>
                <p class="acct-hint" id="document-hint">
                    {{ str_replace('{max_mb}', (string) round(((int) ($maxFileKb ?? 10240)) / 1024, 1), trans('account_services.verification_document_front_hint')) }}
                </p>
                @error('document')
                    <p class="acct-error" id="document-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="acct-field">
                <label for="document_back">{{ trans('account_services.verification_document_back') }}</label>
                <input type="file"
                       id="document_back"
                       name="document_back"
                       accept="application/pdf,image/jpeg,image/png,image/webp"
                       class="acct-input acct-input--file"
                       aria-describedby="document_back-error">
                @error('document_back')
                    <p class="acct-error" id="document_back-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            {{-- Server ignores any client-supplied status fields by design. --}}

            <div class="acct-form__actions">
                <button type="submit" class="acct-btn" data-verification-submit>
                    {{ trans('account_services.verification_submit') }}
                </button>
            </div>
        </form>
    @endif

    <p class="acct-note">{{ trans('account_services.verification_privacy_note') }}</p>
</section>
