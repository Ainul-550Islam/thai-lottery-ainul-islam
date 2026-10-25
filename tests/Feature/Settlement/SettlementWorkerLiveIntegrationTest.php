<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\DTOs\DrawResultData;
use App\Enums\DrawPublicationStatus;
use App\Models\DrawPublication;
use App\Services\Draw\SettlementWorkerClient;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Live PHP-to-Rust-to-MariaDB compatibility test.
 *
 * Run only against the disposable `thai_lottery_test` database while the worker
 * is live: SETTLEMENT_WORKER_LIVE_TEST=1 php artisan test --filter=SettlementWorkerLiveIntegrationTest
 */
final class SettlementWorkerLiveIntegrationTest extends TestCase
{
    private const SECRET = 'live-settlement-worker-integration-secret-32';
    private const FIRST_PRIZE = '123456';
    private const BOTTOM_TWO = '99';

    private int $drawId;
    private int $betId;
    private int $betItemId;
    private string $fingerprint;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('SETTLEMENT_WORKER_LIVE_TEST') !== '1') {
            $this->markTestSkipped('Requires the disposable MariaDB/MySQL test database and a running settlement worker.');
        }
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This integration test requires the MySQL/MariaDB PDO driver.');
        }

        config([
            'services.settlement_worker.enabled' => true,
            'services.settlement_worker.url' => env('SETTLEMENT_WORKER_LIVE_URL', 'http://127.0.0.1:9101'),
            'services.settlement_worker.hmac_secret' => self::SECRET,
            'services.settlement_worker.timeout' => 5,
        ]);

        $this->drawId = random_int(7_000_000_000, 8_000_000_000);
        $this->fingerprint = hash('sha256', 'settlement-worker-integration-'.$this->drawId);
        $now = now();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::table('draw_results')->insert([
                'draw_id' => $this->drawId,
                'first_prize' => self::FIRST_PRIZE,
                'metadata' => json_encode(['bottom_two' => self::BOTTOM_TWO], JSON_THROW_ON_ERROR),
                'total_winners' => 0,
                'total_payout' => '0.00',
                'house_profit' => '0.00',
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('draw_publications')->insert([
                'publication_key' => hash('sha256', 'publication-'.$this->drawId),
                'draw_id' => $this->drawId,
                'draw_certification_id' => $this->drawId,
                'version' => 1,
                'status' => DrawPublicationStatus::Published->value,
                'result_fingerprint' => $this->fingerprint,
                'published_at' => $now,
                'metadata' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->betId = (int) DB::table('bets')->insertGetId([
                'bet_number' => 'SW-IT-'.$this->drawId,
                'user_id' => 1,
                'draw_id' => $this->drawId,
                'type' => '3d',
                'status' => 'pending',
                'currency' => 'THB',
                'stake_amount' => '10.00',
                'potential_payout' => '9000.00',
                'actual_payout' => '0.00',
                'total_numbers' => 1,
                'idempotency_key' => 'SW-IT-'.$this->drawId,
                'placed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->betItemId = (int) DB::table('bet_items')->insertGetId([
                'bet_id' => $this->betId,
                'number' => '456',
                'position' => 'top',
                'amount' => '10.00',
                'payout_multiplier' => 900,
                'potential_payout' => '9000.00',
                'is_winner' => false,
                'actual_payout' => '0.00',
                'metadata' => json_encode(['market' => '3d_direct'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->drawId) && DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                if (isset($this->betItemId)) {
                    DB::table('bet_items')->where('id', $this->betItemId)->delete();
                }
                if (isset($this->betId)) {
                    DB::table('bets')->where('id', $this->betId)->delete();
                }
                DB::table('draw_publications')->where('draw_id', $this->drawId)->delete();
                DB::table('draw_results')->where('draw_id', $this->drawId)->delete();
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        parent::tearDown();
    }

    public function test_php_signs_request_and_live_rust_worker_reads_mariadb_schema(): void
    {
        $fingerprint = DrawPublication::query()
            ->where('draw_id', $this->drawId)
            ->whereIn('status', [
                DrawPublicationStatus::Published->value,
                DrawPublicationStatus::RePublished->value,
            ])
            ->value('result_fingerprint');
        $this->assertSame($this->fingerprint, $fingerprint);

        $plan = app(SettlementWorkerClient::class)->planForBetRange(
            $this->drawId,
            $this->betId,
            $this->betId,
            DrawResultData::fromValidated(self::FIRST_PRIZE, self::BOTTOM_TWO, 6),
            (string) $fingerprint,
        );

        $this->assertSame(1, $plan['total_selections']);
        $this->assertSame([
            $this->betItemId => ['won' => true, 'prize' => '9000.00', 'tier' => '3d_direct'],
        ], $plan['decisions']);
    }
}
