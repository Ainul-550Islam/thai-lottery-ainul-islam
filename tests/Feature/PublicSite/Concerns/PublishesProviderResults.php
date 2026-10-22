<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSite\Concerns;

use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloResultImport;
use App\Models\User;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultIngestionService;

/** Test-only synthetic import; exercises actual ingestion/confirmation/writer. */
trait PublishesProviderResults
{
    protected function publishProviderResult(
        Draw $draw,
        string $provider = 'fixture',
        string $firstPrize = '000001',
        string $bottomTwo = '58',
        array $second = [],
        array $third = [],
        array $tiers = [],
    ): DrawResult {
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $fingerprint = hash('sha256', json_encode([
            (string) $draw->draw_number, $firstPrize, array_values($second), array_values($third), [], $tiers,
        ], JSON_THROW_ON_ERROR));
        GloResultImport::query()->create([
            'draw_id' => $draw->getKey(), 'provider' => $provider, 'mode' => $provider,
            'endpoint' => 'synthetic-test-only', 'upstream_draw_id' => $draw->draw_number,
            'status' => 'imported', 'result_fingerprint' => $fingerprint,
            'payload_summary' => ['first_prize' => $firstPrize, 'bottom_two' => $bottomTwo],
            'import_reference' => 'synthetic-'.bin2hex(random_bytes(10)),
            'imported_by' => $maker->getKey(), 'imported_at' => now(),
        ]);
        $draw = app(DrawLifecycleService::class)->markResultPending($draw);
        $payload = [
            'first_prize' => $firstPrize, 'bottom_two' => $bottomTwo,
            'second_prize' => $second, 'third_prize' => $third, 'tiers' => $tiers,
            'provider' => $provider, 'provider_fingerprint' => $fingerprint,
            'upstream_draw_id' => $draw->draw_number,
        ];
        app(DrawResultIngestionService::class)->ingest(
            (int) $draw->getKey(), $payload, 'glo_import:'.$provider, (int) $maker->getKey(),
        );
        app(DrawResultConfirmationService::class)->confirm(
            (int) $draw->getKey(), (int) $checker->getKey(),
            ['first_prize' => $firstPrize, 'bottom_two' => $bottomTwo], 'Synthetic regression confirmation',
        );

        return DrawResult::query()->where('draw_id', $draw->getKey())->firstOrFail();
    }
}
