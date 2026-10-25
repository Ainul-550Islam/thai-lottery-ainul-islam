<?php

declare(strict_types=1);

namespace App\Filament\Resources\DataSubjectRequestResource;

use App\Enums\PrivacyRequestStatus;
use App\Enums\PrivacyRequestType;
use App\Exceptions\Privacy\PrivacyRequestException;
use App\Models\DataSubjectRequest;
use App\Models\DataSubjectRequestEvent;
use App\Models\PersonalDataExport;
use App\Models\User;
use App\Services\Privacy\PersonalDataExporter;
use App\Services\Privacy\PrivacyRequestService;
use App\Services\Privacy\PrivacyRetentionGuard;
use App\Support\Admin\OperatorActionFactory;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Every move the compliance desk can make on a request.
 *
 * =========================================================================
 * ONE SPEC PER ACTION, RENDERED TWICE
 * =========================================================================
 * Each declaration below is used both as a row action in the queue and as a
 * header action on the view screen (through OperatorActionFactory), so the queue
 * and the record can never offer different buttons for the same state.
 *
 * =========================================================================
 * NOTHING HERE DECIDES ANYTHING
 * =========================================================================
 * Every action calls PrivacyRequestService or PersonalDataExporter, whose
 * refusals arrive as PrivacyRequestException with their own codes. The panel
 * shows the service's refusal verbatim; it never invents a success, never writes
 * a status directly, and never treats "the button was clicked" as "the thing
 * happened".
 *
 * =========================================================================
 * ERASURE IS NOT A DELETE BUTTON
 * =========================================================================
 * The "Run retention check" action answers a question: which records must be
 * kept, and why. It never removes anything - no code in this lane deletes a
 * request, an account or a ledger line. Completing an erasure request completes
 * a REVIEW; the actual deletion of eligible data is a documented operator task
 * performed against the retention report, outside this panel.
 */
final class DataSubjectRequestActions
{
    /**
     * @return list<\Filament\Tables\Actions\Action>
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
                // Acknowledge is the FIRST staff move and nothing more: it takes
                // the request out of the queue's "nobody has touched this" state
                // and starts the review clock the compliance desk watches. It
                // deliberately does not claim anything was decided.
                'name' => 'acknowledge',
                'label' => (string) trans('privacy_requests.filament.actions.acknowledge'),
                'icon' => 'heroicon-o-hand-raised',
                'color' => 'info',
                'heading' => (string) trans('privacy_requests.filament.actions.acknowledge'),
                'description' => (string) trans('privacy_requests.filament.action_fields.note'),
                'visible' => static fn (DataSubjectRequest $record): bool => self::canAct()
                    && $record->status === PrivacyRequestStatus::Received,
                'action' => static function (DataSubjectRequest $record): void {
                    self::move($record, PrivacyRequestStatus::InReview);
                },
            ],
            [
                // Start review moves an acknowledged request into the work the
                // requester is waiting for. Separate from Acknowledge because
                // "we have it" and "we are working on it" are different
                // promises, and the requester is told different things.
                'name' => 'start_review',
                'label' => (string) trans('privacy_requests.filament.actions.start_review'),
                'icon' => 'heroicon-o-eye',
                'color' => 'primary',
                'heading' => (string) trans('privacy_requests.filament.actions.start_review'),
                'description' => (string) trans('privacy_requests.filament.action_fields.note'),
                'visible' => static fn (DataSubjectRequest $record): bool => self::canAct()
                    && $record->status === PrivacyRequestStatus::InReview,
                'action' => static function (DataSubjectRequest $record): void {
                    self::move($record, PrivacyRequestStatus::InProgress);
                },
            ],
            [
                'name' => 'request_information',
                'label' => (string) trans('privacy_requests.filament.actions.request_information'),
                'icon' => 'heroicon-o-question-mark-circle',
                'color' => 'warning',
                'heading' => (string) trans('privacy_requests.filament.actions.request_information'),
                'description' => (string) trans('privacy_requests.filament.action_fields.note_required'),
                'submitLabel' => (string) trans('privacy_requests.filament.actions.request_information'),
                'form' => [
                    Forms\Components\Textarea::make('note')
                        ->label((string) trans('privacy_requests.filament.action_fields.note'))
                        ->required()
                        ->minLength(10)
                        ->maxLength(1000),
                ],
                'visible' => static fn (DataSubjectRequest $record): bool => self::canAct()
                    && in_array($record->status, [
                        PrivacyRequestStatus::Received,
                        PrivacyRequestStatus::InReview,
                        PrivacyRequestStatus::InProgress,
                    ], true),
                'action' => static function (DataSubjectRequest $record, array $data): void {
                    self::move($record, PrivacyRequestStatus::NeedsInformation, (string) ($data['note'] ?? ''));
                },
            ],
            [
                'name' => 'resume_review',
                'label' => (string) trans('privacy_requests.filament.actions.resume_review'),
                'icon' => 'heroicon-o-arrow-path',
                'color' => 'info',
                'heading' => (string) trans('privacy_requests.filament.actions.resume_review'),
                'visible' => static fn (DataSubjectRequest $record): bool => self::canAct()
                    && $record->status === PrivacyRequestStatus::NeedsInformation,
                'action' => static function (DataSubjectRequest $record): void {
                    self::move($record, PrivacyRequestStatus::InReview);
                },
            ],
            [
                'name' => 'retention_check',
                'label' => (string) trans('privacy_requests.filament.actions.retention_check'),
                'icon' => 'heroicon-o-lock-closed',
                'color' => 'gray',
                'heading' => (string) trans('privacy_requests.filament.retention.heading'),
                'description' => (string) trans('privacy_requests.filament.retention.blocked'),
                'visible' => static fn (DataSubjectRequest $record): bool => self::canAct()
                    && in_array($record->type, [PrivacyRequestType::Erasure, PrivacyRequestType::Restriction], true),
                'action' => static function (DataSubjectRequest $record): void {
                    OperatorActionFactory::run(static function () use ($record): void {
                        $report = app(PrivacyRetentionGuard::class)->evaluate($record);

                        app(PrivacyRequestService::class)->recordEvent(
                            $record,
                            DataSubjectRequestEvent::EVENT_RETENTION_CHECK,
                            DataSubjectRequestEvent::ACTOR_STAFF,
                            self::operatorId(),
                            'blocked:'.($report['blocked'] ? 'yes' : 'no').' reasons:'.implode(',', array_column($report['reasons'], 'code')),
                        );

                        $lines = [];

                        foreach ($report['reasons'] as $reason) {
                            $lines[] = (string) trans($reason['label_key']);
                        }

                        Notification::make()
                            ->{$report['blocked'] ? 'warning' : 'success'}()
                            ->title((string) trans(
                                $report['blocked'] ? 'privacy_requests.filament.retention.blocked' : 'privacy_requests.filament.retention.clear',
                            ))
                            ->body(implode("\n", $lines))
                            ->persistent()
                            ->send();
                    });
                },
            ],
            [
                'name' => 'build_export',
                'label' => (string) trans('privacy_requests.filament.actions.build_export'),
                'icon' => 'heroicon-o-archive-box-arrow-down',
                'color' => 'primary',
                'confirm' => true,
                'heading' => (string) trans('privacy_requests.filament.actions.build_export'),
                'description' => (string) trans('privacy_requests.filament.actions.build_export'),
                'visible' => static fn (DataSubjectRequest $record): bool => self::canAct()
                    && $record->isVerified()
                    && in_array($record->type, [PrivacyRequestType::Access, PrivacyRequestType::Portability], true)
                    && in_array($record->status, [PrivacyRequestStatus::InReview, PrivacyRequestStatus::InProgress], true),
                'action' => static function (DataSubjectRequest $record): void {
                    OperatorActionFactory::run(static function () use ($record): void {
                        $export = app(PersonalDataExporter::class)->buildFor($record);

                        Notification::make()
                            ->success()
                            ->title((string) trans('privacy_requests.filament.notifications.export_queued'))
                            ->body($record->reference.' · '.$export->humanSize())
                            ->send();
                    });
                },
            ],
            [
                'name' => 'release_export',
                'label' => (string) trans('privacy_requests.filament.actions.release_export'),
                'icon' => 'heroicon-o-paper-airplane',
                'color' => 'success',
                'confirm' => true,
                'heading' => (string) trans('privacy_requests.filament.actions.release_export'),
                'description' => (string) trans('privacy_requests.filament.notifications.export_released'),
                'visible' => static fn (DataSubjectRequest $record): bool => self::canAct()
                    && $record->isVerified()
                    && $record->latestExport instanceof PersonalDataExport
                    && ! $record->latestExport->isReleased()
                    && $record->latestExport->isReady(),
                'action' => static function (DataSubjectRequest $record): void {
                    OperatorActionFactory::run(static function () use ($record): void {
                        $export = $record->latestExport;

                        if (! $export instanceof PersonalDataExport || ! $export->isReady()) {
                            throw PrivacyRequestException::exportNotReady($record->reference);
                        }

                        app(PersonalDataExporter::class)->release($export);
                    });
                },
            ],
            [
                'name' => 'assign_to_me',
                'label' => (string) trans('privacy_requests.filament.actions.assign_to_me'),
                'icon' => 'heroicon-o-user-circle',
                'color' => 'gray',
                'visible' => static fn (DataSubjectRequest $record): bool => self::canAct(),
                'action' => static function (DataSubjectRequest $record): void {
                    OperatorActionFactory::run(static function () use ($record): void {
                        $operator = self::operator();

                        if (! $operator instanceof User) {
                            throw new \RuntimeException('No authenticated operator.');
                        }

                        $record->assigned_to = (int) $operator->getKey();
                        $record->save();

                        app(PrivacyRequestService::class)->recordEvent(
                            $record,
                            DataSubjectRequestEvent::EVENT_NOTE,
                            DataSubjectRequestEvent::ACTOR_STAFF,
                            (int) $operator->getKey(),
                            'assigned_to:'.(int) $operator->getKey(),
                        );

                        Notification::make()
                            ->success()
                            ->title((string) trans('privacy_requests.filament.notifications.assigned'))
                            ->send();
                    });
                },
            ],
            [
                'name' => 'note',
                'label' => (string) trans('privacy_requests.filament.actions.note'),
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'color' => 'gray',
                'heading' => (string) trans('privacy_requests.filament.actions.note'),
                'description' => (string) trans('privacy_requests.filament.action_fields.note'),
                'submitLabel' => (string) trans('privacy_requests.filament.actions.note'),
                'form' => [
                    Forms\Components\Textarea::make('body')
                        ->label((string) trans('privacy_requests.filament.action_fields.note'))
                        ->required()
                        ->minLength(3)
                        ->maxLength(2000),
                ],
                'visible' => static fn (DataSubjectRequest $record): bool => self::canAct(),
                'action' => static function (DataSubjectRequest $record, array $data): void {
                    OperatorActionFactory::run(static function () use ($record, $data): void {
                        app(PrivacyRequestService::class)->note(
                            $record,
                            DataSubjectRequestEvent::ACTOR_STAFF,
                            self::operatorId(),
                            (string) ($data['body'] ?? ''),
                        );

                        Notification::make()
                            ->success()
                            ->title((string) trans('privacy_requests.filament.notifications.note_added'))
                            ->send();
                    });
                },
            ],
            [
                'name' => 'complete',
                'label' => (string) trans('privacy_requests.filament.actions.complete'),
                'icon' => 'heroicon-o-check-badge',
                'color' => 'success',
                'heading' => (string) trans('privacy_requests.filament.actions.complete'),
                'description' => (string) trans('privacy_requests.filament.action_fields.completion_note'),
                'submitLabel' => (string) trans('privacy_requests.filament.actions.complete'),
                'form' => [
                    Forms\Components\Textarea::make('note')
                        ->label((string) trans('privacy_requests.filament.action_fields.completion_note'))
                        ->required()
                        ->minLength(10)
                        ->maxLength(2000),

                    // Shown ONLY for an erasure or a restriction, and only then
                    // is it the thing that lets a blocked request close. The
                    // service refuses without it; the field existing here is the
                    // operator's way to comply, not a way to skip the check.
                    Forms\Components\Textarea::make('retention_override_reason')
                        ->label((string) trans('privacy_requests.filament.action_fields.retention_override'))
                        ->helperText((string) trans('privacy_requests.filament.action_fields.retention_override_hint'))
                        ->visible(fn (DataSubjectRequest $record): bool => $record->type->touchesRetention())
                        ->minLength(PrivacyRequestService::RETENTION_OVERRIDE_MIN_LENGTH)
                        ->maxLength(2000),
                ],
                'visible' => static fn (DataSubjectRequest $record): bool => self::canAct()
                    && $record->isVerified()
                    && $record->status->canTransitionTo(PrivacyRequestStatus::Completed),
                'action' => static function (DataSubjectRequest $record, array $data): void {
                    self::move(
                        $record,
                        PrivacyRequestStatus::Completed,
                        (string) ($data['note'] ?? ''),
                        null,
                        (string) ($data['retention_override_reason'] ?? ''),
                    );
                },
            ],
            [
                'name' => 'reject',
                'label' => (string) trans('privacy_requests.filament.actions.reject'),
                'icon' => 'heroicon-o-x-circle',
                'color' => 'danger',
                'heading' => (string) trans('privacy_requests.filament.actions.reject'),
                'description' => (string) trans('privacy_requests.filament.action_fields.reason'),
                'submitLabel' => (string) trans('privacy_requests.filament.actions.reject'),
                'form' => [
                    Forms\Components\Select::make('reason_code')
                        ->label((string) trans('privacy_requests.filament.action_fields.reason'))
                        // For an erasure or a restriction the ONLY lawful reason
                        // to refuse is the retention obligation, so the other
                        // codes are not offered: a rejection filed as "duplicate"
                        // would be a false record of why somebody's data was kept.
                        ->options(fn (DataSubjectRequest $record): array => collect(
                            $record->type->touchesRetention()
                                ? [PrivacyRequestService::RETENTION_REASON_CODE]
                                : array_keys((array) trans('privacy_requests.reasons')),
                        )
                            ->mapWithKeys(fn (string $code): array => [$code => (string) trans('privacy_requests.reasons.'.$code)])
                            ->all())
                        ->default(fn (DataSubjectRequest $record): ?string => $record->type->touchesRetention()
                            ? PrivacyRequestService::RETENTION_REASON_CODE
                            : null)
                        ->required(),
                    Forms\Components\Textarea::make('note')
                        ->label((string) trans('privacy_requests.filament.action_fields.note'))
                        ->required()
                        ->minLength(10)
                        ->maxLength(2000),
                ],
                'visible' => static fn (DataSubjectRequest $record): bool => self::canAct()
                    && $record->status->canTransitionTo(PrivacyRequestStatus::Rejected),
                'action' => static function (DataSubjectRequest $record, array $data): void {
                    self::move(
                        $record,
                        PrivacyRequestStatus::Rejected,
                        (string) ($data['note'] ?? ''),
                        (string) ($data['reason_code'] ?? ''),
                    );
                },
            ],
        ];
    }

    /**
     * One place where a transition is attempted and reported.
     *
     * The service is the authority. If it refuses, the operator sees its code
     * and nothing was written - which is exactly the behaviour a stale screen
     * needs (two reviewers on one request).
     */
    private static function move(
        DataSubjectRequest $record,
        PrivacyRequestStatus $target,
        ?string $note = null,
        ?string $reasonCode = null,
        ?string $retentionOverrideReason = null,
    ): void {
        try {
            $updated = app(PrivacyRequestService::class)->transition(
                $record,
                $target,
                DataSubjectRequestEvent::ACTOR_STAFF,
                self::operatorId(),
                $note === '' ? null : $note,
                true,
                $reasonCode === '' ? null : $reasonCode,
                $retentionOverrideReason === '' ? null : $retentionOverrideReason,
            );

            Notification::make()
                ->success()
                ->title((string) trans('privacy_requests.filament.notifications.transitioned', [
                    'status' => (string) trans('privacy_requests.statuses.'.$updated->status->value),
                ]))
                ->send();
        } catch (PrivacyRequestException $exception) {
            $retention = in_array($exception->errorCode(), [
                PrivacyRequestException::CODE_RETENTION_BLOCKED,
                PrivacyRequestException::CODE_RETENTION_REASON_REQUIRED,
            ], true);

            Notification::make()
                ->danger()
                ->title((string) trans($retention
                    ? 'privacy_requests.filament.notifications.retention_refused'
                    : 'privacy_requests.filament.notifications.invalid_transition'))
                ->body($exception->getMessage())
                ->persistent()
                ->send();
        } catch (Throwable $exception) {
            // Anything else is a defect, not a refusal: report it, but do not
            // pretend the move happened.
            report($exception);

            Notification::make()
                ->danger()
                ->title((string) trans('privacy_requests.filament.notifications.invalid_transition'))
                ->persistent()
                ->send();
        }
    }

    private static function operator(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private static function operatorId(): ?int
    {
        return self::operator()?->getKey();
    }

    private static function canAct(): bool
    {
        return \App\Support\Admin\AdminAccess::allows(
            self::operator(),
            \App\Support\Admin\AdminAccess::MANAGE_USERS,
        );
    }
}
