<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\GloDealerStatus;
use App\Enums\GloSourceState;
use App\Exceptions\GloDealerException;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\GloDealer;
use App\Models\RetailVendor;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * GLO dealer profile service (GLO-15).
 *
 * Read paths: own profile, own history (profile changes + purchase activity
 * where modeled + optional agent/proxy lane labeled internal).
 *
 * Write paths for profile fields go through GloDealerChangeRequestService
 * only — this service never applies a change-request approval itself, and
 * never lets a dealer mutate verified identity/status directly.
 */
class GloDealerService
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Ensure a dealer profile exists for an active user (idempotent).
     * dealer_type defaults from whether the user already holds a quota
     * retail vendor allocation lane — not from client input.
     */
    public function ensureDealerForUser(User $user): GloDealer
    {
        return $this->db->connection()->transaction(function () use ($user): GloDealer {
            $existing = GloDealer::query()
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $dealerType = RetailVendor::query()->where('status', 'active')->exists()
                ? 'quota'
                : 'non_quota';

            $dealer = GloDealer::create([
                'user_id' => $user->getKey(),
                'retail_vendor_id' => null,
                'agent_id' => null,
                'dealer_ref' => GloDealer::makeDealerRef((int) $user->getKey()),
                'dealer_type' => $dealerType,
                'status' => GloDealerStatus::Pending,
                'verification_state' => 'unverified',
                'display_name' => $user->name ?? ('user-'.$user->getKey()),
                'capabilities' => ['view_own_profile', 'view_own_history', 'submit_change_request', 'update_daily_sales_location'],
                'metadata' => [
                    'source_state' => GloSourceState::InternalReconciled->value,
                    'synthetic_ref' => true,
                ],
            ]);

            AuditLog::create([
                'user_id' => $user->getKey(),
                'action' => AuditAction::Create,
                'risk_level' => RiskLevel::Medium,
                'auditable_type' => GloDealer::class,
                'auditable_id' => $dealer->getKey(),
                'description' => 'glo_dealer_registered',
                'metadata' => [
                    'action_type' => 'glo_dealer_registered',
                    'dealer_ref' => $dealer->dealer_ref,
                    'dealer_type' => $dealer->dealer_type,
                ],
            ]);

            return $dealer;
        });
    }

    /**
     * Public-safe own-profile projection (no credentials, no audit metadata).
     *
     * @return array<string, mixed>
     */
    public function ownProfile(GloDealer $dealer): array
    {
        return [
            'dealer_ref' => $dealer->dealer_ref,
            'dealer_type' => $dealer->dealer_type,
            'status' => $dealer->status->value,
            'verification_state' => $dealer->verification_state,
            'display_name' => $dealer->display_name,
            'address' => $dealer->address,
            'phone' => $dealer->phone,
            'province' => $dealer->province,
            'district' => $dealer->district,
            'subdistrict' => $dealer->subdistrict,
            'sales_location' => $dealer->sales_location,
            'capabilities' => $dealer->capabilities ?? [],
            'linked' => [
                // Presence flags only — no internal vendor/agent secrets.
                'has_retail_channel' => $dealer->retail_vendor_id !== null,
                'has_agent_lane' => $dealer->agent_id !== null,
            ],
            'source_state' => GloSourceState::InternalReconciled->value,
        ];
    }

    /**
     * Dealer-scoped activity history: change requests + own ticket purchases
     * (labeled internal, not "GLO official history") + optional agent/proxy
     * lane when linked.
     *
     * @return array{change_requests: list<array<string, mixed>>, purchases: list<array<string, mixed>>, proxy_authorizations: list<array<string, mixed>>, labels: array<string, string>}
     */
    public function ownHistory(GloDealer $dealer, int $perPage = 20): array
    {
        $perPage = max(1, min($perPage, (int) config('glo.dealer.max_history_page', 100)));

        $changes = $dealer->changeRequests()
            ->orderByDesc('submitted_at')
            ->limit($perPage)
            ->get()
            ->map(static fn ($r): array => [
                'request_reference' => $r->request_reference,
                'request_type' => $r->request_type->value,
                'status' => $r->status->value,
                'requested_value' => $r->requested_value,
                'reason' => $r->reason,
                'submitted_at' => $r->submitted_at?->toIso8601String(),
                'decided_at' => $r->decided_at?->toIso8601String(),
            ])
            ->all();

        $purchases = Ticket::query()
            ->where('user_id', $dealer->user_id)
            ->orderByDesc('created_at')
            ->limit($perPage)
            ->get()
            ->map(static fn (Ticket $t): array => [
                'ticket_reference' => $t->ticket_number,
                'draw_id' => $t->draw_id,
                'status' => $t->status->value,
                'total_amount' => $t->total_amount,
                'created_at' => $t->created_at?->toIso8601String(),
            ])
            ->all();

        // Proxy / authorization history: only the internal agent lane when
        // linked. Labeled as internal — never fabricated as GLO-official.
        $proxy = [];

        if ($dealer->agent_id !== null) {
            $agent = Agent::query()->find($dealer->agent_id);

            if ($agent !== null) {
                $proxy[] = [
                    'lane' => 'internal_agent',
                    'agent_code' => $agent->agent_code,
                    'status' => $agent->status->value,
                    'source_state' => GloSourceState::InternalReconciled->value,
                    'label' => 'Internal agent authorization lane (not a GLO e-Service proxy record)',
                ];
            }
        }

        return [
            'change_requests' => $changes,
            'purchases' => $purchases,
            'proxy_authorizations' => $proxy,
            'labels' => [
                'history' => 'INTERNAL_APPLICATION_HISTORY',
                'proxy' => (string) config('glo.dealer.proxy_history_source', 'internal_agent_lane'),
            ],
        ];
    }

    /**
     * Resolve dealer for the authenticated user (creates pending profile
     * lazily so the API is stable without a separate registration call).
     */
    public function dealerForUser(User $user): GloDealer
    {
        return $this->ensureDealerForUser($user);
    }

    /**
     * Activate a dealer (operator/seeder path only — never called from
     * a dealer-facing mutation).
     */
    public function activate(GloDealer $dealer, ?User $actor = null): GloDealer
    {
        return $this->db->connection()->transaction(function () use ($dealer, $actor): GloDealer {
            $fresh = GloDealer::query()->whereKey($dealer->getKey())->lockForUpdate()->firstOrFail();
            $fresh->status = GloDealerStatus::Active;
            $fresh->verification_state = 'verified';
            $fresh->save();

            AuditLog::create([
                'user_id' => $actor?->getKey(),
                'action' => AuditAction::Update,
                'risk_level' => RiskLevel::Medium,
                'auditable_type' => GloDealer::class,
                'auditable_id' => $fresh->getKey(),
                'description' => 'glo_dealer_activated',
                'metadata' => [
                    'action_type' => 'glo_dealer_activated',
                    'dealer_ref' => $fresh->dealer_ref,
                    'actor_id' => $actor?->getKey(),
                ],
            ]);

            return $fresh;
        });
    }
}
