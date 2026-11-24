<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComplianceCaseResource;

use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\User;
use App\Services\Compliance\ComplianceCaseService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use App\Support\Admin\OperatorActionFactory;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Tables\Actions\Action as TableAction;

/**
 * The four moves an AML file can take, declared once and rendered twice.
 *
 * The queue row action and the record header action are the same declaration, so a move
 * cannot exist on one screen and not the other. Every spec delegates to
 * ComplianceCaseService, which owns the transition map, the note-length rule, the
 * admin-authority rule on closure, and the audit line.
 *
 * WHAT VISIBILITY IS AND IS NOT
 *
 * The `visible` closure is a cheap pre-filter built from the same two facts the service
 * checks — the operator's permission and the enum's transition map — so nobody is offered a
 * button that is guaranteed to fail. It is not the rule: the service refuses an illegal move
 * whether or not a button was shown, and its refusal text is what the operator sees
 * (OperatorActionFactory::run reports it verbatim instead of a green "saved").
 *
 * Read a `visible` closure as "would this click probably do anything", never as
 * "is this allowed".
 */
final class ComplianceCaseActions
{
    /**
     * @return list<TableAction>
     */
    public static function forTable(): array
    {
        return OperatorActionFactory::forTable(self::specs());
    }

    /**
     * @return list<Action>
     */
    public static function forPage(): array
    {
        return OperatorActionFactory::forPage(self::specs());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function specs(): array
    {
        return [
            [
                'name' => 'investigate',
                'label' => 'Start investigating',
                'icon' => 'heroicon-o-magnifying-glass',
                'color' => 'info',
                'heading' => 'Start investigating',
                'description' => 'Marks the file as under investigation and records who moved it. It claims nothing was found.',
                'visible' => static fn (ComplianceCase $record): bool => self::mayMove()
                    && $record->status->canTransitionTo(ComplianceCaseStatus::Investigating),
                'action' => static function (ComplianceCase $record): void {
                    OperatorActionFactory::run(
                        static fn () => app(ComplianceCaseService::class)->investigate($record),
                        'The file is now under investigation.',
                    );
                },
            ],
            [
                'name' => 'escalate',
                'label' => 'Escalate',
                'icon' => 'heroicon-o-arrow-trending-up',
                'color' => 'warning',
                'heading' => 'Escalate this file',
                'description' => 'Above the desk\'s own authority. The service refuses a reason under 8 characters: an escalation the next reader cannot understand is not an escalation.',
                'visible' => static fn (ComplianceCase $record): bool => self::mayMove()
                    && $record->status->canTransitionTo(ComplianceCaseStatus::Escalated),
                'form' => [
                    Forms\Components\Textarea::make('reason')
                        ->label('Escalation reason')
                        ->required()
                        ->minLength(8)
                        ->maxLength(255),
                ],
                'action' => static function (ComplianceCase $record, array $data): void {
                    OperatorActionFactory::run(
                        static fn () => app(ComplianceCaseService::class)->escalate($record, (string) $data['reason']),
                        'The file is escalated.',
                    );
                },
            ],
            [
                'name' => 'resolve',
                'label' => 'Resolve',
                'icon' => 'heroicon-o-check-badge',
                'color' => 'success',
                'heading' => 'Resolve this file',
                'description' => 'What was found and what was decided, in a full sentence — this is the line an auditor reads first.',
                'visible' => static fn (ComplianceCase $record): bool => self::mayMove()
                    && $record->status->canTransitionTo(ComplianceCaseStatus::Resolved),
                'form' => [
                    Forms\Components\Textarea::make('note')
                        ->label('Resolution note')
                        ->required()
                        ->minLength(8)
                        ->maxLength(255),
                ],
                'action' => static function (ComplianceCase $record, array $data): void {
                    $operator = AdminAccess::operator();

                    if (! $operator instanceof User) {
                        return;
                    }

                    OperatorActionFactory::run(
                        static fn () => app(ComplianceCaseService::class)->resolve($record, (string) $data['note'], (int) $operator->getKey()),
                        'The file is resolved and the note is on the record.',
                    );
                },
            ],
            [
                'name' => 'close',
                'label' => 'Close file',
                'icon' => 'heroicon-o-lock-closed',
                'color' => 'gray',
                'heading' => 'Close this file',
                'description' => 'Seals the file permanently. Requires admin authority (the service refuses anyone else by name) and refuses while an unreleased control hold remains.',
                'confirm' => true,
                'visible' => static fn (ComplianceCase $record): bool => self::mayMove()
                    && self::isAdminOperator()
                    && $record->status->canTransitionTo(ComplianceCaseStatus::Closed),
                'action' => static function (ComplianceCase $record): void {
                    $operator = AdminAccess::operator();

                    if (! $operator instanceof User) {
                        return;
                    }

                    OperatorActionFactory::run(
                        static fn () => app(ComplianceCaseService::class)->close($record, (int) $operator->getKey()),
                        'The file is closed.',
                    );
                },
            ],
        ];
    }

    private static function mayMove(): bool
    {
        return AdminAccess::permits(AdminPermission::AML_REVIEW);
    }

    /**
     * Closure authority, mirroring ComplianceCaseService::close()'s own check so a non-admin
     * is not offered a verb that is guaranteed to be refused. The service remains the rule.
     */
    private static function isAdminOperator(): bool
    {
        $operator = AdminAccess::operator();

        return $operator instanceof User && $operator->isAdmin();
    }
}
