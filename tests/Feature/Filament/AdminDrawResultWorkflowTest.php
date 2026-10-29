<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\DrawStatus;
use App\Enums\UserStatus;
use App\Exceptions\DrawResultException;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\User;
use App\Services\Draw\AdminDrawResultWorkflow;
use App\Services\Draw\DrawResultIngestionService;
use App\Services\Draw\DrawResultPublicationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class AdminDrawResultWorkflowTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolePermissionSeeder::class);
    }

    private const NUMBERS = ['first_prize' => '003456', 'bottom_two' => '45'];

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function pending(): array
    {
        $draw = Draw::factory()->create(['status' => DrawStatus::Drawing]);
        $maker = $this->operator();
        $checker = $this->operator();
        $this->actingAs($maker);
        $record = app(AdminDrawResultWorkflow::class)->ingest((int) $draw->getKey(), self::NUMBERS);
        $this->actingAs($checker);

        return [$draw, $maker, $checker, $record['fingerprint']];
    }

    private function assertUnpublished(Draw $draw): void
    {
        $this->assertSame(DrawStatus::Drawing, $draw->fresh()->status);
        $this->assertDatabaseCount('draw_results', 0);
        $this->assertDatabaseCount('winning_numbers', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_actor_and_source_cannot_be_spoofed_by_intake_fields(): void
    {
        $draw = Draw::factory()->create(['status' => DrawStatus::Drawing]);
        $maker = $this->operator();
        $this->actingAs($maker);
        app(AdminDrawResultWorkflow::class)->ingest((int) $draw->getKey(), self::NUMBERS + [
            'actor_user_id' => $this->operator()->getKey(), 'source' => 'official', 'confirmed_by' => 999,
        ]);
        $record = $draw->fresh()->metadata['result_ingestion'];
        $this->assertSame((int) $maker->getKey(), $record['ingested_by']);
        $this->assertSame('admin-panel', $record['source']);
        $this->assertSame('pending', $record['status']);
        $this->assertArrayNotHasKey('confirmed_by', $record);
        $this->assertUnpublished($draw);
    }

    public function test_unauthenticated_intake_is_refused(): void
    {
        $draw = Draw::factory()->create(['status' => DrawStatus::Drawing]);
        try {
            app(AdminDrawResultWorkflow::class)->ingest((int) $draw->getKey(), self::NUMBERS);
            $this->fail('Unauthenticated intake was accepted.');
        } catch (AuthorizationException) {
            $this->assertNull(app(DrawResultIngestionService::class)->pendingIngestion((int) $draw->getKey()));
        }
        $this->assertUnpublished($draw);
    }

    public function test_roleless_checker_is_refused_even_with_a_valid_card(): void
    {
        [$draw, , , $fingerprint] = $this->pending();
        $this->actingAs(User::factory()->create());
        try {
            app(AdminDrawResultWorkflow::class)->confirm((int) $draw->getKey(), self::NUMBERS, 'Checked both numbers.', $fingerprint);
            $this->fail('Roleless checker was accepted.');
        } catch (AuthorizationException) {
            $this->assertFalse(app(AdminDrawResultWorkflow::class)->canConfirm($draw->fresh()));
        }
        $this->assertUnpublished($draw);
    }

    public function test_role_revoked_after_mount_is_rechecked_at_execution(): void
    {
        [$draw, , $checker, $fingerprint] = $this->pending();
        $this->assertTrue(app(AdminDrawResultWorkflow::class)->canConfirm($draw));
        $checker->syncRoles([]);
        try {
            app(AdminDrawResultWorkflow::class)->confirm((int) $draw->getKey(), self::NUMBERS, 'Checked both numbers.', $fingerprint);
            $this->fail('A revoked role was trusted from the session.');
        } catch (AuthorizationException) {
            $this->assertFalse(app(AdminDrawResultWorkflow::class)->canConfirm($draw));
        }
        $this->assertUnpublished($draw);
    }

    public function test_suspended_checker_is_rechecked_at_execution(): void
    {
        [$draw, , $checker, $fingerprint] = $this->pending();
        $checker->status = UserStatus::Suspended;
        $checker->save();
        try {
            app(AdminDrawResultWorkflow::class)->confirm((int) $draw->getKey(), self::NUMBERS, 'Checked both numbers.', $fingerprint);
            $this->fail('A suspended checker was accepted.');
        } catch (AuthorizationException) {
            $this->assertFalse(app(AdminDrawResultWorkflow::class)->canConfirm($draw));
        }
        $this->assertUnpublished($draw);
    }

    public function test_distinct_authorized_checker_publishes_the_existing_manual_card(): void
    {
        [$draw, $maker, $checker, $fingerprint] = $this->pending();
        $draw->scheduled_at = now()->subMinute();
        $draw->save();

        $confirmed = app(AdminDrawResultWorkflow::class)->confirm(
            (int) $draw->getKey(), self::NUMBERS, 'Checked both numbers.', $fingerprint,
        );

        $this->assertTrue($confirmed['published']);
        $record = $draw->fresh()->metadata['result_ingestion'];
        $this->assertSame('confirmed', $record['status']);
        $this->assertSame('admin-panel', $record['source']);
        $this->assertSame((int) $maker->getKey(), $record['ingested_by']);
        $this->assertSame((int) $checker->getKey(), $record['confirmed_by']);
        $this->assertDatabaseCount('draw_results', 1);

        $result = DrawResult::query()->where('draw_id', $draw->getKey())->firstOrFail();
        $provenance = app(DrawResultPublicationService::class)->verifiedProvenanceFor($draw->fresh(), $result);
        $this->assertSame('source-known', $provenance['trust']);
        $this->assertSame('internal', $provenance['provider']);
    }

    public function test_self_confirmation_is_refused_by_the_domain_not_only_button_visibility(): void
    {
        [$draw, $maker, , $fingerprint] = $this->pending();
        $this->actingAs($maker);
        $this->assertFalse(app(AdminDrawResultWorkflow::class)->canConfirm($draw));
        try {
            app(AdminDrawResultWorkflow::class)->confirm((int) $draw->getKey(), self::NUMBERS, 'Checked both numbers.', $fingerprint);
            $this->fail('Maker confirmed their own card.');
        } catch (DrawResultException) {
            $this->assertSame('pending', $draw->fresh()->metadata['result_ingestion']['status']);
        }
        $this->assertUnpublished($draw);
    }

    public function test_claim_mismatch_leaves_the_card_pending_without_partial_publication(): void
    {
        [$draw, , , $fingerprint] = $this->pending();
        try {
            app(AdminDrawResultWorkflow::class)->confirm((int) $draw->getKey(), ['first_prize' => '003456', 'bottom_two' => '56'], 'Checked both numbers.', $fingerprint);
            $this->fail('Disagreeing bottom two was accepted.');
        } catch (DrawResultException) {
            $this->assertSame('pending', $draw->fresh()->metadata['result_ingestion']['status']);
            $this->assertSame('45', $draw->fresh()->metadata['result_ingestion']['payload']['bottom_two']);
        }
        $this->assertUnpublished($draw);
    }

    public function test_replaced_card_rejects_a_stale_review_fingerprint(): void
    {
        [$draw, $maker, $checker, $fingerprint] = $this->pending();
        $this->actingAs($maker);
        $replacement = ['first_prize' => '003457', 'bottom_two' => '46'];
        $changed = app(AdminDrawResultWorkflow::class)->ingest((int) $draw->getKey(), $replacement);
        $this->assertNotSame($fingerprint, $changed['fingerprint']);
        $this->actingAs($checker);
        try {
            app(AdminDrawResultWorkflow::class)->confirm((int) $draw->getKey(), $replacement, 'Checked both numbers.', $fingerprint);
            $this->fail('A stale review was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('fingerprint', $error->errors());
            $this->assertSame($replacement, $draw->fresh()->metadata['result_ingestion']['payload']);
        }
        $this->assertUnpublished($draw);
    }

    public function test_notes_are_required_at_the_panel_boundary(): void
    {
        [$draw, , , $fingerprint] = $this->pending();
        try {
            app(AdminDrawResultWorkflow::class)->confirm((int) $draw->getKey(), self::NUMBERS, ' ', $fingerprint);
            $this->fail('Missing review notes were accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('notes', $error->errors());
        }
        $this->assertUnpublished($draw);
    }

    public function test_missing_or_malformed_fingerprint_does_not_publish(): void
    {
        [$draw] = $this->pending();
        foreach (['', 'not-a-fingerprint', str_repeat('0', 64)] as $invalid) {
            try {
                app(AdminDrawResultWorkflow::class)->confirm((int) $draw->getKey(), self::NUMBERS, 'Checked both numbers.', $invalid);
                $this->fail('Invalid fingerprint was accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('fingerprint', $error->errors());
            }
            $this->assertUnpublished($draw);
        }
    }

    public function test_missing_pending_card_cannot_be_confirmed(): void
    {
        $draw = Draw::factory()->create(['status' => DrawStatus::Drawing]);
        $this->actingAs($this->operator());
        try {
            app(AdminDrawResultWorkflow::class)->confirm((int) $draw->getKey(), self::NUMBERS, 'Checked both numbers.', str_repeat('a', 64));
            $this->fail('A nonexistent card was confirmed.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('fingerprint', $error->errors());
        }
        $this->assertUnpublished($draw);
    }

    public function test_other_source_cards_cannot_be_replaced_or_confirmed_by_this_limited_panel(): void
    {
        $draw = Draw::factory()->create(['status' => DrawStatus::Drawing]);
        $maker = $this->operator();
        app(DrawResultIngestionService::class)->ingest((int) $draw->getKey(), self::NUMBERS, 'operator-cli', (int) $maker->getKey());
        $this->actingAs($this->operator());
        $record = $draw->fresh()->metadata['result_ingestion'];
        $workflow = app(AdminDrawResultWorkflow::class);
        $this->assertFalse($workflow->canIngest($draw));
        $this->assertFalse($workflow->canConfirm($draw));
        try {
            $workflow->ingest((int) $draw->getKey(), self::NUMBERS);
            $this->fail('A dedicated-source card was overwritten.');
        } catch (ValidationException) {
            $this->assertSame($record, $draw->fresh()->metadata['result_ingestion']);
        }
        try {
            $workflow->confirm((int) $draw->getKey(), self::NUMBERS, 'Checked both numbers.', $record['fingerprint']);
            $this->fail('A dedicated-source card was confirmed through the limited panel.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('fingerprint', $error->errors());
        }
        $this->assertUnpublished($draw);
    }

    public function test_lifecycle_is_rechecked_after_the_screen_was_loaded(): void
    {
        [$draw, , , $fingerprint] = $this->pending();
        $draw->status = DrawStatus::Cancelled;
        $draw->save();
        try {
            app(AdminDrawResultWorkflow::class)->confirm((int) $draw->getKey(), self::NUMBERS, 'Checked both numbers.', $fingerprint);
            $this->fail('A cancelled draw was published.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('first_prize', $error->errors());
        }
        $this->assertSame(DrawStatus::Cancelled, $draw->fresh()->status);
        $this->assertDatabaseCount('draw_results', 0);
        $this->assertDatabaseCount('winning_numbers', 0);
    }
}
