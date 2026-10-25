<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\KycStatus;
use App\Events\AdminOperationCompleted;
use App\Events\ComplianceCaseEscalated;
use App\Events\DepositStatusUpdated;
use App\Events\DrawResultCertified;
use App\Events\FinancialLedgerReconciled;
use App\Events\KycStatusChanged;
use App\Events\MfaChallengeVerified;
use App\Events\NotificationDelivered;
use App\Events\NotificationDeliveryFailed;
use App\Events\PaymentTransactionReconciled;
use App\Events\PayoutBatchCompleted;
use App\Events\PlayerProtectionActionApplied;
use App\Events\PrizeClaimApproved;
use App\Events\PrizeMatched;
use App\Events\PrizePayoutCompleted;
use App\Events\ProviderOperationalStateChanged;
use App\Events\RetailTicketAllocated;
use App\Events\SelfExclusionActivated;
use App\Events\SuspiciousAuthenticationDetected;
use App\Events\WithdrawalKycApproved;
use App\Events\WithdrawalStatusUpdated;
use App\Listeners\Notifications\SendDepositStatusNotification;
use App\Listeners\Notifications\SendKycStatusNotification;
use App\Listeners\Notifications\SendPrizePayoutNotification;
use App\Listeners\Notifications\SendWithdrawalStatusNotification;
use App\Listeners\RecordAdminOperationAudit;
use App\Listeners\RecordComplianceActionAudit;
use App\Listeners\RecordDrawCertificationAudit;
use App\Listeners\RecordFinancialReconciliationAudit;
use App\Listeners\RecordMfaVerificationAudit;
use App\Listeners\RecordNotificationAudit;
use App\Listeners\RecordPaymentReconciliationAudit;
use App\Listeners\RecordPayoutBatchAudit;
use App\Listeners\RecordPlayerProtectionActionAudit;
use App\Listeners\RecordPrizeClaimAudit;
use App\Listeners\RecordPrizeDisbursementAudit;
use App\Listeners\RecordProviderOperationAudit;
use App\Listeners\RecordRetailTicketAllocationAudit;
use App\Listeners\RecordSecurityEventAudit;
use App\Listeners\RecordSelfExclusionAudit;
use App\Listeners\RecordWithdrawalKycAudit;
use App\Models\AccountVerification;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\KycDocument;
use App\Models\KycVerification;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Observers\MoneyChainRealtimeObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Event to listener mappings.
     *
     * NOTE: Finance, lottery and payment events/listeners are registered here
     * as they are implemented in their respective phases.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // Prize claim lane: every approval (auto or human) lands one audit
        // row. Discovery is disabled below — anything unlisted here never
        // fires a listener.
        PrizeClaimApproved::class => [
            RecordPrizeClaimAudit::class,
        ],
        // Payout batch lane: the one-time completion of a batch lands one
        // scrubbed, anchor-deduplicated audit row.
        PayoutBatchCompleted::class => [
            RecordPayoutBatchAudit::class,
        ],
        // Money-out KYC gate: every pass (direct or detention-lifted)
        // lands one scrubbed, anchor-deduplicated audit row.
        WithdrawalKycApproved::class => [
            RecordWithdrawalKycAudit::class,
        ],
        // Retail allocation lane: every vendor allocation completion lands
        // exactly one scrubbed, anchor-deduplicated audit row.
        RetailTicketAllocated::class => [
            RecordRetailTicketAllocationAudit::class,
        ],
        // Draw certification lane: every certification act lands exactly
        // one immutable, anchor-deduplicated audit row.
        DrawResultCertified::class => [
            RecordDrawCertificationAudit::class,
        ],
        // Prize-money lane: every match fires one immutable audit row;
        // settlement acts (reservation/disbursement/reversal/failure)
        // ALSO land here, driven synchronously from the settlement court
        // itself via the same scribe's `from()` — money evidence never
        // rides a fire-and-forget queue.
        PrizeMatched::class => [
            RecordPrizeDisbursementAudit::class,
        ],
        // Wallet-liability reconciliation lane (batch-11): every
        // pronouncement/refresh of a reconciliation row (matched or
        // drift) lands one fingerprint-anchored, deduplicated audit
        // row. The service ALSO fires it synchronously inside the same
        // transaction, so evidence never rides a fire-and-forget queue.
        FinancialLedgerReconciled::class => [
            RecordFinancialReconciliationAudit::class,
        ],
        // Payment provider reconciliation lane (batch-12): every
        // pronouncement (matched or drift) lands one fingerprint-
        // anchored, deduplicated audit row. The service also fires the
        // event inside its own transaction.
        PaymentTransactionReconciled::class => [
            RecordPaymentReconciliationAudit::class,
        ],
        // Compliance escalation lane (batch-13): every escalation
        // pronouncement lands one fingerprint-anchored audit row;
        // individual compliance ACTIONS are recorded synchronously by
        // the action service's own static scribe (same transaction).
        ComplianceCaseEscalated::class => [
            RecordComplianceActionAudit::class,
        ],
        // Responsible-gaming lane (batch-14): activation + applied-act
        // envelopes land one anchor-deduplicated audit row each; the
        // services' own static scribes stamp in-transaction alongside.
        SelfExclusionActivated::class => [
            RecordSelfExclusionAudit::class,
        ],
        PlayerProtectionActionApplied::class => [
            RecordPlayerProtectionActionAudit::class,
        ],
        // Account-security lane (batch-15): verification + detection
        // envelopes each land one anchor-deduplicated audit row.
        MfaChallengeVerified::class => [
            RecordMfaVerificationAudit::class,
        ],
        SuspiciousAuthenticationDetected::class => [
            RecordSecurityEventAudit::class,
        ],
        // Notification lane (batch-16): the service's own scribe already
        // stamps the notification row; these envelopes seal the moment.
        NotificationDelivered::class => [
            RecordNotificationAudit::class,
        ],
        NotificationDeliveryFailed::class => [
            RecordNotificationAudit::class,
        ],
        // Admin / provider operations lane (batch-17).
        AdminOperationCompleted::class => [
            RecordAdminOperationAudit::class,
        ],
        ProviderOperationalStateChanged::class => [
            RecordProviderOperationAudit::class,
        ],
        // Player notification lane (batch 3): the four user-facing
        // notifications finally have dispatch sites. Each listener is
        // queued on the notifications lane, handled only after the
        // writing transaction committed, idempotent per (row, status),
        // preference- and suppression-aware through the dispatch service.
        DepositStatusUpdated::class => [
            SendDepositStatusNotification::class,
        ],
        WithdrawalStatusUpdated::class => [
            SendWithdrawalStatusNotification::class,
        ],
        PrizePayoutCompleted::class => [
            SendPrizePayoutNotification::class,
        ],
        KycStatusChanged::class => [
            SendKycStatusNotification::class,
        ],
    ];

    /**
     * Model observers. Registered here, explicitly, for the same reason
     * discovery is disabled below: the publish side of the real-time lane
     * must be readable in one place. MoneyChainRealtimeObserver existed and
     * nothing attached it to a model, so every event it publishes was dead.
     *
     * @var array<class-string<Model>, array<int, class-string>>
     */
    protected $observers = [
        Wallet::class => [MoneyChainRealtimeObserver::class],
        Bet::class => [MoneyChainRealtimeObserver::class],
        Deposit::class => [MoneyChainRealtimeObserver::class],
        Withdrawal::class => [MoneyChainRealtimeObserver::class],
        Draw::class => [MoneyChainRealtimeObserver::class],
        DrawResult::class => [MoneyChainRealtimeObserver::class],
    ];

    public function boot(): void
    {
        parent::boot();

        $this->bridgeKycRowFactsToEvent();
    }

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }

    /**
     * The KYC lane writes its verdicts in four tables through services this
     * provider does not edit, and raised no event a notification could hang
     * on. These model-event bridges turn each row fact into one
     * KycStatusChanged envelope (after commit); the listener dedupes per
     * (user, status), so a review that writes two of these rows still tells
     * the player once.
     */
    private function bridgeKycRowFactsToEvent(): void
    {
        User::updated(static function (User $user): void {
            if (! $user->wasChanged('kyc_status')) {
                return;
            }

            $current = KycStatusChanged::normalise($user->kyc_status);

            if ($current === null) {
                return;
            }

            event(new KycStatusChanged(
                user: $user,
                previousStatus: KycStatusChanged::normalise($user->getOriginal('kyc_status')),
                currentStatus: $current,
                reason: null,
                sourceType: 'users',
                sourceId: (int) $user->getKey(),
            ));
        });

        $fromRow = static function (Model $row, string $table, string $reasonColumn, bool $created): void {
            if (! $created && ! $row->wasChanged('status')) {
                return;
            }

            $current = KycStatusChanged::normalise($row->getAttribute('status'));
            $userId = (int) $row->getAttribute('user_id');

            if ($current === null || $userId <= 0) {
                return;
            }

            $user = User::query()->find($userId);

            if (! $user instanceof User) {
                return;
            }

            $reason = $row->getAttribute($reasonColumn);

            event(new KycStatusChanged(
                user: $user,
                previousStatus: $created ? null : KycStatusChanged::normalise($row->getOriginal('status')),
                currentStatus: $current,
                reason: $current === KycStatus::Rejected->value && is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
                sourceType: $table,
                sourceId: (int) $row->getKey(),
            ));
        };

        KycVerification::created(static fn (KycVerification $row) => $fromRow($row, 'kyc_verifications', 'rejection_reason', true));
        KycVerification::updated(static fn (KycVerification $row) => $fromRow($row, 'kyc_verifications', 'rejection_reason', false));
        AccountVerification::updated(static fn (AccountVerification $row) => $fromRow($row, 'account_verifications', 'review_reason', false));
        KycDocument::updated(static fn (KycDocument $row) => $fromRow($row, 'kyc_documents', 'rejection_reason', false));
    }
}
