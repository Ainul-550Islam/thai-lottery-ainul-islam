<?php

declare(strict_types=1);

namespace App\Console\Commands\Fx;

use App\Models\FxRateSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * fx:import — a no-op unless an operator has chosen a source.
 *
 * WHY THIS COMMAND EXISTS AND DOES NOTHING BY DEFAULT
 * The batch requires an import entry point and forbids outbound network calls by
 * default. Those two requirements are reconciled here: with the default `null`
 * driver the command reports "nothing to import" and exits 0, and with the
 * `manual` driver it validates and stamps the rows an operator has entered (for
 * example, by a CSV one of their staff prepared from a contracted rate sheet).
 *
 * IT INVENTS NOTHING
 * No sample rate, no fallback, no "1.0" — a missing rate stays missing. The only
 * numbers this command can write are the ones handed to it.
 */
final class ImportFxRatesCommand extends Command
{
    protected $signature = 'fx:import
        {--base=THB : Base currency}
        {--quote=USD : Quote currency}
        {--rate= : An explicit rate to record (decimal string)}
        {--reference= : Source reference for the rate}';

    protected $description = 'Record a display FX rate (no network call; default driver is null).';

    public function handle(): int
    {
        $driver = (string) config('player_experience.fx.driver', 'null');

        if ($driver === 'null') {
            $this->info('FX driver is "null": no rate is imported and no network call is made.');

            return self::SUCCESS;
        }

        $rate = $this->option('rate');

        if (! is_string($rate) || trim($rate) === '') {
            $this->warn('No --rate given. Nothing was written.');

            return self::SUCCESS;
        }

        $base = strtoupper((string) $this->option('base'));
        $quote = strtoupper((string) $this->option('quote'));

        if ($base === $quote) {
            $this->error('Base and quote must differ.');

            return self::FAILURE;
        }

        if (bccomp($rate, '0', 12) <= 0) {
            $this->error('The rate must be greater than zero.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($base, $quote, $rate): void {
            FxRateSnapshot::query()->create([
                'base' => $base,
                'quote' => $quote,
                'rate' => bcadd($rate, '0', 12),
                'source' => (string) config('player_experience.fx.manual_source', 'manual_admin'),
                'captured_at' => Carbon::now(),
                'reference' => $this->option('reference') === null ? null : (string) $this->option('reference'),
            ]);
        });

        $this->info(sprintf('Recorded %s/%s = %s.', $base, $quote, $rate));

        return self::SUCCESS;
    }
}
