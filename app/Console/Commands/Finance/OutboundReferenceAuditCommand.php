<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Models\Payout;
use App\Models\Withdrawal;
use App\Services\Payment\OutboundReferenceAuditService;
use Illuminate\Console\Command;
use Throwable;

/** Server-operator diagnostics only; no public bulk binding or automatic import endpoint. */
final class OutboundReferenceAuditCommand extends Command
{
    protected $signature = 'payments:outbound-reference-audit
        {--domain= : payout or withdrawal}
        {--id= : Exact positive obligation id, including soft-deleted history}
        {--attention : Read-only cursor page, not whole-database certification}
        {--after=0 : Exclusive obligation-id cursor for attention pages}
        {--limit=50 : Attention page size, 1 to 200}';

    protected $description = 'Read-only redacted common outbound ownership audit; never sends, repairs, releases or reassigns';

    public function handle(OutboundReferenceAuditService $audit): int
    {
        $domain = $this->option('domain');
        $id = $this->option('id');
        $attention = (bool) $this->option('attention');
        $after = $this->option('after');
        $limit = $this->option('limit');
        if (! in_array($domain, ['payout', 'withdrawal'], true) || ! ctype_digit((string) $after) || strlen((string) $after) > 18
            || ! ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 200
            || ($attention ? $id !== null : (! is_string($id) || ! ctype_digit($id) || strlen($id) > 18 || (int) $id < 1 || (int) $after !== 0))) {
            $this->error('Choose a domain and either --id=POSITIVE_ID or --attention with bounded --after/--limit.');

            return self::FAILURE;
        }
        try {
            $query = ($domain === 'payout' ? Payout::withTrashed() : Withdrawal::withTrashed());
            $rows = $attention ? $query->where('id', '>', (int) $after)->orderBy('id')->limit((int) $limit + 1)->get() : $query->whereKey((int) $id)->get();
            if (! $attention && $rows->count() !== 1) {
                $this->error('No unique obligation found. Nothing was changed.');

                return self::FAILURE;
            }
            $more = $attention && $rows->count() > (int) $limit;
            $rows = $attention ? $rows->take((int) $limit) : $rows;
            $this->line(json_encode(['read_only' => true, 'bounded_listing' => $attention, 'domain' => $domain,
                'items' => $rows->map(fn ($row) => $audit->inspect($row))->values()->all(),
                'has_more' => $more, 'next_after' => $more ? (int) $rows->last()->id : null,
                'guidance' => 'Missing indexes or conflicting history require original-evidence review; do not reset claims, resend, release holds or choose a historical winner.'], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Outbound reference inspection failed. No financial mutation or provider call was attempted.');

            return self::FAILURE;
        }
    }
}
