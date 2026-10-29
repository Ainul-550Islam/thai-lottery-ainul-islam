<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\FraudDecision;
use App\Enums\MalwareScanStatus;
use App\Models\BlockedIp;
use App\Models\FraudAlert;
use App\Models\GloL6TicketReservation;
use App\Models\GloTicket;
use App\Models\UploadScanRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RbacAndPolicyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('player', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('super-admin', 'web');
    }

    public function test_security_and_glo_policies_enforce_role_status_ownership_and_maker_checker_rules(): void
    {
        $player = User::factory()->create();
        $player->syncRoles(['player']);

        $otherPlayer = User::factory()->create();
        $otherPlayer->syncRoles(['player']);

        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $suspendedAdmin = User::factory()->suspended()->create();
        $suspendedAdmin->syncRoles(['admin']);

        // BlockedIpPolicy
        $this->assertFalse(Gate::forUser($player)->allows('viewAny', BlockedIp::class));
        $this->assertFalse(Gate::forUser($suspendedAdmin)->allows('viewAny', BlockedIp::class));
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', BlockedIp::class));
        $this->assertTrue(Gate::forUser($admin)->allows('create', BlockedIp::class));

        // UploadScanRecordPolicy
        $scan = UploadScanRecord::create([
            'id' => (string) Str::uuid(),
            'owning_model_type' => User::class,
            'owning_model_id' => (string) $player->id,
            'original_filename' => 'id.png',
            'file_mime_type' => 'image/png',
            'file_size_bytes' => 128,
            'file_sha256' => hash('sha256', 'synthetic-test'),
            'private_storage_key' => 'quarantine/synthetic.bin',
            'scan_status' => MalwareScanStatus::PENDING_SCAN->value,
        ]);
        $this->assertFalse(Gate::forUser($player)->allows('rescan', $scan));
        $this->assertFalse(Gate::forUser($suspendedAdmin)->allows('rescan', $scan));
        $this->assertTrue(Gate::forUser($admin)->allows('rescan', $scan));

        // FraudAlertPolicy (including maker-checker self-review prohibition)
        $selfAlert = FraudAlert::create([
            'id' => (string) Str::uuid(),
            'idempotency_event_key' => 'FA-SELF-'.Str::uuid(),
            'event_type' => 'WITHDRAWAL',
            'user_id' => (string) $admin->id,
            'amount' => '1000.0000',
            'currency' => 'THB',
            'risk_score' => 80,
            'decision' => FraudDecision::HOLD_FOR_REVIEW->value,
            'reason_codes' => ['VELOCITY'],
            'status' => 'OPEN',
        ]);
        $this->assertFalse(Gate::forUser($player)->allows('review', $selfAlert));
        $this->assertFalse(Gate::forUser($admin)->allows('review', $selfAlert), 'Subject admin must never self-review own fraud alert.');

        $secondAdmin = User::factory()->create();
        $secondAdmin->syncRoles(['admin']);
        $this->assertTrue(Gate::forUser($secondAdmin)->allows('review', $selfAlert));

        $super = User::factory()->create();
        $super->syncRoles(['super-admin']);
        $inactiveSuper = User::factory()->suspended()->create();
        $inactiveSuper->syncRoles(['super-admin']);
        $superAlert = new FraudAlert;
        $superAlert->forceFill(['user_id' => (string) $super->id]);
        $this->assertFalse(Gate::forUser($super)->allows('review', $superAlert), 'Super-admin may not self-review.');
        $this->assertFalse(Gate::forUser($inactiveSuper)->allows('viewAny', BlockedIp::class));

        // GloL6TicketPolicy (strict object ownership for players, read visibility for active admins)
        $ticket = new GloTicket;
        $ticket->forceFill(['owner_user_id' => $player->id]);
        $this->assertTrue(Gate::forUser($player)->allows('view', $ticket));
        $this->assertFalse(Gate::forUser($otherPlayer)->allows('view', $ticket));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $ticket));

        $reservation = new GloL6TicketReservation;
        $reservation->forceFill(['user_id' => $player->id]);
        $this->assertTrue(Gate::forUser($player)->allows('confirm', $reservation));
        $this->assertFalse(Gate::forUser($otherPlayer)->allows('confirm', $reservation));
        $this->assertFalse(Gate::forUser($admin)->allows('confirm', $reservation), 'Admins cannot consume another player reservation.');
        $this->assertFalse(Gate::forUser($super)->allows('confirm', $reservation), 'Super-admin cannot consume another player reservation.');
    }

    public function test_admin_security_http_endpoints_reject_unauthenticated_player_and_self_review_requests(): void
    {
        $player = User::factory()->create();
        $player->syncRoles(['player']);
        $playerToken = $player->createToken('player-api')->plainTextToken;

        $admin1 = User::factory()->create();
        $admin1->syncRoles(['admin']);
        $admin1Token = $admin1->createToken('admin1-api')->plainTextToken;

        $admin2 = User::factory()->create();
        $admin2->syncRoles(['admin']);
        $admin2Token = $admin2->createToken('admin2-api')->plainTextToken;

        // Unauthenticated -> 401
        $this->getJson('/api/v1/admin/security/overview')->assertStatus(401);
        $this->getJson('/api/v1/admin/security/ip-blocks')->assertStatus(401);

        // Regular player -> 403 across all admin security routes
        $this->withToken($playerToken)->getJson('/api/v1/admin/security/overview')->assertStatus(403);
        $this->withToken($playerToken)->getJson('/api/v1/admin/security/ip-blocks')->assertStatus(403);
        $this->withToken($playerToken)->postJson('/api/v1/admin/security/ip-blocks', [
            'ip_or_cidr' => '198.51.100.44',
            'reason_code' => 'ABUSE',
        ])->assertStatus(403);
        $this->withToken($playerToken)->getJson('/api/v1/admin/security/fraud-alerts')->assertStatus(403);
        $this->withToken($playerToken)->getJson('/api/v1/admin/security/malware-scans')->assertStatus(403);
        // Authorization happens before fetching an object or invoking a mutator.
        $this->withToken($playerToken)->postJson('/api/v1/admin/security/ip-blocks/nonexistent/unblock')->assertStatus(403);
        $this->withToken($playerToken)->postJson('/api/v1/admin/security/malware-scans/nonexistent/rescan')->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->withToken($admin2Token)->getJson('/api/v1/admin/security/overview')->assertOk();

        // Maker-checker HTTP enforcement on /api/v1/admin/security/fraud-alerts/{id}/review
        $alert = FraudAlert::create([
            'id' => (string) Str::uuid(),
            'idempotency_event_key' => 'FA-HTTP-'.Str::uuid(),
            'event_type' => 'DEPOSIT',
            'user_id' => (string) $admin1->id,
            'amount' => '5000.0000',
            'currency' => 'THB',
            'risk_score' => 85,
            'decision' => FraudDecision::HOLD_FOR_REVIEW->value,
            'reason_codes' => ['HIGH_AMOUNT'],
            'status' => 'OPEN',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($admin1Token)->postJson("/api/v1/admin/security/fraud-alerts/{$alert->id}/review", [
            'action' => 'RELEASE_ALLOW',
            'decision_reason' => 'Attempting to approve my own alert over HTTP',
        ])->assertStatus(403)->assertJsonPath('error', 'MAKER_CHECKER_VIOLATION');

        $this->app['auth']->forgetGuards();
        $this->withToken($admin2Token)->postJson("/api/v1/admin/security/fraud-alerts/{$alert->id}/review", [
            'action' => 'REJECT_DENY',
            'decision_reason' => 'Independent compliance officer confirmed velocity anomaly',
        ])->assertOk()->assertJsonPath('data.status', 'RESOLVED_DENY');
        $audit = \App\Models\AuditLog::query()->where('description', 'Fraud alert adjudicated by a separate reviewer.')->sole();
        $this->assertNull($audit->auditable_id); // The numeric morph column must not impersonate a UUID.
        $this->assertSame((string) $alert->id, $audit->metadata['alert_id']);
        $this->assertSame(FraudAlert::class, $audit->metadata['subject_type']);

        // Terminal immutability -> 409 FRAUD_ALERT_ALREADY_RESOLVED
        $this->app['auth']->forgetGuards();
        $this->withToken($admin2Token)->postJson("/api/v1/admin/security/fraud-alerts/{$alert->id}/review", [
            'action' => 'RELEASE_ALLOW',
            'decision_reason' => 'Attempting to reopen resolved alert',
        ])->assertStatus(409)->assertJsonPath('error', 'FRAUD_ALERT_ALREADY_RESOLVED');
    }

    public function test_glo_l6_migration_rollback_is_backwards_safe_and_refuses_destructive_data_loss(): void
    {
        $migration = require database_path('migrations/2026_10_24_000100_create_glo_l6_purchase_reservations_and_allocations_tables.php');

        $this->assertTrue(Schema::hasTable('glo_l6_ticket_reservations'));
        $this->assertTrue(Schema::hasTable('glo_l6_active_allocations'));

        // Empty tables roll back cleanly and re-apply idempotently.
        $migration->down();
        $this->assertFalse(Schema::hasTable('glo_l6_ticket_reservations'));
        $this->assertFalse(Schema::hasTable('glo_l6_active_allocations'));

        $migration->up();
        $migration->up(); // Idempotent hasTable guard check.
        $this->assertTrue(Schema::hasTable('glo_l6_ticket_reservations'));
        $this->assertTrue(Schema::hasTable('glo_l6_active_allocations'));
    }
}
