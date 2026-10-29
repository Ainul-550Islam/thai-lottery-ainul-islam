<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Models\User;
use App\Services\Draw\DrawResultIngestionService;
use App\Services\Lottery\CircuitBreaker;
use App\Services\Lottery\GloResultImportService;
use App\Support\Admin\AdminAccess;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Official-only intake. Publication remains the independent manual four-eyes act. */
class FetchGloOfficialDrawResultCommand extends Command
{
    protected $signature = 'glo:fetch-result
        {draw_code? : Specific draw code}
        {--dry-run : Attempt the fetch and report what would be ingested, writing nothing}';

    // This checkout previously registered the old name. Keep it only as a
    // compatibility alias; the supported command is glo:fetch-result.
    protected $aliases = ['glo:fetch-official'];

    protected $description = 'Fetch a configured official result and stage it for independent confirmation.';

    public function handle(
        GloResultImportService $imports,
        DrawResultIngestionService $ingestion,
        CircuitBreaker $breaker,
        ConfigRepository $config,
    ): int {
        if ((string) $config->get('glo.official_source.mode', '') !== 'official') {
            $this->error($this->laravel->environment('production')
                ? 'Refusing to run: production requires official GLO source mode. Nothing was fetched or written.'
                : 'Refusing to run: this official fetch requires official GLO source mode. Nothing was fetched or written.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $maker = $dryRun ? null : $this->operator($config);
        if (! $dryRun && ! $maker instanceof User) {
            $this->error('Refusing to run: configure GLO_AUTOMATED_OPERATOR_ID as an active user with draw-management permission. Nothing was fetched or written.');

            return self::FAILURE;
        }

        // A dry run is read-only even for the breaker cache; live runs keep the
        // existing per-source outage protection.
        $breakerName = 'glo:official';
        if (! $dryRun && $breaker->isOpen($breakerName)) {
            $this->error(sprintf('The GLO circuit is open; retry in %d seconds. Nothing was fetched.', $breaker->retryAfterSeconds($breakerName)));

            return self::FAILURE;
        }

        $draws = $this->drawsAwaitingResult($this->argument('draw_code'));
        if ($draws->isEmpty()) {
            $this->info('No closed or result-pending draws awaiting intake.');

            return self::SUCCESS;
        }

        $failed = 0;
        $staged = 0;
        foreach ($draws as $draw) {
            if ($ingestion->pendingIngestion((int) $draw->getKey()) !== null) {
                $this->warn(sprintf('Draw %s already has a pending result; it was not replaced.', $draw->draw_number));
                continue;
            }

            try {
                if ($dryRun) {
                    $payload = $imports->previewOfficialForDraw($draw);
                    if (($payload['status'] ?? null) !== 'imported') {
                        $failed++;
                        $this->error(sprintf('Dry run could not validate the official response for draw %s.', $draw->draw_number));
                        continue;
                    }
                    $this->info(sprintf('Dry run: draw %s has first prize %s and bottom two %s. Nothing was written.', $draw->draw_number, $payload['first_prize'], $payload['bottom_two']));
                    $staged++;
                    continue;
                }

                $outcome = $imports->importOfficialForDraw($draw, $maker);
                $pending = $outcome['ingestion'] ?? null;
                if (($outcome['import']->status ?? null) !== 'imported'
                    || ! is_array($pending) || ($pending['status'] ?? null) !== 'pending') {
                    $breaker->recordFailure($breakerName, 'official_intake_refused');
                    $failed++;
                    $this->error(sprintf('Official intake was refused for draw %s; nothing was published.', $draw->draw_number));
                    continue;
                }

                $breaker->recordSuccess($breakerName);
                $staged++;
                $this->info(sprintf('Staged draw %s for review (fingerprint %s).', $draw->draw_number, (string) $pending['fingerprint']));
                $this->warn('A different authorized operator must confirm and publish the result.');
            } catch (Throwable $exception) {
                if (! $dryRun) {
                    $breaker->recordFailure($breakerName, 'official_intake_error');
                }
                $failed++;
                // Never log response bodies or endpoint URLs, which may contain credentials.
                Log::warning('glo.official_result.intake_refused', [
                    'draw_id' => (int) $draw->getKey(),
                    'exception' => $exception::class,
                ]);
                $this->error(sprintf('Official intake failed for draw %s; nothing was published.', $draw->draw_number));
            }
        }

        $this->line(sprintf('%d draw(s) validated or staged; %d failure(s).', $staged, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, Draw> */
    private function drawsAwaitingResult(?string $drawCode): Collection
    {
        return Draw::query()
            ->whereIn('status', [DrawStatus::Closed->value, DrawStatus::Drawing->value])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->when(is_string($drawCode) && trim($drawCode) !== '', function ($query) use ($drawCode): void {
                $query->where(function ($query) use ($drawCode): void {
                    $query->where('draw_code', trim($drawCode))->orWhere('draw_number', trim($drawCode));
                });
            })
            ->orderBy('scheduled_at')
            ->get();
    }

    private function operator(ConfigRepository $config): ?User
    {
        $raw = $config->get('services.system.automated_operator_id');
        if ((! is_string($raw) && ! is_int($raw)) || ! ctype_digit((string) $raw) || (int) $raw < 1) {
            return null;
        }

        $operator = User::query()->find((int) $raw);

        return $operator instanceof User && $operator->isActive() && AdminAccess::allows($operator, AdminAccess::MANAGE_DRAWS)
            ? $operator
            : null;
    }
}
