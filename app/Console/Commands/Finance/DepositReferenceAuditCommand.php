<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Enums\DepositStatus;
use App\Models\Deposit;
use App\Services\Finance\DepositCompletionService;
use Illuminate\Console\Command;
use Throwable;

/** Private server-operator diagnostics only; no provider call, backfill or financial mutation. */
final class DepositReferenceAuditCommand extends Command
{
    protected $signature = 'payments:deposit-reference-audit
        {--deposit= : Exact ID, reference or UUID, including soft-deleted history}
        {--attention : At most 200 oldest rows; not a whole-database certification}';

    protected $description = 'Read-only redacted original deposit settlement proof audit; never credits or repairs';

    public function handle(DepositCompletionService $completion): int
    {
        $selector = $this->option('deposit');
        $attention = (bool) $this->option('attention');
        if (($attention && $selector !== null) || (! $attention && (! is_string($selector) || trim($selector) === ''))) {
            $this->error('Choose --deposit=ID/REFERENCE/UUID or --attention, not both.');

            return self::FAILURE;
        }
        try {
            $query = Deposit::withTrashed();
            if ($attention) {
                $rows = $query->orderBy('id')->limit(200)->get();
            } else {
                $selector = trim($selector);
                $row = ctype_digit($selector) ? (clone $query)->find((int) $selector) : null;
                $rows = $row ? collect([$row]) : $query->where(fn ($q) => $q->where('reference_number', $selector)->orWhere('uuid', $selector))->limit(2)->get();
                if ($rows->count() !== 1) {
                    $this->error('No unique deposit matches. Nothing was changed.');

                    return self::FAILURE;
                }
            }
            $items = $rows->map(function (Deposit $row) use ($completion): array {
                $proof = 'not_terminal_credit';
                if ($row->status === DepositStatus::Confirmed || $row->financial_transaction_id !== null) {
                    $proof = 'held_original_evidence';
                    try {
                        $completion->verifySettled($row);
                        $proof = 'canonical_credit_proved';
                    } catch (Throwable) {
                        // Raw exception text may contain private provider/account facts. Never echo it.
                    }
                }

                return ['deposit_id' => (int) $row->id, 'status' => $row->status->value,
                    'deleted' => $row->trashed(), 'proof' => $proof];
            })->all();
            $this->line(json_encode(['read_only' => true, 'bounded_listing' => $attention, 'items' => $items,
                'guidance' => 'Proof is local original accounting, not provider authentication. Missing proof remains a controlled hold. Never reconstruct ownership, resend or repair by status.'], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Deposit audit failed. No provider call, credit or historical repair was attempted.');

            return self::FAILURE;
        }
    }
}
