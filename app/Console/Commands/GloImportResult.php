<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Draw;
use App\Models\User;
use App\Services\Lottery\GloResultImportService;
use App\Support\Admin\AdminAccess;
use Illuminate\Console\Command;

/**
 * glo:import-result — import an official/fixture GLO result onto a draw with
 * provenance. NEVER invents numbers: NOT_CONFIGURED / failed imports are
 * recorded and reported without writing draw_results.
 *
 *   glo:import-result --draw=GLO-…            # by draw_number or id
 *   glo:import-result --latest                # fixture/latest replay
 *   glo:import-result --draw=123 --actor=…    # audit attribution
 *   glo:import-result --json
 */
class GloImportResult extends Command
{
    protected $signature = 'glo:import-result
        {--draw= : Local draw id or draw_number}
        {--latest : Import latest fixture without requiring a draw match}
        {--actor= : Operator email for audit attribution}
        {--json : JSON output}';

    protected $description = 'Import a GLO official/fixture result with provenance (honest NOT_CONFIGURED allowed)';

    public function handle(GloResultImportService $imports): int
    {
        $asJson = (bool) $this->option('json');
        $actor = $this->resolveActor();

        if ($this->option('latest')) {
            $outcome = $imports->importLatest($actor);
        } else {
            $drawRef = (string) $this->option('draw');

            if ($drawRef === '') {
                $this->error('--draw= or --latest is required.');

                return self::FAILURE;
            }

            $draw = ctype_digit($drawRef)
                ? Draw::query()->find((int) $drawRef)
                : Draw::query()->where('draw_number', $drawRef)->first();

            if ($draw === null) {
                $this->error('Draw not found: '.$drawRef);

                return self::FAILURE;
            }

            $outcome = $imports->importForDraw($draw, $actor);
        }

        $import = $outcome['import'];

        // `draw_result_written` is always false now, and that is the point:
        // this command stages a Pending ingestion and CANNOT publish. Publishing
        // requires a different operator through the confirmation path.
        //
        // The report therefore answers the question an operator actually has —
        // "did this become reviewable?" — with `pending_confirmation`, rather
        // than the old `result_written`, which recorded a publication that
        // should never have happened from an import.
        $ingestion = $outcome['ingestion'] ?? null;

        $report = [
            'import_reference' => $import->import_reference,
            'status' => $import->status,
            'provider' => $import->provider,
            'mode' => $import->mode,
            'draw_id' => $import->draw_id,
            'draw_result_written' => $outcome['draw_result'] !== null,
            'pending_confirmation' => is_array($ingestion)
                && ($ingestion['status'] ?? null) === 'pending',
            'ingestion_fingerprint' => is_array($ingestion) ? ($ingestion['fingerprint'] ?? null) : null,
            'failure_reason' => $import->failure_reason,
            'first_prize' => $import->payload_summary['first_prize'] ?? null,
        ];

        if ($asJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Import %s: %s (provider=%s pending_confirmation=%s)%s',
                $report['import_reference'],
                $report['status'],
                $report['provider'],
                $report['pending_confirmation'] ? 'yes' : 'no',
                $report['failure_reason'] !== null ? ' — '.$report['failure_reason'] : '',
            ));

            if ($report['pending_confirmation']) {
                $this->warn(
                    'Staged as Pending. A DIFFERENT operator must confirm it before it publishes: '
                    .'php artisan lottery:publish-result --draw='.$import->draw_id.' --confirm'
                );
            }
        }

        return $report['status'] === 'imported' ? self::SUCCESS : self::FAILURE;
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

        if (! AdminAccess::allows($actor, AdminAccess::VIEW_DRAWS)
            && ! AdminAccess::allows($actor, AdminAccess::MANAGE_DRAWS)) {
            $this->error('Actor lacks draw permissions for import attribution.');

            exit(self::FAILURE);
        }

        return $actor;
    }
}
