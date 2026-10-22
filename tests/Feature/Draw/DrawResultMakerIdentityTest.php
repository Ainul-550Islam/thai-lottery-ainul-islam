<?php

declare(strict_types=1);

namespace Tests\Feature\Draw;

use App\Enums\UserStatus;
use App\Exceptions\DrawResultException;
use App\Models\Draw;
use App\Models\User;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultIngestionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DrawResultMakerIdentityTest extends TestCase
{
    use DatabaseTruncation;

    private const NUMBERS = ['first_prize' => '003456', 'bottom_two' => '45'];

    private function assertNoPublicationOrMoney(): void
    {
        foreach (['draw_results', 'winning_numbers', 'financial_transactions', 'ledger_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_invalid_supplied_ids_are_typed_refusals_before_metadata_or_audit_writes(): void
    {
        $draw = Draw::factory()->create(['status' => 'drawing']);
        $before = $draw->fresh()->metadata;
        $auditCount = DB::table('audit_logs')->count();
        foreach ([0, -1, PHP_INT_MAX] as $invalid) {
            try {
                app(DrawResultIngestionService::class)->ingest((int) $draw->getKey(), self::NUMBERS, 'operator', $invalid);
                $this->fail('An invalid supplied maker was staged.');
            } catch (DrawResultException $exception) {
                $this->assertSame(DrawResultException::CODE_CONFIRMATION_FORBIDDEN, $exception->errorCode());
                $this->assertSame($invalid, $exception->context()['operator_id']);
                $this->assertSame((int) $draw->getKey(), $exception->context()['draw_id']);
            }
            $this->assertSame($before, $draw->fresh()->metadata);
            $this->assertSame($auditCount, DB::table('audit_logs')->count());
            $this->assertNoPublicationOrMoney();
        }
    }

    public function test_suspended_maker_cannot_replace_a_pending_card_or_replay_it(): void
    {
        $draw = Draw::factory()->create(['status' => 'drawing']);
        $maker = User::factory()->create();
        $service = app(DrawResultIngestionService::class);
        $service->ingest((int) $draw->getKey(), self::NUMBERS, 'operator', (int) $maker->getKey());
        $before = $draw->fresh()->metadata;
        $auditCount = DB::table('audit_logs')->count();
        $maker->status = UserStatus::Suspended;
        $maker->save();
        foreach ([self::NUMBERS, ['first_prize' => '003457', 'bottom_two' => '46']] as $numbers) {
            try {
                $service->ingest((int) $draw->getKey(), $numbers, 'operator', (int) $maker->getKey());
                $this->fail('A suspended maker was accepted, including on exact replay.');
            } catch (DrawResultException $exception) {
                $this->assertSame(DrawResultException::CODE_CONFIRMATION_FORBIDDEN, $exception->errorCode());
            }
            $this->assertSame($before, $draw->fresh()->metadata);
            $this->assertSame($auditCount, DB::table('audit_logs')->count());
            $this->assertNoPublicationOrMoney();
        }
    }

    public function test_invalid_replacement_leaves_the_valid_maker_and_history_unchanged(): void
    {
        $draw = Draw::factory()->create(['status' => 'drawing']);
        $maker = User::factory()->create();
        $service = app(DrawResultIngestionService::class);
        $service->ingest((int) $draw->getKey(), self::NUMBERS, 'operator', (int) $maker->getKey());
        $before = $draw->fresh()->metadata;
        $auditCount = DB::table('audit_logs')->count();
        try {
            $service->ingest((int) $draw->getKey(), ['first_prize' => '003457', 'bottom_two' => '46'], 'operator', 0);
            $this->fail('A bad maker replaced the valid pending card.');
        } catch (DrawResultException $exception) {
            $this->assertSame(DrawResultException::CODE_CONFIRMATION_FORBIDDEN, $exception->errorCode());
        }
        $this->assertSame($before, $draw->fresh()->metadata);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertNoPublicationOrMoney();
    }

    public function test_deleted_maker_is_not_treated_as_an_existing_audit_identity(): void
    {
        $draw = Draw::factory()->create(['status' => 'drawing']);
        $maker = User::factory()->create();
        $id = (int) $maker->getKey();
        $maker->delete();
        $before = $draw->fresh()->metadata;
        $auditCount = DB::table('audit_logs')->count();
        try {
            app(DrawResultIngestionService::class)->ingest((int) $draw->getKey(), self::NUMBERS, 'operator', $id);
            $this->fail('A deleted maker was accepted.');
        } catch (DrawResultException $exception) {
            $this->assertSame(DrawResultException::CODE_CONFIRMATION_FORBIDDEN, $exception->errorCode());
        }
        $this->assertSame($before, $draw->fresh()->metadata);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertNoPublicationOrMoney();
    }

    public function test_null_feed_identity_remains_explicitly_unattributed_and_unconfirmable(): void
    {
        $draw = Draw::factory()->create(['status' => 'drawing']);
        $outcome = app(DrawResultIngestionService::class)->ingest((int) $draw->getKey(), self::NUMBERS, 'glo_feed', null);
        $this->assertSame('pending', $outcome['status']);
        $this->assertNull($draw->fresh()->metadata['result_ingestion']['ingested_by']);
        $audit = DB::table('audit_logs')->where('auditable_id', $draw->getKey())->first();
        $this->assertNotNull($audit);
        $this->assertNull($audit->user_id);
        try {
            app(DrawResultConfirmationService::class)->confirm((int) $draw->getKey(), (int) User::factory()->create()->getKey(), self::NUMBERS);
            $this->fail('An unattributed feed was confirmed.');
        } catch (DrawResultException $exception) {
            $this->assertSame(DrawResultException::CODE_CONFIRMATION_FORBIDDEN, $exception->errorCode());
        }
        $this->assertSame('pending', $draw->fresh()->metadata['result_ingestion']['status']);
        $this->assertNoPublicationOrMoney();
    }

    public function test_valid_maker_replay_retains_single_intake_and_audit(): void
    {
        $draw = Draw::factory()->create(['status' => 'drawing']);
        $maker = User::factory()->create();
        $service = app(DrawResultIngestionService::class);
        $first = $service->ingest((int) $draw->getKey(), self::NUMBERS, 'operator', (int) $maker->getKey());
        $auditCount = DB::table('audit_logs')->count();
        $before = $draw->fresh()->metadata;
        $second = $service->ingest((int) $draw->getKey(), self::NUMBERS, 'operator', (int) $maker->getKey());
        $this->assertFalse($first['no_op']);
        $this->assertTrue($second['no_op']);
        $this->assertSame($first['fingerprint'], $second['fingerprint']);
        $this->assertSame($before, $draw->fresh()->metadata);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertNoPublicationOrMoney();
    }
}
