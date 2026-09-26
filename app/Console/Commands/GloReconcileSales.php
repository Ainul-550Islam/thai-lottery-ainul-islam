<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Draw;
use App\Models\User;
use App\Services\Lottery\GloSalesReconciliationService;
use App\Support\Admin\AdminAccess;
use Illuminate\Console\Command;

/**
 * glo:reconcile-sales — seat-aware sales reconciliation for L6/N3 with the
 * GLON3_SALES_CONFLICT gate. Conflicted rows block auto-settlement until an
 * operator clears them with evidence (outside this command).
 *
 *   glo:reconcile-sales --draw=123
 *   glo:reconcile-sales --draw=123 --product=n3
 *   glo:reconcile-sales --draw=123 --expected-seats=500 --expected-gross=10000.00
 *   glo:reconcile-sales --draw=123 --json --actor=admin@example.com
 */
class GloReconcileSales extends Command
{
    protected $signature = 'glo:reconcile-sales
        {--draw= : Draw id or draw_number (required)}
        {--product= : l6|n3 (default: both seated products)}
        {--expected-seats= : Expected seat/unit count}
        {--expected-gross= : Expected gross as decimal string}
        {--actor= : Operator email}
        {--json : JSON output}';

    protected $description = 'Reconcile GLO L6/N3 sales seats (GLO-9, GLON3_SALES_CONFLICT gate)';

    public function handle(GloSalesReconciliationService $reconciliation): int
    {
        $drawRef = (string) $this->option('draw');

        if ($drawRef === '') {
            $this->error('--draw= is required.');

            return self::FAILURE;
        }

        $draw = ctype_digit($drawRef)
            ? Draw::query()->find((int) $drawRef)
            : Draw::query()->where('draw_number', $drawRef)->orWhere('uuid', $drawRef)->first();

        if ($draw === null) {
            $this->error('Draw not found: '.$drawRef);

            return self::FAILURE;
        }

        $actor = $this->resolveActor();
        $product = (string) $this->option('product');

        $expected = [];

        if ($this->option('expected-seats') !== null && $this->option('expected-seats') !== '') {
            $expected['expected_seats'] = (int) $this->option('expected-seats');
        }

        if ($this->option('expected-gross') !== null && $this->option('expected-gross') !== '') {
            $expected['expected_gross'] = (string) $this->option('expected-gross');
        }

        try {
            if ($product !== '') {
                $results = [$product => $reconciliation->reconcile($draw, $product, $expected, $actor)];
            } else {
                $results = $reconciliation->reconcileDraw($draw, $actor);
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $blocked = false;
        $summary = [];

        foreach ($results as $name => $row) {
            if ($row === null) {
                continue;
            }

            if ($row['blocked']) {
                $blocked = true;
            }

            $summary[$name] = [
                'status' => $row['status'],
                'conflict_gate' => $row['conflict_gate'],
                'variance_gross' => $row['variance_gross'],
                'reference' => $row['reconciliation']->reconciliation_reference,
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'draw_id' => (int) $draw->getKey(),
                'blocked' => $blocked,
                'conflict_gate' => (string) config('glo.reconciliation.conflict_gate', 'GLON3_SALES_CONFLICT'),
                'results' => $summary,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($summary as $name => $row) {
                $this->line(sprintf(
                    '  %s: %s variance=%s gate=%s ref=%s',
                    $name,
                    $row['status'],
                    $row['variance_gross'],
                    $row['conflict_gate'] ?? '-',
                    $row['reference'],
                ));
            }

            $this->info($blocked
                ? 'Reconciliation CONFLICTED — settlement blocked until gate cleared.'
                : 'Reconciliation matched (or no seats).');
        }

        // Conflicted is a valid completed run (exit 0); only hard errors fail.
        return self::SUCCESS;
    }

    private function resolveActor(): ?User
    {
        $email = (string) $this->option('actor');

        if ($email === '') {
            return null;
        }

        $actor = User::query()->where('email', $email)->first();

        if ($actor === null || ! $actor->isActive()) {
            $this->error('Actor not found or inactive.');
            exit(self::FAILURE);
        }

        if (! AdminAccess::allows($actor, AdminAccess::RECONCILE_LEDGER)
            && ! AdminAccess::allows($actor, AdminAccess::PROCESS_SETTLEMENTS)) {
            $this->error('Actor lacks reconciliation permission.');
            exit(self::FAILURE);
        }

        return $actor;
    }
}
