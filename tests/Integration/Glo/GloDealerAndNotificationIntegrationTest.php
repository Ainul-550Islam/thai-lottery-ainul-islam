<?php

declare(strict_types=1);

namespace Tests\Integration\Glo;

use App\Enums\UserStatus;
use App\Enums\DrawStatus;
use App\Enums\GloDealerRequestType;
use App\Enums\TicketStatus;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloNotificationDelivery;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Lottery\GloDealerChangeRequestService;
use App\Services\Lottery\GloDealerService;
use App\Services\Lottery\GloResultNotificationService;
use App\Services\Lottery\GloSavedTicketService;
use App\Services\Lottery\GloSalesPointService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * GLO-15..17 end-to-end: dealer e-Service + daily sales location +
 * saved ticket + verified result → idempotent notification.
 */
class GloDealerAndNotificationIntegrationTest extends TestCase
{
    use DatabaseTruncation;

    private User $dealerUser;

    private User $operator;

    private \App\Models\GloDealer $dealer;

    private Draw $openDraw;

    private Draw $resultDraw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->dealerUser = $this->freshUser()->create();
        $this->operator = $this->freshUser()->create();
        $this->operator->syncRoles(['admin']);

        $dealers = app(GloDealerService::class);
        $this->dealer = $dealers->ensureDealerForUser($this->dealerUser);
        $dealers->activate($this->dealer);
        $this->dealer->refresh();

        $this->openDraw = Draw::factory()->open()->create(['scheduled_at' => now()->addDays(5)]);
        $this->resultDraw = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished,
            'scheduled_at' => now()->subDays(8),
            'result_published_at' => now()->subDays(7),
        ]);
    }

    public function test_full_dealer_location_change_notify_flow(): void
    {
        // 1. Dealer daily sales location.
        $sales = app(GloSalesPointService::class);
        $history = $sales->updateDailyLocation($this->dealer, [
            'display_name' => 'Integration Stall',
            'province' => 'Bangkok',
            'latitude' => 13.75,
            'longitude' => 100.5,
        ], $this->dealerUser);
        $eff = $history->effective_date;
        $effStr = $eff instanceof \Illuminate\Support\Carbon ? $eff->toDateString() : (string) $eff;
        $this->assertSame(now()->toDateString(), $effStr);

        // 2. Dealer submits sales_location change request; operator approves.
        $changes = app(GloDealerChangeRequestService::class);
        $row = $changes->submit(
            $this->dealer,
            $this->dealerUser,
            GloDealerRequestType::SalesLocation,
            'New Market|Bangkok|Pathum Wan',
            'relocated',
        );
        $row = $changes->startReview($row, $this->operator);
        $row = $changes->approve($row, $this->operator, 'ok');
        $this->dealer->refresh();
        $this->assertSame('New Market', $this->dealer->sales_location);
        $this->assertSame('Pathum Wan', $this->dealer->district);

        // 3. User saves a ticket on the open draw.
        $ticket = Ticket::create([
            'ticket_number' => 'TK-INTEGRATION-000001',
            'user_id' => $this->dealerUser->getKey(),
            'draw_id' => $this->openDraw->getKey(),
            'currency' => 'THB',
            'metadata' => ['product' => 'l6'],
        ]);
        $ticket->status = TicketStatus::Confirmed;
        $ticket->save();
        $saved = app(GloSavedTicketService::class)->save($this->dealerUser, (int) $ticket->getKey());
        $this->assertTrue($saved['saved']->isActive());

        // 4. Result publishes on a parallel draw path: reassign association for
        //    notification by planting a saved ticket on the result draw (post-draw
        //    save is forbidden — this models the same ticket that was saved pre-draw).
        DrawResult::create([
            'draw_id' => $this->resultDraw->getKey(),
            'first_prize' => '042042',
            'second_prize' => [],
            'third_prize' => [],
            'published_at' => now(),
            'metadata' => ['glo' => ['import_fingerprint' => 'fp-integration-1', 'import_provider' => 'fixture']],
        ]);

        $resultTicket = Ticket::create([
            'ticket_number' => 'TK-INTEGRATION-042042',
            'user_id' => $this->dealerUser->getKey(),
            'draw_id' => $this->resultDraw->getKey(),
            'currency' => 'THB',
            'metadata' => [],
        ]);
        $resultTicket->status = TicketStatus::Confirmed;
        $resultTicket->save();
        \App\Models\GloSavedTicket::create([
            'user_id' => $this->dealerUser->getKey(),
            'ticket_id' => $resultTicket->getKey(),
            'draw_id' => $this->resultDraw->getKey(),
            'product' => 'l6',
            'ticket_reference' => $resultTicket->ticket_number,
            'status' => 'active',
            'saved_at' => now()->subDays(9),
        ]);

        // 5. Result → notification (idempotent).
        $notify = app(GloResultNotificationService::class);
        $first = $notify->processDraw($this->resultDraw);
        $second = $notify->processDraw($this->resultDraw);

        $this->assertSame(1, $first['notified']);
        $this->assertSame(1, $second['replayed']);
        $this->assertSame(1, GloNotificationDelivery::query()->count());
        $this->assertSame('fp-integration-1', $first['result_version']);

        // 6. Audit trail exists for location, request, notification.
        $this->assertDatabaseHas('audit_logs', ['description' => 'glo_daily_sales_location_updated']);
        $this->assertDatabaseHas('audit_logs', ['description' => 'glo_dealer_request_approved']);
        $this->assertDatabaseHas('audit_logs', ['description' => 'glo_result_notification_queued']);
        $this->assertDatabaseHas('audit_logs', ['description' => 'glo_saved_ticket_created']);
    }

    public function test_concurrent_style_double_location_same_day_only_one_row(): void
    {
        $sales = app(GloSalesPointService::class);
        $input = ['display_name' => 'A', 'latitude' => 13.7, 'longitude' => 100.5];

        $sales->updateDailyLocation($this->dealer, $input, $this->dealerUser);

        try {
            // Second "worker" same day.
            $sales->updateDailyLocation($this->dealer, $input, $this->dealerUser);
            $this->fail('second same-day update must fail');
        } catch (\App\Exceptions\GloDealerException) {
            $this->assertSame(1, \App\Models\GloSalesPointHistory::query()->count());
        }
    }

    public function test_double_approve_same_request_only_one_state(): void
    {
        $changes = app(GloDealerChangeRequestService::class);
        $row = $changes->submit(
            $this->dealer,
            $this->dealerUser,
            GloDealerRequestType::Name,
            'Once',
        );
        $row = $changes->startReview($row, $this->operator);
        $changes->approve($row, $this->operator);

        try {
            // Second approval attempt (replayed request object in stale state).
            $stale = \App\Models\GloDealerChangeRequest::query()->find($row->getKey());
            $changes->approve($stale, $this->operator);
            $this->fail('double approve must be illegal');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('Illegal', $e->getMessage());
        }

        $this->assertSame(
            'approved',
            \App\Models\GloDealerChangeRequest::query()->find($row->getKey())->status->value,
        );
    }

    private function freshUser(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return User::factory()->state(fn (): array => [
            'email' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)).'.'.bin2hex(random_bytes(4)).'@glofreeze.local',
            'username' => 'gz'.\Illuminate\Support\Str::random(10),
            'phone' => '+8801'.random_int(100_000_000, 999_999_999),
            'status' => UserStatus::Active,
        ]);
    }
}
