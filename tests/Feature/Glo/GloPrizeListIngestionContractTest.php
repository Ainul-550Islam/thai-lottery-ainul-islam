<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Exceptions\DrawResultException;
use App\Models\Draw;
use App\Models\User;
use App\Services\Draw\DrawResultIngestionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Canonical intake limits, not official authenticity or full announcement certification. */
final class GloPrizeListIngestionContractTest extends TestCase
{
    use DatabaseTruncation;

    private function payload(): array
    {
        return [
            'first_prize' => '042042', 'bottom_two' => '58',
            'second_prize' => ['112233', '445566', '778899', '001122', '334455'],
            'third_prize' => ['600001', '600002', '600003', '600004', '600005',
                '600006', '600007', '600008', '600009', '600010'],
        ];
    }

    private function assertNoPublicationOrMoney(): void
    {
        foreach (['draw_results', 'winning_numbers', 'financial_transactions', 'ledger_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_full_fixture_card_preserves_exact_lists_order_and_leading_zero(): void
    {
        $draw = Draw::factory()->closed()->create();
        $maker = User::factory()->create();
        $payload = $this->payload();
        $outcome = app(DrawResultIngestionService::class)->ingest(
            (int) $draw->getKey(), $payload, 'glo_import:fixture', (int) $maker->getKey(),
        );
        $stored = $draw->fresh()->metadata['result_ingestion'];
        $this->assertSame('pending', $outcome['status']);
        $this->assertSame($payload, $stored['payload']);
        $this->assertSame('001122', $stored['payload']['second_prize'][3]);
        $this->assertCount(5, $stored['payload']['second_prize']);
        $this->assertCount(10, $stored['payload']['third_prize']);
        $this->assertSame(DrawResultIngestionService::fingerprintFor($payload), $stored['fingerprint']);
        $this->assertNoPublicationOrMoney();
    }

    public static function invalidLists(): array
    {
        return [
            'second exceeds five' => ['second_prize', array_fill(0, 6, '112233')],
            'third exceeds ten' => ['third_prize', array_fill(0, 11, '600001')],
            'associative second list' => ['second_prize', ['prize' => '112233']],
            'sparse third list' => ['third_prize', [1 => '600001']],
            'integer drops leading zero' => ['second_prize', [1122]],
            'integer six digits still not string' => ['third_prize', [600001]],
            'short second prize' => ['second_prize', ['11223']],
            'long third prize' => ['third_prize', ['6000010']],
            'non-numeric prize' => ['second_prize', ['112x33']],
            'nested prize' => ['third_prize', [['600001']]],
            'null list' => ['second_prize', null],
            'scalar list' => ['third_prize', '600001'],
        ];
    }

    #[DataProvider('invalidLists')]
    public function test_invalid_replacement_rolls_back_and_retains_the_previous_pending_card(string $field, mixed $invalid): void
    {
        $draw = Draw::factory()->closed()->create();
        $maker = User::factory()->create();
        $ingestion = app(DrawResultIngestionService::class);
        $ingestion->ingest((int) $draw->getKey(), $this->payload(), 'glo_import:fixture', (int) $maker->getKey());
        $before = $draw->fresh()->metadata;
        $changed = $this->payload();
        $changed[$field] = $invalid;
        try {
            $ingestion->ingest((int) $draw->getKey(), $changed, 'glo_import:fixture', (int) $maker->getKey());
            $this->fail('An invalid provider list was accepted.');
        } catch (DrawResultException $exception) {
            $this->assertSame(DrawResultException::CODE_MALFORMED, $exception->errorCode());
            $this->assertSame($field, $exception->context()['field']);
        }
        $this->assertSame($before, $draw->fresh()->metadata);
        $this->assertNoPublicationOrMoney();
    }

    public function test_current_contract_allows_short_or_empty_lists_without_certifying_completeness(): void
    {
        $draw = Draw::factory()->closed()->create();
        $payload = $this->payload();
        $payload['second_prize'] = ['001122'];
        $payload['third_prize'] = [];
        $outcome = app(DrawResultIngestionService::class)->ingest(
            (int) $draw->getKey(), $payload, 'glo_import:fixture', (int) User::factory()->create()->getKey(),
        );
        $this->assertSame('pending', $outcome['status']);
        $this->assertSame($payload, $draw->fresh()->metadata['result_ingestion']['payload']);
        $this->assertNoPublicationOrMoney();
    }

    public function test_changed_third_prize_changes_fingerprint_and_preserves_superseded_history(): void
    {
        $draw = Draw::factory()->closed()->create();
        $maker = User::factory()->create();
        $ingestion = app(DrawResultIngestionService::class);
        $payload = $this->payload();
        $first = $ingestion->ingest((int) $draw->getKey(), $payload, 'glo_import:fixture', (int) $maker->getKey());
        $payload['third_prize'][9] = '000010';
        $second = $ingestion->ingest((int) $draw->getKey(), $payload, 'glo_import:fixture', (int) $maker->getKey());
        $this->assertNotSame($first['fingerprint'], $second['fingerprint']);
        $metadata = $draw->fresh()->metadata;
        $this->assertSame($payload, $metadata['result_ingestion']['payload']);
        $this->assertSame('superseded', $metadata['result_ingestion_history'][0]['status']);
        $this->assertSame($first['fingerprint'], $metadata['result_ingestion_history'][0]['fingerprint']);
        $this->assertSame('600010', $metadata['result_ingestion_history'][0]['payload']['third_prize'][9]);
        $this->assertNoPublicationOrMoney();
    }
}
