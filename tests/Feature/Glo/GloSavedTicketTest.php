<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Enums\UserStatus;
use App\Enums\GloSavedTicketStatus;
use App\Enums\TicketStatus;
use App\Models\Draw;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Lottery\GloSavedTicketService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * GLO-17 saved tickets: save/remove/ownership/draw-state/uniqueness.
 */
class GloSavedTicketTest extends TestCase
{
    use DatabaseTruncation;

    private User $user;

    private User $other;

    private string $token;

    private Draw $openDraw;

    private GloSavedTicketService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->service = app(GloSavedTicketService::class);
        $this->user = $this->freshUser()->create();
        $this->other = $this->freshUser()->create();
        $this->token = $this->user->createToken('api')->plainTextToken;

        $this->openDraw = Draw::factory()->open()->create([
            'scheduled_at' => now()->addDays(3),
        ]);
    }

    private function makeTicket(User $owner, ?Draw $draw = null, TicketStatus $status = TicketStatus::Confirmed): Ticket
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TK-'.uniqid(),
            'user_id' => $owner->getKey(),
            'draw_id' => ($draw ?? $this->openDraw)->getKey(),
            'currency' => 'THB',
            'metadata' => ['product' => 'l6'],
        ]);
        $ticket->status = $status;
        $ticket->save();

        return $ticket;
    }

    public function test_valid_save(): void
    {
        $ticket = $this->makeTicket($this->user);
        $result = $this->service->save($this->user, (int) $ticket->getKey());

        $this->assertTrue($result['saved']->isActive());
        $this->assertSame('pending', $result['saved']->notification_state);
        $this->assertSame($ticket->ticket_number, $result['saved']->ticket_reference);
    }

    public function test_duplicate_save_rejected(): void
    {
        $ticket = $this->makeTicket($this->user);
        $this->service->save($this->user, (int) $ticket->getKey());

        try {
            $this->service->save($this->user, (int) $ticket->getKey());
            $this->fail('duplicate save must 409');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('already saved', $e->getMessage());
        }
    }

    public function test_unique_constraint_blocks_race_insert(): void
    {
        $ticket = $this->makeTicket($this->user);
        $this->service->save($this->user, (int) $ticket->getKey());

        $this->expectException(\Illuminate\Database\QueryException::class);
        // Simulate a second concurrent insert bypassing the service lock path.
        \DB::table('glo_saved_tickets')->insert([
            'user_id' => $this->user->getKey(),
            'ticket_id' => $ticket->getKey(),
            'draw_id' => $this->openDraw->getKey(),
            'product' => 'l6',
            'ticket_reference' => $ticket->ticket_number,
            'status' => 'active',
            'saved_at' => now(),
            'notification_state' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_remove_deactivates_but_keeps_row(): void
    {
        $ticket = $this->makeTicket($this->user);
        $this->service->save($this->user, (int) $ticket->getKey());
        $saved = $this->service->remove($this->user, (int) $ticket->getKey());

        $this->assertSame(GloSavedTicketStatus::Inactive, $saved->status);
        $this->assertNotNull($saved->removed_at);
        $this->assertDatabaseCount('glo_saved_tickets', 1);
    }

    public function test_cannot_save_another_users_ticket(): void
    {
        $ticket = $this->makeTicket($this->other);

        try {
            $this->service->save($this->user, (int) $ticket->getKey());
            $this->fail('cross-user save must 403');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('belong', $e->getMessage());
        }
    }

    public function test_cancelled_ticket_rejected(): void
    {
        $ticket = $this->makeTicket($this->user, status: TicketStatus::Cancelled);

        try {
            $this->service->save($this->user, (int) $ticket->getKey());
            $this->fail('cancelled ticket must 422');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }
    }

    public function test_finalized_draw_rejected(): void
    {
        $finalDraw = Draw::factory()->create([
            'status' => 'result_published',
            'scheduled_at' => now()->subDays(5),
            'result_published_at' => now()->subDays(4),
        ]);
        $ticket = $this->makeTicket($this->user, $finalDraw);

        try {
            $this->service->save($this->user, (int) $ticket->getKey());
            $this->fail('finalized draw must 422');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('finalized', $e->getMessage());
        }
    }

    public function test_unknown_ticket_404(): void
    {
        $this->expectException(\App\Exceptions\GloDealerException::class);
        $this->service->save($this->user, 999999);
    }

    public function test_api_save_list_remove(): void
    {
        $ticket = $this->makeTicket($this->user);

        $this->withToken($this->token)
            ->postJson('/api/v1/glo/me/tickets/'.$ticket->getKey().'/save')
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'active');

        $this->withToken($this->token)
            ->getJson('/api/v1/glo/me/tickets')
            ->assertOk()
            ->assertJsonPath('data.0.ticket_reference', $ticket->ticket_number);

        $this->withToken($this->token)
            ->deleteJson('/api/v1/glo/me/tickets/'.$ticket->getKey().'/save')
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->withToken($this->token)
            ->getJson('/api/v1/glo/me/tickets')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_api_requires_auth(): void
    {
        $this->getJson('/api/v1/glo/me/tickets')->assertStatus(401);
    }

    public function test_api_cannot_save_foreign_ticket(): void
    {
        $ticket = $this->makeTicket($this->other);

        $this->withToken($this->token)
            ->postJson('/api/v1/glo/me/tickets/'.$ticket->getKey().'/save')
            ->assertStatus(403);
    }

    public function test_reactivate_after_remove_is_allowed(): void
    {
        $ticket = $this->makeTicket($this->user);
        $this->service->save($this->user, (int) $ticket->getKey());
        $this->service->remove($this->user, (int) $ticket->getKey());
        $again = $this->service->save($this->user, (int) $ticket->getKey());

        $this->assertTrue($again['saved']->isActive());
        $this->assertSame(1, \App\Models\GloSavedTicket::query()->count());
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
