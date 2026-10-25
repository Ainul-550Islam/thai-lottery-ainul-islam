<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactMessageResource;

use App\Mail\SupportReplyMail;
use App\Models\ContactMessage;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\OperatorActionFactory;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The moves a support operator can make on one message.
 *
 * =========================================================================
 * EVERY ACTION IS A SERVICE CALL
 * =========================================================================
 * State changes go through SupportTicketService::changeStatus(), which locks
 * the row, checks the configured transition table and writes the timeline entry
 * inside one transaction. Notes and replies go through the same service. This
 * class contributes labels, permissions, visibility and form fields - never a
 * database write of its own - so the panel and any future API cannot disagree
 * about what a legal transition is.
 *
 * =========================================================================
 * THE REPLY ACTION CAN REFUSE BEFORE IT IS PRESSED
 * =========================================================================
 * With no reply mailbox configured, SupportReplyMail cannot send, so the action
 * hides itself and the service refuses if it is somehow invoked. A composer that
 * opens, accepts a paragraph and then silently sends nothing is the exact
 * failure this lane is written to prevent.
 */
final class ContactMessageActions
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
                'name' => 'start_review',
                'label' => (string) trans('support_tickets.filament.actions.change_status'),
                'icon' => 'heroicon-o-eye',
                'color' => 'info',
                'heading' => (string) trans('support_tickets.filament.actions.change_status'),
                'visible' => static fn (ContactMessage $record): bool => self::canWork()
                    && $record->status === ContactMessage::STATUS_RECEIVED,
                'action' => static function (ContactMessage $record): void {
                    self::move($record, ContactMessage::STATUS_IN_REVIEW);
                },
            ],
            [
                'name' => 'resolve',
                'label' => (string) trans('support_tickets.status.resolved'),
                'icon' => 'heroicon-o-check-badge',
                'color' => 'success',
                'heading' => (string) trans('support_tickets.status.resolved'),
                'submitLabel' => (string) trans('support_tickets.status.resolved'),
                'form' => [
                    Forms\Components\Textarea::make('note')
                        ->label((string) trans('support_tickets.filament.action_fields.note'))
                        ->maxLength(2000),
                ],
                'visible' => static fn (ContactMessage $record): bool => self::canWork()
                    && in_array($record->status, [ContactMessage::STATUS_RECEIVED, ContactMessage::STATUS_IN_REVIEW], true),
                'action' => static function (ContactMessage $record, array $data): void {
                    self::move($record, ContactMessage::STATUS_RESOLVED, (string) ($data['note'] ?? ''));
                },
            ],
            [
                'name' => 'reopen',
                'label' => (string) trans('support_tickets.filament.actions.reopen'),
                'icon' => 'heroicon-o-arrow-uturn-left',
                'color' => 'warning',
                'heading' => (string) trans('support_tickets.filament.actions.reopen'),
                'visible' => static fn (ContactMessage $record): bool => self::canWork()
                    && $record->status !== ContactMessage::STATUS_RECEIVED
                    && $record->status !== ContactMessage::STATUS_IN_REVIEW,
                'action' => static function (ContactMessage $record): void {
                    self::move($record, ContactMessage::STATUS_IN_REVIEW);
                },
            ],
            [
                'name' => 'mark_spam',
                'label' => (string) trans('support_tickets.filament.actions.mark_spam'),
                'icon' => 'heroicon-o-no-symbol',
                'color' => 'danger',
                'confirm' => true,
                'heading' => (string) trans('support_tickets.filament.actions.mark_spam'),
                'description' => (string) trans('support_tickets.filament.notifications.no_delete'),
                'visible' => static fn (ContactMessage $record): bool => self::canWork()
                    && $record->status !== ContactMessage::STATUS_SPAM,
                'action' => static function (ContactMessage $record): void {
                    self::move($record, ContactMessage::STATUS_SPAM);
                },
            ],
            [
                'name' => 'archive',
                'label' => (string) trans('support_tickets.filament.actions.archive'),
                'icon' => 'heroicon-o-archive-box',
                'color' => 'gray',
                'confirm' => true,
                'visible' => static fn (ContactMessage $record): bool => self::canWork()
                    && $record->status !== ContactMessage::STATUS_ARCHIVED,
                'action' => static function (ContactMessage $record): void {
                    self::move($record, ContactMessage::STATUS_ARCHIVED);
                },
            ],
            [
                'name' => 'add_note',
                'label' => (string) trans('support_tickets.filament.actions.add_note'),
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'color' => 'gray',
                'heading' => (string) trans('support_tickets.filament.actions.add_note'),
                'submitLabel' => (string) trans('support_tickets.filament.actions.add_note'),
                'form' => [
                    Forms\Components\Textarea::make('body')
                        ->label((string) trans('support_tickets.filament.action_fields.note'))
                        ->required()
                        ->minLength(3)
                        ->maxLength(max(10, (int) config('support_tickets.note_max_length', 2000))),
                ],
                'visible' => static fn (ContactMessage $record): bool => self::canWork(),
                'action' => static function (ContactMessage $record, array $data): void {
                    OperatorActionFactory::run(static function () use ($record, $data): void {
                        $operator = self::operator();

                        if (! $operator instanceof User) {
                            throw new RuntimeException('No authenticated operator.');
                        }

                        app(SupportTicketService::class)->addNote($record, $operator, (string) ($data['body'] ?? ''));

                        Notification::make()
                            ->success()
                            ->title((string) trans('support_tickets.filament.notifications.note_added'))
                            ->send();
                    });
                },
            ],
            [
                'name' => 'reply',
                'label' => (string) trans('support_tickets.filament.actions.reply'),
                'icon' => 'heroicon-o-paper-airplane',
                'color' => 'primary',
                'heading' => (string) trans('support_tickets.filament.actions.reply'),
                'description' => (string) trans('support_tickets.mail.reply.footer'),
                'submitLabel' => (string) trans('support_tickets.filament.actions.reply'),
                'form' => [
                    Forms\Components\Textarea::make('body')
                        ->label((string) trans('support_tickets.filament.action_fields.reply_body'))
                        ->required()
                        ->minLength(10)
                        ->maxLength(max(50, (int) config('support_tickets.reply_max_length', 4000))),
                ],
                'visible' => static fn (ContactMessage $record): bool => self::canWork()
                    && SupportReplyMail::replyIsConfigured(),
                'action' => static function (ContactMessage $record, array $data): void {
                    OperatorActionFactory::run(static function () use ($record, $data): void {
                        $operator = self::operator();

                        if (! $operator instanceof User) {
                            throw new RuntimeException('No authenticated operator.');
                        }

                        try {
                            app(SupportTicketService::class)->reply($record, $operator, (string) ($data['body'] ?? ''));
                        } catch (RuntimeException $exception) {
                            if ($exception->getMessage() === 'support_ticket_reply_not_configured') {
                                Notification::make()
                                    ->danger()
                                    ->title((string) trans('support_tickets.filament.notifications.reply_not_configured'))
                                    ->persistent()
                                    ->send();

                                return;
                            }

                            throw $exception;
                        } catch (InvalidArgumentException) {
                            Notification::make()
                                ->danger()
                                ->title((string) trans('support_tickets.filament.notifications.reply_body_required'))
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title((string) trans('support_tickets.filament.notifications.reply_queued'))
                            ->send();
                    });
                },
            ],
        ];
    }

    private static function move(ContactMessage $record, string $target, ?string $note = null): void
    {
        OperatorActionFactory::run(static function () use ($record, $target, $note): void {
            $operator = self::operator();

            if (! $operator instanceof User) {
                throw new RuntimeException('No authenticated operator.');
            }

            $updated = app(SupportTicketService::class)->changeStatus(
                $record,
                $target,
                $operator,
                is_string($note) && trim($note) !== '' ? $note : null,
            );

            Notification::make()
                ->success()
                ->title((string) trans('support_tickets.filament.notifications.status_changed', [
                    'status' => (string) trans(config('support_tickets.status_label_keys.'.$updated->status, 'support_tickets.status.unknown')),
                ]))
                ->send();
        });
    }

    private static function operator(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private static function canWork(): bool
    {
        return AdminAccess::allows(self::operator(), AdminAccess::MANAGE_USERS);
    }
}
