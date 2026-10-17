<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Draw;
use App\Models\DrawResult;
use App\Services\ResultIngestion\DrawResultConfirmationService;
use App\Services\ResultIngestion\GloResultIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class GloFourEyesWriteBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private GloResultIngestionService $ingestionService;
    private DrawResultConfirmationService $confirmationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingestionService = app(GloResultIngestionService::class);
        $this->confirmationService = app(DrawResultConfirmationService::class);
    }

    public function test_same_operator_cannot_ingest_and_confirm_result(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('4-Eyes Governance Violation');

        $operatorId = Str::uuid()->toString();

        $draw = Draw::create([
            'id' => Str::uuid()->toString(),
            'draw_code' => 'THAI-4EYES-01',
            'game_type' => 'THAI_6D',
            'draw_date' => now(),
            'betting_starts_at' => now()->subDays(2),
            'betting_ends_at' => now()->subHours(1),
            'status' => 'CLOSED',
        ]);

        $draft = $this->ingestionService->ingestPayload(
            drawId: $draw->id,
            resultPayload: [
                'first_prize' => '934521',
                'two_digit_back' => '88',
                'three_digit_front' => ['123', '456'],
                'three_digit_back' => ['789', '012'],
            ],
            operatorId: $operatorId
        );

        $this->assertEquals('PENDING_CONFIRMATION', $draft->verification_status);

        // Confirmation attempt with same operator ID MUST FAIL
        $this->confirmationService->confirmResult(
            drawResultId: $draft->id,
            confirmingOperatorId: $operatorId
        );
    }

    public function test_distinct_operator_confirms_successfully(): void
    {
        $operatorIngester = Str::uuid()->toString();
        $operatorConfirmer = Str::uuid()->toString();

        $draw = Draw::create([
            'id' => Str::uuid()->toString(),
            'draw_code' => 'THAI-4EYES-02',
            'game_type' => 'THAI_6D',
            'draw_date' => now(),
            'betting_starts_at' => now()->subDays(2),
            'betting_ends_at' => now()->subHours(1),
            'status' => 'CLOSED',
        ]);

        $draft = $this->ingestionService->ingestPayload(
            drawId: $draw->id,
            resultPayload: [
                'first_prize' => '934521',
                'two_digit_back' => '88',
            ],
            operatorId: $operatorIngester
        );

        $confirmed = $this->confirmationService->confirmResult(
            drawResultId: $draft->id,
            confirmingOperatorId: $operatorConfirmer
        );

        $this->assertEquals('CONFIRMED', $confirmed->verification_status);
        $this->assertEquals($operatorConfirmer, $confirmed->confirmed_by_operator_id);
        $this->assertNotNull($confirmed->confirmed_at);
    }
}
