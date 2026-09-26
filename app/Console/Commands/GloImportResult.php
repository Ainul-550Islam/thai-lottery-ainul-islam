<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Draw;
use App\Models\User;
use App\Services\Lottery\GloResultImportService;
use App\Support\Admin\AdminAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

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
                : Draw::query()->where('draw_number', $drawRef)->orWhere('uuid', $drawRef)->first();

            if ($draw === null) {
                $this->error('Draw not found: '.$drawRef);

                return self::FAILURE;
            }

            $outcome = $imports->importForDraw($draw, $actor);
        }

        $import = $outcome['import'];
        $report = [
            'import_reference' => $import->import_reference,
            'status' => $import->status,
            'provider' => $import->provider,
            'mode' => $import->mode,
            'draw_id' => $import->draw_id,
            'draw_result_written' => $outcome['draw_result'] !== null,
            'failure_reason' => $import->failure_reason,
            'first_prize' => $import->payload_summary['first_prize'] ?? null,
        ];

        if ($asJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Import %s: %s (provider=%s result_written=%s)%s',
                $report['import_reference'],
                $report['status'],
                $report['provider'],
                $report['draw_result_written'] ? 'yes' : 'no',
                $report['failure_reason'] !== null ? ' — '.$report['failure_reason'] : '',
            ));
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
