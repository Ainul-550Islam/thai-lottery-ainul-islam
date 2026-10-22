<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Models\Withdrawal;
use App\Services\Payment\WithdrawalProviderReferenceService;
use Illuminate\Console\Command;
use Throwable;

/** Authorized server-operator diagnostics only; not a public reconciliation or mutation endpoint. */
final class WithdrawalReferenceAuditCommand extends Command
{
    protected $signature = 'payments:withdrawal-reference-audit
        {--withdrawal= : Exact id, reference or UUID, including soft-deleted history}
        {--attention : Bounded scan of at most 200 oldest withdrawal rows, not whole-database certification}';

    protected $description = 'Read-only redacted shared-reference/account/legacy-history audit; never sends or resets';

    public function handle(WithdrawalProviderReferenceService $references): int
    {
        $selector = $this->option('withdrawal');
        $attention = (bool) $this->option('attention');
        if (($attention && $selector !== null) || (! $attention && (! is_string($selector) || trim($selector) === ''))) {
            $this->error('Choose --withdrawal=ID/REFERENCE/UUID or --attention, not both.');

            return self::FAILURE;
        }
        try {
            $query = Withdrawal::withTrashed()->select(['id', 'uuid', 'reference_number', 'method', 'status', 'provider', 'provider_reference', 'metadata', 'user_id', 'wallet_id', 'currency', 'amount', 'fee', 'deleted_at']);
            if ($attention) {
                $rows = $query->orderBy('id')->limit(200)->get();
            } else {
                $selector = trim($selector);
                $row = ctype_digit($selector) ? (clone $query)->find((int) $selector) : null;
                $rows = $row ? collect([$row]) : $query->where(fn ($q) => $q->where('reference_number', $selector)->orWhere('uuid', $selector))->limit(2)->get();
                if ($rows->count() !== 1) {
                    $this->error('No unique withdrawal matches. Nothing was changed.');

                    return self::FAILURE;
                }
            }
            $this->line(json_encode(['read_only' => true, 'bounded_listing' => $attention,
                'items' => $rows->map(fn ($row) => $references->inspect($row))->all(),
                'guidance' => 'Historical duplicates and account switches require evidence review. Never clear a claim, reassign an owner, resend or release uncertain funds.'], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Reference audit failed. No transfer or financial mutation was attempted.');

            return self::FAILURE;
        }
    }
}
