<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\GloFreezeException;
use App\Models\GloTicketFreeze;
use App\Models\User;
use App\Services\Lottery\GloTicketFreezeService;
use App\Support\Admin\AdminAccess;
use Illuminate\Console\Command;

/**
 * Freeze case lifecycle CLI (GLO-11): review | approve | reject | release | expire-one.
 *
 * Every transition is performed by GloTicketFreezeService under a row lock and
 * written to AuditLog. --actor is mandatory (default deny without identity).
 */
class GloFreezeCase extends Command
{
    protected $signature = 'glo:freeze-case
        {action : review|approve|reject|release|expire}
        {case : freeze_case_id}
        {--actor= : Operator email (required)}
        {--reason= : Transition reason}
        {--json : JSON output}';

    protected $description = 'Transition a GLO freeze case through the GLO-11 state machine';

    public function handle(GloTicketFreezeService $service): int
    {
        $action = (string) $this->argument('action');
        $caseId = (string) $this->argument('case');
        $email = (string) $this->option('actor');
        $reason = (string) ($this->option('reason') ?: '');

        if ($email === '') {
            $this->error('--actor=<email> is required (default deny).');

            return self::FAILURE;
        }

        $actor = User::query()->where('email', $email)->first();

        if ($actor === null || ! $actor->isActive()) {
            $this->error('Actor not found or inactive.');

            return self::FAILURE;
        }

        if (! AdminAccess::allows($actor, AdminAccess::REVIEW_GLO_FREEZES)) {
            $this->error('Actor lacks the "review glo freezes" permission.');

            return self::FAILURE;
        }

        $freeze = GloTicketFreeze::query()->where('freeze_case_id', $caseId)->first();

        if ($freeze === null) {
            $this->error('Freeze case not found: '.$caseId);

            return self::FAILURE;
        }

        try {
            $updated = match ($action) {
                'review' => $service->startReview($freeze, $actor),
                'approve' => $service->approveToFrozen($freeze, $actor, $reason !== '' ? $reason : null),
                'reject' => $service->reject($freeze, $actor, $reason !== '' ? $reason : 'Rejected'),
                'release' => $service->release($freeze, $actor, $reason !== '' ? $reason : 'Released'),
                'expire' => $service->expire($freeze, $actor, $reason !== '' ? $reason : null),
                default => throw new GloFreezeException('Unknown action "'.$action.'"', 422),
            };
        } catch (GloFreezeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $payload = [
            'freeze_case_id' => $updated->freeze_case_id,
            'status' => $updated->status->value,
            'ticket_reference' => $updated->ticket?->ticket_reference,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf('Case %s → %s', $payload['freeze_case_id'], $payload['status']));
        }

        return self::SUCCESS;
    }
}
