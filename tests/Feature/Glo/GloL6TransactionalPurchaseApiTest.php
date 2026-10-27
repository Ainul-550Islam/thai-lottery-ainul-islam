<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\GloL6ReservationStatus;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WalletReservationStatus;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\GloL6ActiveAllocation;
use App\Models\GloL6Sale;
use App\Models\GloL6TicketReservation;
use App\Models\GloTicket;
use App\Models\LedgerEntry;
use App\Models\LegalDocument;
use App\Models\LegalDocumentPublication;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletReservation;
use App\Services\Legal\AgeVerificationService;
use App\Services\Legal\ConsentService;
use App\Services\Lottery\GloL6PurchaseCapabilityService;
use App\Services\Lottery\GloL6TransactionalPurchaseService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class GloL6TransactionalPurchaseApiTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('player', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('super-admin', 'web');

        (new LedgerAccountSeeder)->run();
    }

    public function test_capability_endpoint_defaults_to_not_configured_and_blocks_sales_fail_closed(): void
    {
        $capability = $this->getJson('/api/v1/glo-l6/capability');

        $capability->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', GloL6PurchaseCapabilityService::STATUS_NOT_CONFIGURED)
            ->assertJsonPath('data.enabled', false);

        $this->seedPublishedLegalDocuments();
        $draw = $this->seedOpenSeatedDraw(series: 1, unitsSold: 5);
        [$buyer, $token, $wallet] = $this->makeFundedCompliantPlayer('500.00');

        $attempt = $this->withToken($token)->postJson('/api/v1/glo-l6/reservations', [
            'draw_id' => $draw->id,
            'ticket_number' => '123456',
            'set_series' => 1,
            'idempotency_key' => 'blocked-default-001',
        ]);

        $attempt->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'glo_l6_not_configured');

        $wallet->refresh();
        $this->assertSame('500.00', (string) $wallet->balance);
        $this->assertSame('0.00', (string) $wallet->locked_balance);
        $this->assertSame(0, GloTicket::query()->count());
        $this->assertSame(0, GloL6TicketReservation::query()->count());
    }

    public function test_capability_blocks_fixture_fallthrough_and_production_sandbox_inventory(): void
    {
        $this->seedPublishedLegalDocuments();
        $draw = $this->seedOpenSeatedDraw(series: 1, unitsSold: 5);
        $this->enableSandboxGloPurchaseGates();

        $service = app(GloL6PurchaseCapabilityService::class);
        $ready = $service->capability($draw, 1);
        $this->assertTrue($ready['enabled']);
        $this->assertSame(GloL6PurchaseCapabilityService::STATUS_CONFIGURED, $ready['status']);

        // Enabling fixture fallthrough MUST immediately flip capability to NOT_CONFIGURED.
        config(['glo.sources.fall_through_to_fixture' => true]);
        $blockedByFixture = $service->capability($draw, 1);
        $this->assertFalse($blockedByFixture['enabled']);
        $this->assertSame(GloL6PurchaseCapabilityService::STATUS_NOT_CONFIGURED, $blockedByFixture['status']);

        // Restore fixture guard, then simulate production with sandbox_seeded inventory -> MUST fail closed.
        config(['glo.sources.fall_through_to_fixture' => false]);
        $originalEnv = $this->app['env'];
        try {
            $this->app['env'] = 'production';
            $blockedInProd = $service->capability($draw, 1);
            $this->assertFalse($blockedInProd['enabled']);
            $this->assertFalse($blockedInProd['gates']['inventory_provider_ready']);

            // Only custodian_inventory with non-empty provider ref unlocks inventory gate in production.
            config([
                'glo.l6.purchase.inventory_provider_mode' => 'custodian_inventory',
                'glo.l6.purchase.custodian_provider_ref' => 'CUSTODIAN-VAULT-TH-01',
            ]);
            $custodianReady = $service->capability($draw, 1);
            $this->assertTrue($custodianReady['enabled']);
        } finally {
            $this->app['env'] = $originalEnv;
        }
    }

    public function test_end_to_end_reserve_replay_conflict_confirm_ledger_balance_and_ticket_ownership(): void
    {
        $this->seedPublishedLegalDocuments();
        $draw = $this->seedOpenSeatedDraw(series: 2, unitsSold: 10);
        $this->enableSandboxGloPurchaseGates();

        [$buyer, $token, $wallet] = $this->makeFundedCompliantPlayer('500.00');
        [$otherBuyer, $otherToken, $otherWallet] = $this->makeFundedCompliantPlayer('500.00');

        // 1. Reserve slot 045678 in series 2 -> 201 Created, 80.00 THB held in locked_balance.
        $reserve = $this->withToken($token)->postJson('/api/v1/glo-l6/reservations', [
            'draw_id' => $draw->id,
            'ticket_number' => '045678',
            'set_series' => 2,
            'idempotency_key' => 'resv-key-alpha-01',
            'expected_unit_price' => '80.00',
        ]);

        $reserve->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.reservation.ticket_number', '045678')
            ->assertJsonPath('data.reservation.set_series', 2)
            ->assertJsonPath('data.reservation.status', 'reserved')
            ->assertJsonPath('data.reservation.unit_price', '80.00');

        $reference = (string) $reserve->json('data.reservation.reservation_reference');
        $this->assertNotEmpty($reference);

        $wallet->refresh();
        $this->assertSame('500.00', (string) $wallet->balance);
        $this->assertSame('80.00', (string) $wallet->locked_balance);

        // 2. Idempotent replay with identical payload -> 200 OK, replayed=true, no duplicate lock.
        $replay = $this->withToken($token)->postJson('/api/v1/glo-l6/reservations', [
            'draw_id' => $draw->id,
            'ticket_number' => '045678',
            'set_series' => 2,
            'idempotency_key' => 'resv-key-alpha-01',
            'expected_unit_price' => '80.00',
        ]);

        $replay->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.reservation.reservation_reference', $reference);

        $wallet->refresh();
        $this->assertSame('80.00', (string) $wallet->locked_balance);

        // 3. Reusing same idempotency_key with different ticket_number -> 409 Conflict.
        $conflict = $this->withToken($token)->postJson('/api/v1/glo-l6/reservations', [
            'draw_id' => $draw->id,
            'ticket_number' => '999999',
            'set_series' => 2,
            'idempotency_key' => 'resv-key-alpha-01',
        ]);
        $conflict->assertStatus(409)
            ->assertJsonPath('error.code', 'glo_l6_idempotency_conflict');

        // 4. Another player attempting to reserve the same slot -> 409 glo_l6_ticket_unavailable, zero debit/hold.
        $this->app['auth']->forgetGuards();
        $collision = $this->withToken($otherToken)->postJson('/api/v1/glo-l6/reservations', [
            'draw_id' => $draw->id,
            'ticket_number' => '045678',
            'set_series' => 2,
            'idempotency_key' => 'other-buyer-01',
        ]);
        $collision->assertStatus(409)
            ->assertJsonPath('error.code', 'glo_l6_ticket_already_allocated');
        $this->assertSame('0.00', (string) $otherWallet->fresh()->locked_balance);

        // 5. IDOR check: other player cannot confirm buyer's reservation -> 404 (no existence leak).
        $this->app['auth']->forgetGuards();
        $idorConfirm = $this->withToken($otherToken)->postJson(
            "/api/v1/glo-l6/reservations/{$reference}/confirm",
            ['idempotency_key' => 'idor-attempt-01']
        );
        $idorConfirm->assertStatus(404)
            ->assertJsonPath('error.code', 'glo_l6_reservation_not_found');

        // 6. Owner confirms reservation -> 201 Created, consumes hold, posts balanced BetDebit, issues GloTicket.
        $this->app['auth']->forgetGuards();
        $confirm = $this->withToken($token)->postJson(
            "/api/v1/glo-l6/reservations/{$reference}/confirm",
            ['idempotency_key' => 'confirm-key-alpha-01']
        );

        $confirm->assertStatus(201)
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.reservation.status', 'purchased')
            ->assertJsonPath('data.ticket.ticket_number', '045678')
            ->assertJsonPath('data.ticket.set_series', 2)
            ->assertJsonPath('data.ticket.unit_price', '80.00');

        $ticketRef = (string) $confirm->json('data.ticket.ticket_reference');

        $wallet->refresh();
        $this->assertSame('420.00', (string) $wallet->balance);
        $this->assertSame('0.00', (string) $wallet->locked_balance);

        // Verify double-entry ledger balance on the underlying FinancialTransaction.
        $reservationModel = GloL6TicketReservation::query()->where('reservation_reference', $reference)->firstOrFail();
        $tx = FinancialTransaction::query()->findOrFail((int) $reservationModel->financial_transaction_id);
        $this->assertSame(TransactionType::BetPlacement, $tx->type);
        $this->assertSame(TransactionStatus::Completed, $tx->status);

        $debits = LedgerEntry::query()
            ->where('financial_transaction_id', $tx->id)
            ->where('type', LedgerEntryType::Debit->value)
            ->pluck('amount')
            ->reduce(static fn (string $acc, mixed $v): string => bcadd($acc, (string) $v, 2), '0.00');
        $credits = LedgerEntry::query()
            ->where('financial_transaction_id', $tx->id)
            ->where('type', LedgerEntryType::Credit->value)
            ->pluck('amount')
            ->reduce(static fn (string $acc, mixed $v): string => bcadd($acc, (string) $v, 2), '0.00');

        $this->assertSame('80.00', $debits);
        $this->assertSame('80.00', $credits);
        $this->assertTrue(AuditLog::query()->where('user_id', $buyer->id)->exists());

        // 7. Confirm replay with same idempotency_key -> 200 OK, no second debit.
        $confirmReplay = $this->withToken($token)->postJson(
            "/api/v1/glo-l6/reservations/{$reference}/confirm",
            ['idempotency_key' => 'confirm-key-alpha-01']
        );
        $confirmReplay->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.ticket.ticket_reference', $ticketRef);
        $this->assertSame('420.00', (string) $wallet->fresh()->balance);

        // 8. Ticket listing & IDOR-safe show endpoint.
        $this->withToken($token)->getJson('/api/v1/glo-l6/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($token)->getJson("/api/v1/glo-l6/tickets/{$ticketRef}")
            ->assertOk()
            ->assertJsonPath('data.ticket.ticket_reference', $ticketRef);

        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->getJson("/api/v1/glo-l6/tickets/{$ticketRef}")
            ->assertStatus(404);
    }

    public function test_series_capacity_oversell_expiry_reclamation_snapshot_integrity_and_cancelled_draw_refund(): void
    {
        $this->seedPublishedLegalDocuments();
        // Series capacity strictly 1 unit.
        $draw = $this->seedOpenSeatedDraw(series: 1, unitsSold: 1);
        $this->enableSandboxGloPurchaseGates();

        [$buyerA, $tokenA, $walletA] = $this->makeFundedCompliantPlayer('300.00');
        [$buyerB, $tokenB, $walletB] = $this->makeFundedCompliantPlayer('300.00');

        // Buyer A reserves the sole unit in series 1.
        $resA = $this->withToken($tokenA)->postJson('/api/v1/glo-l6/reservations', [
            'draw_id' => $draw->id,
            'ticket_number' => '111222',
            'set_series' => 1,
            'idempotency_key' => 'cap-a-01',
        ]);
        $resA->assertStatus(201);
        $refA = (string) $resA->json('data.reservation.reservation_reference');

        // Buyer B tries a DIFFERENT 6-digit number in series 1 -> blocked by series capacity (units_sold = 1)!
        $this->app['auth']->forgetGuards();
        $oversell = $this->withToken($tokenB)->postJson('/api/v1/glo-l6/reservations', [
            'draw_id' => $draw->id,
            'ticket_number' => '333444',
            'set_series' => 1,
            'idempotency_key' => 'cap-b-01',
        ]);
        $oversell->assertStatus(409)
            ->assertJsonPath('error.code', 'glo_l6_series_oversold');

        // Tamper with sealed pricing_snapshot in DB -> confirmation MUST detect hash mismatch and refuse!
        $resModelA = GloL6TicketReservation::query()->where('reservation_reference', $refA)->firstOrFail();
        $originalSnapshot = $resModelA->pricing_snapshot;
        $tampered = $originalSnapshot;
        $tampered['unit_price'] = '10.00';
        DB::table('glo_l6_ticket_reservations')->where('id', $resModelA->id)->update([
            'pricing_snapshot' => json_encode($tampered, JSON_THROW_ON_ERROR),
        ]);

        $this->app['auth']->forgetGuards();
        $tamperedConfirm = $this->withToken($tokenA)->postJson(
            "/api/v1/glo-l6/reservations/{$refA}/confirm",
            ['idempotency_key' => 'tamper-confirm-01']
        );
        $tamperedConfirm->assertStatus(422)
            ->assertJsonPath('error.code', 'glo_l6_pricing_mismatch');

        // Restore valid snapshot, age the reservation past TTL, and run expiry sweep.
        $past = now()->subMinutes(10);
        DB::table('glo_l6_ticket_reservations')->where('id', $resModelA->id)->update([
            'pricing_snapshot' => json_encode($originalSnapshot, JSON_THROW_ON_ERROR),
            'reserved_until' => $past,
        ]);
        DB::table('glo_l6_active_allocations')->where('reservation_id', $resModelA->id)->update([
            'reserved_until' => $past,
        ]);
        DB::table('wallet_reservations')->where('id', $resModelA->wallet_reservation_id)->update([
            'expires_at' => $past,
        ]);

        $service = app(GloL6TransactionalPurchaseService::class);
        $reclaimed = $service->reclaimExpiredReservations();
        $this->assertSame(1, $reclaimed);

        $walletA->refresh();
        $this->assertSame('300.00', (string) $walletA->balance);
        $this->assertSame('0.00', (string) $walletA->locked_balance);
        $this->assertSame(GloL6ReservationStatus::Expired, $resModelA->fresh()->status);
        $this->assertSame(WalletReservationStatus::Expired, WalletReservation::findOrFail($resModelA->wallet_reservation_id)->status);

        // Now Buyer B can reserve & explicitly release via DELETE, then purchase immediately in series 1!
        $this->app['auth']->forgetGuards();
        $tempResB = $this->withToken($tokenB)->postJson('/api/v1/glo-l6/reservations', [
            'draw_id' => $draw->id,
            'ticket_number' => '333444',
            'set_series' => 1,
            'idempotency_key' => 'temp-res-b-01',
        ]);
        $tempResB->assertStatus(201);
        $tempRefB = (string) $tempResB->json('data.reservation.reservation_reference');
        $this->assertSame('80.00', (string) $walletB->fresh()->locked_balance);

        $this->withToken($tokenB)->deleteJson("/api/v1/glo-l6/reservations/{$tempRefB}")
            ->assertOk()
            ->assertJsonPath('data.reservation.status', 'released');
        $this->assertSame('0.00', (string) $walletB->fresh()->locked_balance);

        $purchaseB = $this->withToken($tokenB)->postJson('/api/v1/glo-l6/purchases', [
            'draw_id' => $draw->id,
            'ticket_number' => '333444',
            'set_series' => 1,
            'idempotency_key' => 'imm-buy-b-01',
        ]);
        $purchaseB->assertStatus(201)
            ->assertJsonPath('data.ticket.ticket_number', '333444')
            ->assertJsonPath('data.reservation.status', 'purchased');

        $walletB->refresh();
        $this->assertSame('220.00', (string) $walletB->balance);
        $this->assertSame('0.00', (string) $walletB->locked_balance);

        // Cancel the draw and run append-only refund compensation!
        $draw->status = DrawStatus::Cancelled;
        $draw->save();

        $entriesBeforeRefund = LedgerEntry::query()->count();
        $refunded = $service->refundCancelledDrawTickets($draw);
        $this->assertSame(1, $refunded);

        $walletB->refresh();
        $this->assertSame('300.00', (string) $walletB->balance);
        $this->assertSame('0.00', (string) $walletB->locked_balance);
        $this->assertGreaterThan($entriesBeforeRefund, LedgerEntry::query()->count());

        $refundedReservation = GloL6TicketReservation::query()
            ->where('user_id', $buyerB->id)
            ->where('status', GloL6ReservationStatus::Refunded->value)
            ->first();
        $this->assertNotNull($refundedReservation);
        $this->assertNotNull($refundedReservation->refund_financial_transaction_id);

        // Running refundCancelledDrawTickets a second time is a safe idempotent no-op (0 additional refunds).
        $this->assertSame(0, $service->refundCancelledDrawTickets($draw));
        $this->assertSame('300.00', (string) $walletB->fresh()->balance);

        // Migration rollback MUST refuse destructive drop while financial reservation rows exist.
        $migration = require database_path('migrations/2026_10_24_000100_create_glo_l6_purchase_reservations_and_allocations_tables.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_concurrent_multiprocess_purchase_race_allows_exactly_one_winner_without_double_allocation(): void
    {
        $this->seedPublishedLegalDocuments();
        $draw = $this->seedOpenSeatedDraw(series: 1, unitsSold: 10);
        $this->enableSandboxGloPurchaseGates();

        [$buyer1, , $wallet1] = $this->makeFundedCompliantPlayer('200.00');
        [$buyer2, , $wallet2] = $this->makeFundedCompliantPlayer('200.00');

        $dbPath = (string) config('database.connections.sqlite.database');
        if (! str_starts_with($dbPath, '/')) {
            $dbPath = base_path($dbPath);
        }

        $workerScript = base_path('tests/Support/glo-l6-purchase-race-worker.php');

        $proc1 = new Process([
            PHP_BINARY,
            $workerScript,
            $dbPath,
            (string) $buyer1->id,
            (string) $draw->id,
            '777888',
            '1',
            'race-worker-1',
        ], base_path());

        $proc2 = new Process([
            PHP_BINARY,
            $workerScript,
            $dbPath,
            (string) $buyer2->id,
            (string) $draw->id,
            '777888',
            '1',
            'race-worker-2',
        ], base_path());

        $proc1->start();
        $proc2->start();
        $proc1->wait();
        $proc2->wait();

        $out1 = json_decode(trim($proc1->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        $out2 = json_decode(trim($proc2->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        $winners = array_values(array_filter([$out1, $out2], static fn (array $r): bool => ($r['ok'] ?? false) === true));
        $losers = array_values(array_filter([$out1, $out2], static fn (array $r): bool => ($r['ok'] ?? false) === false));

        $this->assertCount(1, $winners, 'Exactly one concurrent buyer may win the unique GLO L6 ticket slot.');
        $this->assertCount(1, $losers, 'The losing concurrent buyer must fail closed.');

        $this->assertSame(
            1,
            GloTicket::query()->where('draw_id', $draw->id)->where('ticket_number', '777888')->count()
        );
        $this->assertSame(
            1,
            GloL6ActiveAllocation::query()->where('draw_id', $draw->id)->where('ticket_number', '777888')->count()
        );

        $combinedBalances = bcadd((string) $wallet1->fresh()->balance, (string) $wallet2->fresh()->balance, 2);
        $this->assertSame('320.00', $combinedBalances, 'Exactly one 80.00 THB debit may occur across both racing buyers.');
        $this->assertSame('0.00', (string) $wallet1->fresh()->locked_balance);
        $this->assertSame('0.00', (string) $wallet2->fresh()->locked_balance);
    }

    public function test_compliance_gates_reject_underage_suspended_and_unconsented_players(): void
    {
        $this->seedPublishedLegalDocuments();
        $draw = $this->seedOpenSeatedDraw(series: 1, unitsSold: 5);
        $this->enableSandboxGloPurchaseGates();

        // Underage player (18 years old; Thai GLO statutory minimum is 20).
        $underage = User::factory()->create([
            'date_of_birth' => now()->subYears(18)->toDateString(),
        ]);
        $underage->syncRoles(['player']);
        $this->acceptCurrentLegalDocumentsFor($underage);
        $underageToken = $underage->createToken('api')->plainTextToken;

        $this->withToken($underageToken)->postJson('/api/v1/glo-l6/purchases', [
            'draw_id' => $draw->id,
            'ticket_number' => '555666',
            'set_series' => 1,
            'idempotency_key' => 'underage-01',
        ])->assertStatus(403);

        // Adult player WITH date_of_birth + age attestation, WITHOUT accepting current legal documents -> 428.
        $unconsented = User::factory()->create([
            'date_of_birth' => '1990-05-10',
        ]);
        $unconsented->syncRoles(['player']);
        app(AgeVerificationService::class)->attest(
            $unconsented,
            Request::create('/account/age-verification', 'POST', ['age_attestation' => true]),
            null
        );
        $unconsentedToken = $unconsented->createToken('api')->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->withToken($unconsentedToken)->postJson('/api/v1/glo-l6/purchases', [
            'draw_id' => $draw->id,
            'ticket_number' => '555666',
            'set_series' => 1,
            'idempotency_key' => 'unconsented-01',
        ])->assertStatus(428);

        // Suspended player -> 403.
        $suspended = User::factory()->suspended()->create([
            'date_of_birth' => '1988-03-15',
        ]);
        $suspended->syncRoles(['player']);
        app(AgeVerificationService::class)->attest(
            $suspended,
            Request::create('/account/age-verification', 'POST', ['age_attestation' => true]),
            null
        );
        $this->acceptCurrentLegalDocumentsFor($suspended);
        $suspendedToken = $suspended->createToken('api')->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->withToken($suspendedToken)->postJson('/api/v1/glo-l6/purchases', [
            'draw_id' => $draw->id,
            'ticket_number' => '555666',
            'set_series' => 1,
            'idempotency_key' => 'suspended-01',
        ])->assertStatus(403);
    }

    private function enableSandboxGloPurchaseGates(): void
    {
        config([
            'real_money.enabled' => true,
            'glo.l6.purchase.enabled' => true,
            'glo.l6.purchase.inventory_provider_mode' => 'sandbox_seeded',
            'glo.l6.purchase.require_official_result_source' => true,
            'glo.official_source.mode' => 'official',
            'glo.official_source.base_url' => 'https://www.glo.or.th',
            'glo.official_source.latest_lottery_path' => '/api/lottery/getLatestLottery',
            'glo.official_source.lottery_result_path' => '/api/checking/getLotteryResult',
            'glo.sources.fall_through_to_fixture' => false,
            'glo.result_sources.fall_through_to_fixture' => false,
        ]);
    }

    private function seedPublishedLegalDocuments(): void
    {
        foreach (['terms', 'privacy'] as $type) {
            $doc = LegalDocument::query()->create([
                'document_type' => $type,
                'locale' => 'en',
                'version' => '2026.1',
                'title' => ucfirst($type).' 2026.1',
                'meta_title' => ucfirst($type),
                'meta_description' => ucfirst($type).' description',
                'sections' => [['heading' => 'Section 1', 'body' => 'Authoritative test legal content.']],
                'content_hash' => hash('sha256', $type.'-2026.1'),
                'effective_at' => now()->subDay()->toDateString(),
                'status' => 'published',
                'published_at' => now()->subDay(),
            ]);

            LegalDocumentPublication::query()->updateOrCreate(
                ['document_type' => $type, 'locale' => 'en'],
                [
                    'legal_document_id' => $doc->id,
                    'published_at' => now()->subDay(),
                ]
            );
        }
    }

    private function seedOpenSeatedDraw(int $series = 1, int $unitsSold = 10): Draw
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::Open,
            'scheduled_at' => now()->addDays(3),
            'betting_open_at' => now()->subMinute(),
            'betting_close_at' => now()->addDays(2),
            'currency' => Currency::THB->value,
        ]);

        GloL6Sale::query()->create([
            'draw_id' => $draw->id,
            'product' => 'l6',
            'seat_key' => sprintf('draw-%d-l6-s%d', $draw->id, $series),
            'series_number' => $series,
            'units_sold' => $unitsSold,
            'units_full' => 1000000,
            'gross_sales' => bcmul((string) $unitsSold, '80.00', 2),
            'sold_fraction' => '0.000010000000',
            'full_allocation_pool' => '48000000.00',
            'proportional_prize_pool' => '480.00',
            'currency' => Currency::THB,
            'ticket_price' => '80.00',
            'source_reference' => 'SYNTHETIC-SEAT-TEST',
            'provenance' => 'operator_seeded_test',
            'metadata' => ['synthetic_test' => true],
        ]);

        return $draw;
    }

    /**
     * @return array{0: User, 1: string, 2: Wallet}
     */
    private function makeFundedCompliantPlayer(string $balance): array
    {
        $user = User::factory()->create([
            'date_of_birth' => '1992-04-12',
        ]);
        $user->syncRoles(['player']);

        app(AgeVerificationService::class)->attest(
            $user,
            Request::create('/account/age-verification', 'POST', ['age_attestation' => true]),
            null
        );

        if (LegalDocumentPublication::query()->count() >= 2) {
            $this->acceptCurrentLegalDocumentsFor($user);
        }

        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'type' => WalletType::Primary->value,
            'currency' => Currency::THB->value,
            'balance' => $balance,
            'locked_balance' => '0.00',
            'status' => WalletStatus::Active->value,
        ]);

        $token = $user->createToken('glo-l6-test')->plainTextToken;

        return [$user, $token, $wallet];
    }

    private function acceptCurrentLegalDocumentsFor(User $user): void
    {
        $req = Request::create('/legal/consent', 'POST', [
            'terms' => true,
            'privacy_notice' => true,
            'terms_version' => '2026.1',
            'terms_hash' => hash('sha256', 'terms-2026.1'),
            'privacy_version' => '2026.1',
            'privacy_hash' => hash('sha256', 'privacy-2026.1'),
        ]);

        app(ConsentService::class)->acceptCurrentDocuments($user, $req);
    }
}
