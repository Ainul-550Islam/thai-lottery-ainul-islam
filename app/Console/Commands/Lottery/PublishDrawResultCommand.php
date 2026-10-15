<?php

declare(strict_types=1);

namespace App\Console\Commands\Lottery;

use App\Models\Draw;
use App\Models\User;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultIngestionService;
use App\Services\Draw\DrawResultPublicationService;
use App\Services\Draw\DrawScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Two-step operator surface for official result ingestion and confirmation.
 *
 * The first invocation records a Pending result. A second invocation with
 * --confirm and a different operator confirms that exact result. This command
 * never calls the low-level publication writer directly.
 */
final class PublishDrawResultCommand extends Command
{
    protected $signature = 'lottery:publish-result
        {draw : The draw id or draw number}
        {--first-prize= : The official first prize digit string}
        {--bottom-two= : The official bottom-two digit string}
        {--operator-id= : Existing operator user id responsible for this action}
        {--confirm : Confirm a previously ingested result instead of ingesting}
        {--yes : Skip the interactive acknowledgement prompt}';

    protected $description = 'Ingest or independently confirm an official draw result';

    public function __construct(
        private readonly DrawResultIngestionService $ingestion,
        private readonly DrawResultConfirmationService $confirmation,
        private readonly DrawResultPublicationService $publication,
        private readonly DrawScheduleService $schedule,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $draw = $this->resolveDraw((string) $this->argument('draw'));

        if (! $draw instanceof Draw) {
            $this->error(sprintf('No draw matches "%s".', (string) $this->argument('draw')));

            return self::FAILURE;
        }

        $operatorId = $this->resolveOperatorId();

        if ($operatorId === null) {
            return self::FAILURE;
        }

        $firstPrize = $this->option('first-prize');
        $bottomTwo = $this->option('bottom-two');

        if (! is_string($firstPrize) || trim($firstPrize) === '') {
            $firstPrize = (string) $this->ask(sprintf(
                'Official first prize (%d digits)',
                (int) config('lottery.results.first_prize_digits', 6),
            ));
        }

        if (! is_string($bottomTwo) || trim($bottomTwo) === '') {
            $bottomTwo = (string) $this->ask('Official bottom two digits');
        }

        $firstPrize = trim($firstPrize);
        $bottomTwo = trim($bottomTwo);
        $confirming = (bool) $this->option('confirm');
        $action = $confirming ? 'CONFIRM AND PUBLISH' : 'INGEST FOR REVIEW';

        $this->table(
            ['Action', 'Draw', 'Status', 'Operator', 'First prize', 'Bottom two'],
            [[
                $action,
                (string) $draw->draw_number,
                (string) $draw->status->value,
                (string) $operatorId,
                $firstPrize,
                $bottomTwo,
            ]],
        );

        if ($confirming) {
            $this->warn('Confirmation publishes the already-ingested numbers. The confirming operator must differ from the ingesting operator.');
        } else {
            $this->warn('This step does not publish. A different operator must review and confirm the Pending ingestion.');
        }

        if (! (bool) $this->option('yes') && ! $this->confirm($action.'?', false)) {
            $this->line('Cancelled. Nothing was changed.');

            return self::SUCCESS;
        }

        try {
            if (! $confirming) {
                return $this->ingest($draw, $operatorId, $firstPrize, $bottomTwo);
            }

            return $this->confirmAndPublish($draw, $operatorId, $firstPrize, $bottomTwo);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            Log::warning('lottery.result_workflow.refused', [
                'draw_id' => (int) $draw->getKey(),
                'draw_number' => (string) $draw->draw_number,
                'operator_id' => $operatorId,
                'action' => $confirming ? 'confirm' : 'ingest',
                'exception' => $exception,
            ]);

            return self::FAILURE;
        }
    }

    private function ingest(Draw $draw, int $operatorId, string $firstPrize, string $bottomTwo): int
    {
        $result = $this->ingestion->ingest(
            (int) $draw->getKey(),
            [
                'first_prize' => $firstPrize,
                'bottom_two' => $bottomTwo,
            ],
            'operator-cli',
            $operatorId,
        );

        $this->info(sprintf(
            'Ingested draw %s as Pending (fingerprint %s). A different operator must confirm it.',
            (string) $draw->draw_number,
            (string) $result['fingerprint'],
        ));

        Log::info('lottery.result_ingested', [
            'draw_id' => (int) $draw->getKey(),
            'draw_number' => (string) $draw->draw_number,
            'operator_id' => $operatorId,
            'fingerprint' => (string) $result['fingerprint'],
            'no_op' => (bool) $result['no_op'],
        ]);

        return self::SUCCESS;
    }

    private function confirmAndPublish(
        Draw $draw,
        int $operatorId,
        string $firstPrize,
        string $bottomTwo,
    ): int {
        $confirmed = $this->confirmation->confirm(
            (int) $draw->getKey(),
            $operatorId,
            [
                'first_prize' => $firstPrize,
                'bottom_two' => $bottomTwo,
            ],
        );

        $winningNumbers = $this->publication->winningNumbersFor((int) $draw->getKey());

        $this->info(sprintf(
            'Confirmed and published draw %s: result id %d, %d winning-number row(s).',
            (string) $draw->draw_number,
            (int) $confirmed['draw_result_id'],
            count($winningNumbers),
        ));

        Log::info('lottery.result_confirmed_and_published', [
            'draw_id' => (int) $draw->getKey(),
            'draw_number' => (string) $draw->draw_number,
            'operator_id' => $operatorId,
            'draw_result_id' => (int) $confirmed['draw_result_id'],
            'winning_numbers' => count($winningNumbers),
        ]);

        $delay = (int) config('lottery.automation.settlement_delay_minutes', 0);

        if ($this->schedule->autoSettleEnabled() && $this->schedule->automationEnabled()) {
            $this->line(sprintf(
                'Automatic settlement will run in about %d minute(s).',
                $delay,
            ));
        } else {
            $this->line(sprintf(
                'Automatic settlement is off. To settle: php artisan lottery:settle-draws --draw=%s',
                (string) $draw->draw_number,
            ));
        }

        return self::SUCCESS;
    }

    private function resolveOperatorId(): ?int
    {
        $candidate = $this->option('operator-id');

        if (! is_string($candidate) || ! ctype_digit($candidate) || (int) $candidate < 1) {
            $this->error('--operator-id= is required and must be a positive existing user id.');

            return null;
        }

        $operatorId = (int) $candidate;

        if (! User::query()->whereKey($operatorId)->exists()) {
            $this->error(sprintf('Operator user %d does not exist.', $operatorId));

            return null;
        }

        return $operatorId;
    }

    private function resolveDraw(string $identifier): ?Draw
    {
        return Draw::query()
            ->where('draw_number', $identifier)
            ->when(
                is_numeric($identifier),
                fn ($query) => $query->orWhere('id', (int) $identifier),
            )
            ->first();
    }
}
