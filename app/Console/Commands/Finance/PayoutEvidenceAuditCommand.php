<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Exceptions\FinancialException;
use App\Services\Payment\PayoutEvidenceService;
use App\Services\Payment\PayoutEvidenceStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Authorized server diagnostics only. No expiry-driven deletion of financial evidence or private bytes in output. */
final class PayoutEvidenceAuditCommand extends Command
{
    protected $signature = 'payments:payout-evidence-audit {--after=0 : Exclusive archive-id cursor} {--limit=50 : Page size, 1 to 200} {--verify-bytes : Decrypt and verify each selected private object without printing it}';

    protected $description = 'Read-only private payout evidence integrity/retention audit; never deletes, repairs or resends';

    public function handle(PayoutEvidenceService $service, PayoutEvidenceStore $store): int
    {
        $after = (string) $this->option('after');
        $limit = (string) $this->option('limit');
        if (! ctype_digit($after) || strlen($after) > 18 || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 200) {
            $this->error('Use a nonnegative archive cursor and a page limit from 1 to 200.');

            return self::FAILURE;
        }
        try {
            $rows = DB::table('payout_transfer_evidence')->where('id', '>', (int) $after)->orderBy('id')->limit((int) $limit + 1)->get();
            $more = $rows->count() > (int) $limit;
            $rows = $rows->take((int) $limit);
            $items = $rows->map(function ($row) use ($service, $store): array {
                $item = $service->project($row);
                $item['bytes_verified'] = false;
                $item['issues'] = [];
                if ($this->option('verify-bytes')) {
                    try {
                        $store->read($row);
                        $item['bytes_verified'] = true;
                    } catch (FinancialException) {
                        $item['issues'][] = 'private_object_missing_or_corrupt';
                    }
                }
                $item['automatic_deletion_allowed'] = false;

                return $item;
            })->values()->all();
            $this->line(json_encode(['read_only' => true, 'items' => $items, 'has_more' => $more,
                'next_after' => $more ? (int) $rows->last()->id : null,
                'guidance' => 'Preserve encrypted objects, SQL provenance, access audits and key history. Legal hold is not cleared by this command; no automatic deletion or historical repair.'], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Private evidence audit failed. No deletion or financial mutation was attempted.');

            return self::FAILURE;
        }
    }
}
