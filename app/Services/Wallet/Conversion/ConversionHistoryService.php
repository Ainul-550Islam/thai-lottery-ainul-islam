<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Models\WalletConversion;
use Illuminate\Support\Carbon;

final class ConversionHistoryService
{
    /** @return list<array{event: string, status_from: string|null, status_to: string|null, error_code: string|null, recorded_at: string}> */
    public function auditTimeline(string $reference, int $userId): array
    {
        $conversion = WalletConversion::query()->where('reference', $reference)->where('user_id', $userId)->first();
        if (! $conversion) {
            return [];
        }
        $timeline = [[
            'event' => 'conversion_requested',
            'status_from' => null,
            'status_to' => 'pending',
            'error_code' => null,
            'recorded_at' => $conversion->created_at?->toDateTimeString() ?? now()->toDateTimeString(),
        ]];
        if ($conversion->completed_at !== null) {
            $timeline[] = [
                'event' => 'conversion_completed',
                'status_from' => 'processing',
                'status_to' => 'completed',
                'error_code' => null,
                'recorded_at' => $conversion->completed_at->toDateTimeString(),
            ];
        }
        if ($conversion->reversed_at !== null) {
            $timeline[] = [
                'event' => 'conversion_reversed',
                'status_from' => 'completed',
                'status_to' => 'reversed',
                'error_code' => null,
                'recorded_at' => $conversion->reversed_at->toDateTimeString(),
            ];
        }
        return $timeline;
    }
}
