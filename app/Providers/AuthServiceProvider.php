<?php

namespace App\Providers;

use App\Models\AccountGradeSnapshot;
use App\Models\Agent;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\GloPrizeClaim;
use App\Models\GradeDiscountSnapshot;
use App\Models\GloTicketFreeze;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\Wallet;
use App\Policies\AccountGradePolicy;
use App\Policies\AgentPolicy;
use App\Policies\BetPolicy;
use App\Policies\DrawPolicy;
use App\Policies\GloPrizeClaimPolicy;
use App\Policies\GloTicketFreezePolicy;
use App\Policies\LedgerPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\TicketPolicy;
use App\Policies\WalletPolicy;
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
        \App\Models\AccountVerification::class => \App\Policies\AccountVerificationPolicy::class,
        GloTicketFreeze::class => GloTicketFreezePolicy::class,
        GloPrizeClaim::class => GloPrizeClaimPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        Gate::before(function ($user, $ability) {
            return $user->hasRole('super-admin') ? true : null;
        });

        // /metrics operator gate: staff-or-higher only (super-admin short-circuits above).
        Gate::define('access-metrics', function ($user): bool {
            return $user->hasAnyRole(\App\Support\Admin\AdminAccess::PANEL_ROLES);
        });
    }
}
