<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Support\AbstractLotteryResultVersion;
use App\Models\User;
use App\Services\Lottery\OperatorResultEntryService;
use App\Support\Admin\AdminAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

/**
 * Operator result entry: the manual path, with two people, for the four sourced
 * lottery lanes.
 *
 * ============================================================================
 * WHY THIS PAGE EXISTS AT ALL
 * ============================================================================
 * When a feed is late, the numbers still have to be recorded before the claim
 * window opens. The choice is not "manual entry or nothing" — it is "manual
 * entry with a second signature and an audit row, or somebody editing a table
 * from a console". This page is the first of those.
 *
 * ============================================================================
 * WHAT THIS CLASS WILL NOT DO
 * ============================================================================
 *
 *   - It does not write a result. Every mutation goes through
 *     {@see OperatorResultEntryService}, which delegates to the lane's own
 *     import engine. There is no `->update()`, no `->save()` and no query
 *     builder call in this file, so the field widths, the leading-zero rule,
 *     the fingerprint, the conflict rule and the supersede-never-overwrite rule
 *     are the ones the import engine already enforces.
 *
 *   - It does not let one person publish. `approve()` is a second action, and it
 *     refuses when the approver is the importer. There is no UI affordance that
 *     merges the two steps into one click, because a combined button is exactly
 *     the control this workflow exists to provide.
 *
 *   - It does not render a stored result for editing. A published result is
 *     immutable; a correction is a NEW version, which the import engine
 *     supersedes. The page shows the pending queue and the entry form, and
 *     nothing that looks like an edit box over live numbers.
 *
 *   - It does not echo operator input back. Success and failure messages come
 *     from the outcome the service returned; the substrings a visitor typed are
 *     never interpolated into a notification.
 *
 * ============================================================================
 * ACCESS
 * ============================================================================
 * Importing requires MANAGE_DRAWS (a result is a draw fact). Approving requires
 * PROCESS_DRAWS. They are separate permissions on purpose: an operator who may
 * type numbers is not automatically an operator who may release them.
 */
class OperatorResultEntryPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Lottery Operations';

    protected static ?string $navigationLabel = 'Operator result entry';

    protected static ?string $title = 'Operator result entry';

    protected static ?int $navigationSort = 12;

    protected static string $view = 'filament.pages.operator-result-entry-page';

    /** The lane currently selected in the form. */
    public string $lane = 'national';

    /**
     * The values for the lane's text fields, keyed by field name.
     *
     * @var array<string, string>
     */
    public array $fields = [];

    /** The reason captured at approval time. Never pre-filled. */
    public string $approvalReason = '';

    /** The version awaiting a second signature, when one has been selected. */
    public ?int $selectedVersionId = null;

    public static function canAccess(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::MANAGE_DRAWS,
            AdminAccess::PROCESS_DRAWS,
            AdminAccess::VIEW_DRAWS,
        ]);
    }

    /**
     * A read-only auditor may look at the queue and do nothing else.
     */
    public function canSubmit(): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_DRAWS) && ! AdminAccess::isReadOnly();
    }

    public function canApprove(): bool
    {
        return AdminAccess::current(AdminAccess::PROCESS_DRAWS) && ! AdminAccess::isReadOnly();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $service = app(OperatorResultEntryService::class);

        return [
            'lanes' => $service->lanes(),
            'lane' => $this->lane,
            'fields' => $this->fields,
            'pending' => $this->pendingLane(),
            'canSubmit' => $this->canSubmit(),
            'canApprove' => $this->canApprove(),
            'selectedVersionId' => $this->selectedVersionId,
            'approvalReason' => $this->approvalReason,
        ];
    }

    /**
     * The pending queue for the selected lane, or an empty list when the lane
     * selection is not one this service supports.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingLane(): array
    {
        $service = app(OperatorResultEntryService::class);

        try {
            return $service->pendingApprovals($this->lane);
        } catch (Throwable) {
            // An unsupported lane is a UI state (the select was tampered with or
            // the config changed under the page), not an error page.
            return [];
        }
    }

    /**
     * Operator A: store the numbers, withhold publication.
     *
     * The payload is assembled from the lane's own declared fields so the form
     * cannot submit a column the import engine does not know about. Field
     * values are passed through as typed — trimming and width validation belong
     * to the engine, and doing them twice is how a leading zero gets lost.
     */
    public function submit(): void
    {
        if (! $this->canSubmit()) {
            $this->refuse('You do not have permission to record a result.');

            return;
        }

        $actor = AdminAccess::operator();

        if (! $actor instanceof User) {
            $this->refuse('No operator session; nothing was recorded.');

            return;
        }

        $payload = [];

        foreach ($this->fields as $field => $value) {
            if (is_string($field) && $field !== '' && is_string($value) && trim($value) !== '') {
                $payload[$field] = $value;
            }
        }

        if ($payload === []) {
            $this->refuse('Enter at least one value before submitting.');

            return;
        }

        try {
            $outcome = app(OperatorResultEntryService::class)->submit($this->lane, $payload, $actor);
        } catch (Throwable $e) {
            // The exception message is a statement about configuration or
            // permission, never about the numbers, so it is safe to show.
            $this->refuse($e->getMessage());

            return;
        }

        $status = (string) ($outcome['status'] ?? 'unknown');

        if ($status === 'rejected') {
            Notification::make()
                ->title('Entry rejected')
                ->body($this->joinErrors($outcome))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(match ($status) {
                'conflict' => 'Conflict recorded — publication withheld',
                'duplicate' => 'Identical entry already stored',
                default => 'Entry stored — awaiting a second signature',
            })
            ->body(sprintf(
                'Draw %s, version %s. Nothing is public until a different operator approves it.',
                (string) ($outcome['draw_reference'] ?? '—'),
                (string) ($outcome['version'] ?? '—'),
            ))
            ->success()
            ->send();

        // The form is cleared because the values are now a stored version; a
        // second submit would be an attempt to enter the same result again.
        $this->fields = [];
    }

    /**
     * Operator B: approve and publish.
     */
    public function approve(): void
    {
        if (! $this->canApprove()) {
            $this->refuse('You do not have permission to approve a result.');

            return;
        }

        $actor = AdminAccess::operator();

        if (! $actor instanceof User) {
            $this->refuse('No operator session; nothing was approved.');

            return;
        }

        if ($this->selectedVersionId === null) {
            $this->refuse('Select the pending version you intend to approve.');

            return;
        }

        $version = $this->findVersion($this->selectedVersionId);

        if ($version === null) {
            $this->refuse('That version is no longer available.');

            return;
        }

        try {
            $outcome = app(OperatorResultEntryService::class)
                ->approve($this->lane, $version, $actor, $this->approvalReason);
        } catch (Throwable $e) {
            $this->refuse($e->getMessage());

            return;
        }

        if ((string) ($outcome['status'] ?? '') === 'rejected') {
            Notification::make()
                ->title('Approval refused')
                ->body($this->joinErrors($outcome))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Approved and published')
            ->body(sprintf(
                'Draw %s, version %s is now the published result.',
                (string) ($outcome['draw_reference'] ?? '—'),
                (string) ($outcome['version'] ?? '—'),
            ))
            ->success()
            ->send();

        $this->approvalReason = '';
        $this->selectedVersionId = null;
    }

    /**
     * Resolve a pending version by id, scoped to the selected lane's model.
     *
     * Scoping matters: an id from the weekly lane must not be approvable through
     * the national engine, because each engine's `mayPublish()` reads its own
     * config and its own publication rules.
     */
    private function findVersion(int $id): ?AbstractLotteryResultVersion
    {
        $model = match ($this->lane) {
            'national' => \App\Models\NationalLotteryResultVersion::class,
            'weekly' => \App\Models\WeeklyLotteryResultVersion::class,
            'bingo' => \App\Models\BingoLotteryResultVersion::class,
            'pcso' => \App\Models\PcsoLotteryResultVersion::class,
            default => null,
        };

        if ($model === null) {
            return null;
        }

        /** @var class-string<AbstractLotteryResultVersion> $model */
        $version = $model::query()->whereKey($id)->first();

        return $version instanceof AbstractLotteryResultVersion ? $version : null;
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function joinErrors(array $outcome): string
    {
        $codes = array_values(array_map('strval', (array) ($outcome['errors'] ?? [])));

        if ($codes === []) {
            return (string) ($outcome['message'] ?? 'The entry was not accepted.');
        }

        return implode(', ', $codes);
    }

    private function refuse(string $message): void
    {
        Notification::make()
            ->title('Refused')
            ->body($message)
            ->danger()
            ->send();
    }
}
