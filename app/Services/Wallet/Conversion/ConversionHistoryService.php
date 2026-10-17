<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Models\WalletConversion;
use App\Models\WalletConversionAudit;
use Illuminate\Support\Collection;

/**
 * The audit trail of one conversion, read back for the member who owns it.
 *
 * The timeline is what turns "something happened to your money" into a
 * narrative: what was attempted, what was refused and why, and when each event
 * was recorded. It is scoped by the CONVERSION'S owner rather than filtered
 * after the fact, so a guessed reference can never expose another member's
 * trail.
 */
final class ConversionHistoryService
{
    /**
     * @return list<array{event: string, error_code: ?string, recorded_at: ?string}>
     */
    public function auditTimeline(string $reference, int $userId): array
    {
        $conversion = WalletConversion::query()
            ->where('reference', '=', $reference)
            ->where('user_id', '=', $userId)
            ->first();

        if (! $conversion instanceof WalletConversion) {
            return [];
        }

        return WalletConversionAudit::query()
            ->where('wallet_conversion_id', '=', (int) $conversion->getKey())
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->map(static function (WalletConversionAudit $row): array {
                return [
                    'event' => (string) $row->event,
                    'error_code' => $row->error_code === null ? null : (string) $row->error_code,
                    'recorded_at' => $row->recorded_at?->toDateTimeString(),
                ];
            })
            ->all();
    }

    /**
     * Every audit row recorded against one conversion's reference, including
     * the rows written before the row itself was committed (a refusal carries
     * the prospective reference). Operator channels read this; members only
     * ever see their own, via auditTimeline().
     *
     * @return Collection<int, WalletConversionAudit>
     */
    public function auditRowsForReference(string $reference): Collection
    {
        return WalletConversionAudit::query()
            ->forReference($reference)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();
    }
}
