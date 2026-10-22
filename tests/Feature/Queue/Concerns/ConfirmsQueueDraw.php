<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\Concerns;

use App\Models\Draw;
use App\Models\User;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultIngestionService;

/** Synthetic operator result through actual distinct-maker/checker services. */
trait ConfirmsQueueDraw
{
    protected function confirmQueueDraw(Draw $draw): Draw
    {
        $lifecycle = app(DrawLifecycleService::class);
        $draw = $lifecycle->close($draw);
        $draw->scheduled_at = now()->subMinute();
        $draw->save();
        $draw = $lifecycle->markResultPending($draw);
        $card = ['first_prize' => '456123', 'bottom_two' => '45'];
        app(DrawResultIngestionService::class)->ingest(
            (int) $draw->getKey(), $card, 'internal', (int) User::factory()->create()->getKey(),
        );
        app(DrawResultConfirmationService::class)->confirm(
            (int) $draw->getKey(), (int) User::factory()->create()->getKey(), $card, 'Synthetic queue confirmation',
        );

        return $draw->fresh();
    }
}
