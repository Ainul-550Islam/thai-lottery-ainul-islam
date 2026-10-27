{{-- Verification status badge + account info (owner only). No decision logic in Blade. --}}
<section class="acct-card" aria-labelledby="verif-status-title" data-account-verification-status>
    <h2 class="acct-card__title" id="verif-status-title">{{ $title }}</h2>

    <dl class="acct-dl">
        <div>
            <dt>{{ trans('account_services.verification_account_number') }}</dt>
            <dd data-verification-account>{{ $account['account_number'] ?? trans('account_services.not_recorded') }}</dd>
        </div>
        <div>
            <dt>{{ trans('account_services.verification_name') }}</dt>
            <dd data-verification-name>{{ $account['name'] ?? trans('account_services.not_recorded') }}</dd>
        </div>
        <div>
            <dt>{{ trans('account_services.verification_email') }}</dt>
            <dd data-verification-email>{{ $account['email'] ?? trans('account_services.not_recorded') }}</dd>
        </div>
        <div>
            <dt>{{ trans('account_services.verification_join_date') }}</dt>
            <dd data-verification-join>{{ $account['join_date'] ?? trans('account_services.not_recorded') }}</dd>
        </div>
        <div>
            <dt>{{ trans('account_services.verification_renew_date') }}</dt>
            <dd data-verification-renew>{{ $account['renew_date'] ?? 'NOT_CONFIGURED' }}</dd>
        </div>
        <div>
            <dt>{{ trans('account_services.verification_status') }}</dt>
            <dd>
                <span class="acct-badge acct-badge--{{ strtolower((string) ($account['verification_status'] ?? 'not_submitted')) }}"
                      role="status"
                      aria-live="polite"
                      data-verification-status="{{ $account['verification_status'] ?? 'NOT_SUBMITTED' }}">
                    {{ trans('account_services.verification_status_'.($account['verification_status'] ?? 'NOT_SUBMITTED')) }}
                </span>
            </dd>
        </div>
        <div>
            <dt>{{ trans('account_services.verification_method') === 'verification_method' ? 'Method' : 'Method' }}</dt>
            <dd data-verification-method>{{ $account['method'] ?? 'DOCUMENT_UPLOAD_VERIFICATION' }}</dd>
        </div>
        <div>
            <dt>Phone verification</dt>
            <dd data-verification-phone-state>{{ $account['phone_verification'] ?? 'PHONE_VERIFICATION_NOT_CONFIGURED' }}</dd>
        </div>
    </dl>

    <p class="acct-note">{{ trans('account_services.verification_review_note') }}</p>
</section>
