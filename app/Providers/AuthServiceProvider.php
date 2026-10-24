<?php

namespace App\Providers;

use App\Models\AccountGradeSnapshot;
use App\Models\AccountVerification;
use App\Models\Agent;
use App\Models\Bet;
use App\Models\BlockedIp;
use App\Models\Chat\ChatConversation;
use App\Models\Draw;
use App\Models\FraudAlert;
use App\Models\GloL6TicketReservation;
use App\Models\GloPrizeClaim;
use App\Models\GloTicket;
use App\Models\GloTicketFreeze;
use App\Models\GradeDiscountSnapshot;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\UploadScanRecord;
use App\Models\Wallet;
use App\Policies\AccountGradePolicy;
use App\Policies\AccountVerificationPolicy;
use App\Policies\AgentPolicy;
use App\Policies\BetPolicy;
use App\Policies\BlockedIpPolicy;
use App\Policies\ChatConversationPolicy;
use App\Policies\DrawPolicy;
use App\Policies\FraudAlertPolicy;
use App\Policies\GloL6TicketPolicy;
use App\Policies\GloPrizeClaimPolicy;
use App\Policies\GloTicketFreezePolicy;
use App\Policies\LedgerPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\TicketPolicy;
use App\Policies\UploadScanRecordPolicy;
use App\Policies\WalletPolicy;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        AccountGradeSnapshot::class => AccountGradePolicy::class,
        GradeDiscountSnapshot::class => AccountGradePolicy::class,
        Wallet::class => WalletPolicy::class,
        LedgerEntry::class => LedgerPolicy::class,
        Bet::class => BetPolicy::class,
        Ticket::class => TicketPolicy::class,
        Draw::class => DrawPolicy::class,
        Payment::class => PaymentPolicy::class,
        Agent::class => AgentPolicy::class,
        AccountVerification::class => AccountVerificationPolicy::class,
        GloTicketFreeze::class => GloTicketFreezePolicy::class,
        GloPrizeClaim::class => GloPrizeClaimPolicy::class,
        GloTicket::class => GloL6TicketPolicy::class,
        GloL6TicketReservation::class => GloL6TicketPolicy::class,
        BlockedIp::class => BlockedIpPolicy::class,
        FraudAlert::class => FraudAlertPolicy::class,
        UploadScanRecord::class => UploadScanRecordPolicy::class,
        ChatConversation::class => ChatConversationPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        Gate::before(function ($user, $ability) {
            return (method_exists($user, 'isActive') ? $user->isActive() : true) && $user->hasRole('super-admin')
                ? true
                : null;
        });

        // /metrics operator gate: staff-or-higher only (super-admin short-circuits above).
        Gate::define('access-metrics', function ($user): bool {
            return $user->hasAnyRole(AdminAccess::PANEL_ROLES);
        });

        // Web operations console boundary. The controller still applies the
        // least-privilege permission for each panel; this gate prevents any
        // unauthenticated or non-operator request from reaching that layer.
        Gate::define('access-admin', function ($user): bool {
            return AdminAccess::canAccessPanel($user);
        });

        // Publishing a Terms or Privacy Notice is a controlled system-setting
        // action. The API route also requires an active authenticated operator.
        Gate::define('manage-legal-documents', function ($user): bool {
            return AdminAccess::allows($user, AdminAccess::MANAGE_SYSTEM_SETTINGS);
        });
    }
}
