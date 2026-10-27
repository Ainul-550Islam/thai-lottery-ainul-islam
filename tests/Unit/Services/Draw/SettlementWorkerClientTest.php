<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Draw;

use App\DTOs\DrawResultData;
use App\Services\Draw\SettlementWorkerClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class SettlementWorkerClientTest extends TestCase
{
    private const SECRET = 'test-settlement-worker-secret-at-least-32-bytes';
    private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.settlement_worker.enabled' => true,
            'services.settlement_worker.url' => 'http://settlement-worker:9101',
            'services.settlement_worker.hmac_secret' => self::SECRET,
            'services.settlement_worker.timeout' => 3,
        ]);
    }

    public function test_it_signs_the_exact_json_body_and_validates_the_returned_plan(): void
    {
        Http::fake([
            'http://settlement-worker:9101/v1/settlement/plan' => Http::response([
                'draw_id' => 7,
                'result_fingerprint' => self::FINGERPRINT,
                'total_selections' => 1,
                'winning_selections' => 1,
                'total_prize' => '9000.00',
                'decisions' => [[
                    'bet_id' => 12,
                    'bet_item_id' => 34,
                    'won' => true,
                    'prize' => '9000.00',
                    'tier' => '3d_direct',
                ]],
            ]),
        ]);

        $plan = app(SettlementWorkerClient::class)->planForBetRange(
            7,
            12,
            12,
            DrawResultData::fromValidated('123456', '99', 6),
            self::FINGERPRINT,
        );

        $this->assertSame(1, $plan['total_selections']);
        $this->assertSame([
            34 => ['won' => true, 'prize' => '9000.00', 'tier' => '3d_direct'],
        ], $plan['decisions']);

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();
            $timestamp = $request->header('X-Settlement-Timestamp')[0] ?? '';
            $signature = $request->header('X-Settlement-Signature')[0] ?? '';
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

            return $request->method() === 'POST'
                && $request->url() === 'http://settlement-worker:9101/v1/settlement/plan'
                && $decoded['draw_id'] === 7
                && $decoded['from_bet_id'] === 12
                && $decoded['to_bet_id'] === 12
                && $decoded['result']['first_prize'] === '123456'
                && $decoded['result']['bottom_two'] === '99'
                && $decoded['result']['result_fingerprint'] === self::FINGERPRINT
                && preg_match('/^[0-9]+$/', $timestamp) === 1
                && hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET), $signature);
        });
    }

    public function test_it_rejects_a_plan_whose_aggregate_does_not_match_its_decisions(): void
    {
        Http::fake([
            '*' => Http::response([
                'draw_id' => 7,
                'result_fingerprint' => self::FINGERPRINT,
                'total_selections' => 1,
                'winning_selections' => 1,
                'total_prize' => '0.00',
                'decisions' => [[
                    'bet_id' => 12,
                    'bet_item_id' => 34,
                    'won' => true,
                    'prize' => '9000.00',
                    'tier' => '3d_direct',
                ]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('plan totals do not reconcile');

        app(SettlementWorkerClient::class)->planForBetRange(
            7,
            12,
            12,
            DrawResultData::fromValidated('123456', '99', 6),
            self::FINGERPRINT,
        );
    }

    public function test_it_refuses_to_use_an_invalid_worker_fingerprint(): void
    {
        Http::preventStrayRequests();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no valid live publication fingerprint');

        app(SettlementWorkerClient::class)->planForBetRange(
            7,
            12,
            12,
            DrawResultData::fromValidated('123456', '99', 6),
            'not-a-fingerprint',
        );
    }
}
