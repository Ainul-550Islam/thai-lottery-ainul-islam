<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use Illuminate\Console\Command;
use Throwable;

/** Authorized server-operator inspection only; never sends, settles, releases or resets a claim. */
final class WithdrawalDispatchStatusCommand extends Command
{
    protected $signature = 'payments:withdrawal-dispatch-status
        {--withdrawal= : One withdrawal id, reference or UUID}
        {--attention : Inspect at most 200 Processing withdrawals, oldest id first}';

    protected $description = 'Read-only redacted outbound dispatch/reconciliation status; never permits automatic resend';

    public function handle(): int
    {
        $selector = $this->option('withdrawal');
        $attention = (bool) $this->option('attention');
        if (($attention && $selector !== null) || (! $attention && (! is_string($selector) || trim($selector) === ''))) {
            $this->error('Choose --withdrawal=ID/REFERENCE/UUID or --attention, not both.');

            return self::FAILURE;
        }
        try {
            // Deliberately do not load/decrypt payout_details or print raw metadata.
            $query = Withdrawal::query()->select(['id', 'uuid', 'reference_number', 'status', 'currency', 'amount', 'net_amount', 'metadata']);
            if ($attention) {
                $rows = $query->where('status', WithdrawalStatus::Processing->value)->orderBy('id')->limit(200)->get();
            } else {
                $selector = trim($selector);
                $row = ctype_digit($selector) ? (clone $query)->find((int) $selector) : null;
                $rows = $row ? collect([$row]) : $query->where(fn ($q) => $q->where('reference_number', $selector)->orWhere('uuid', $selector))->limit(2)->get();
                if ($rows->count() !== 1) {
                    $this->error('No unique withdrawal matches the selector. Nothing was changed.');

                    return self::FAILURE;
                }
            }
            $items = $rows->map(static function (Withdrawal $row): array {
                $metadata = is_array($row->metadata) ? $row->metadata : [];
                $claim = $metadata['outbound_dispatch'] ?? null;
                $state = is_array($claim) ? ($claim['state'] ?? 'corrupt') : (array_key_exists('outbound_dispatch', $metadata) ? 'corrupt' : 'unclaimed');
                if (! is_string($state) || ! in_array($state, ['sending', 'unknown', 'legacy_unknown', 'pending', 'completed', 'failed', 'reconciliation_required', 'unclaimed'], true)) {
                    $state = 'corrupt';
                }
                if ($state === 'unclaimed' && $row->status === WithdrawalStatus::Processing) {
                    $state = 'legacy_unknown';
                }

                return ['withdrawal_id' => (int) $row->id, 'reference' => $row->reference_number,
                    'withdrawal_status' => $row->status->value, 'dispatch_state' => $state,
                    'currency' => $row->currency->value, 'gross_amount' => (string) $row->amount, 'net_amount' => (string) $row->net_amount,
                    'reconciliation_required' => ! $row->status->isFinal() && in_array($state, ['sending', 'unknown', 'legacy_unknown', 'reconciliation_required', 'corrupt'], true),
                    'automatic_resend_allowed' => false];
            })->all();
            $this->line(json_encode(['read_only' => true, 'items' => $items, 'bounded_listing' => $attention,
                'guidance' => 'Do not clear claims, resend or release uncertain funds. Obtain provider evidence through the approved reconciliation workflow.'], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Dispatch inspection failed. No transfer or financial write was attempted.');

            return self::FAILURE;
        }
    }
}
