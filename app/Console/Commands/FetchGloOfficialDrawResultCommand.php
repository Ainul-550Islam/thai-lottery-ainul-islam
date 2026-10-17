<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Draw;
use App\Services\ResultIngestion\GloResultIngestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchGloOfficialDrawResultCommand extends Command
{
    protected $signature = 'glo:fetch-result {draw_code? : Specific draw code}';
    protected $description = 'Ingests official Thai Government Lottery Office (GLO) results with cryptographic fingerprinting.';

    public function handle(GloResultIngestionService $ingestionService): int
    {
        $drawCode = $this->argument('draw_code');

        $query = Draw::whereIn('status', ['CLOSED', 'DRAW_COMPLETED']);
        if ($drawCode) {
            $query->where('draw_code', $drawCode);
        }

        $draws = $query->get();

        if ($draws->isEmpty()) {
            $this->info('No closed draws awaiting result ingestion.');
            return self::SUCCESS;
        }

        foreach ($draws as $draw) {
            $this->info("Fetching official results for Draw: {$draw->draw_code}...");

            try {
                // Call GLO Official REST Gateway with retry & timeout
                $response = Http::timeout(10)->retry(3, 1000)->get(
                    config('services.glo.endpoint', 'https://api.glo.or.th/v1/lottery/latest')
                );

                if (!$response->successful()) {
                    $this->error("Failed to fetch results from GLO API: HTTP {$response->status()}");
                    continue;
                }

                $data = $response->json();

                $resultPayload = [
                    'first_prize' => (string) ($data['first_prize'] ?? ''),
                    'two_digit_back' => (string) ($data['two_digit_back'] ?? ''),
                    'three_digit_front' => $data['three_digit_front'] ?? [],
                    'three_digit_back' => $data['three_digit_back'] ?? [],
                    'second_prizes' => $data['second_prizes'] ?? [],
                    'third_prizes' => $data['third_prizes'] ?? [],
                ];

                $systemOperatorId = config('services.system.automated_operator_id', '00000000-0000-0000-0000-000000000001');

                $draftResult = $ingestionService->ingestPayload(
                    drawId: $draw->id,
                    resultPayload: $resultPayload,
                    operatorId: $systemOperatorId,
                    sourceProvider: 'GLO_API_GATEWAY'
                );

                $this->info("Successfully ingested draft result for Draw [{$draw->draw_code}]. Fingerprint: {$draftResult->payload_fingerprint}");
                $this->warn("Awaiting Supervisor 4-Eyes Confirmation before publication.");
            } catch (Throwable $e) {
                Log::error("GLO Result Ingestion error for Draw [{$draw->draw_code}]: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
                $this->error("Error: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
