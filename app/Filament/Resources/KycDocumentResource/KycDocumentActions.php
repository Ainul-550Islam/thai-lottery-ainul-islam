<?php

declare(strict_types=1);

namespace App\Filament\Resources\KycDocumentResource;

use App\Enums\KycDocumentVerificationStatus;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Compliance\KycDocumentService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use App\Support\Admin\OperatorActionFactory;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Tables\Actions\Action as TableAction;

/**
 * The three decisions a KYC document can take, declared once and rendered twice.
 *
 * WHICH SERVICE, AND WHY IT IS THE ONE THAT MATTERS
 *
 * These verbs call `App\Services\Compliance\KycDocumentService`, the authoritative per-document
 * lane: it holds the row lock, applies `KycDocumentVerificationStatus::canTransitionTo()`,
 * stamps BOTH the authoritative `verification_status` and the legacy `status` column (so the
 * older gates never go blind), refuses expired evidence by name, and writes an audit line.
 *
 * FOUR-EYES IS NOW THE LANE'S OWN RULE
 *
 * `KycDocumentService::verify()`/`reject()` refuse a reviewer deciding their own evidence
 * (`KycVerificationException::CODE_SELF_REVIEW`). The `visible` closures here and `guard()` still
 * refuse it first, because a button that is guaranteed to fail should not be offered — but the
 * rule no longer depends on the screen being the only caller.
 */
final class KycDocumentActions
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
                'name' => 'verify',
                'label' => 'Verify document',
                'icon' => 'heroicon-o-check-badge',
                'color' => 'success',
                'heading' => 'Verify this document',
                'description' => 'The reference is the stamp: it names the desk check that was performed (8–96 characters, A–Z 0–9 : - . _). The service refuses expired evidence and refuses to verify a document that is already terminal.',
                // Uploaded → Verified is NOT a single enum transition (Uploaded → Processing →
                // Verified), because the service performs the seated act: it claims the document
                // into Processing and verifies it in the same transaction. The enum is consulted
                // for the terminal states, and the service's own accepted set decides the rest —
                // which is why this is written as "the states the service accepts" rather than as
                // one transition.
                'visible' => static fn (KycDocument $record): bool => self::mayApprove()
                    && self::notOwnEvidence($record)
                    && in_array(self::statusOf($record), [
                        KycDocumentVerificationStatus::Uploaded,
                        KycDocumentVerificationStatus::Processing,
                    ], true),
                'form' => [
                    Forms\Components\TextInput::make('verification_reference')
                        ->label('Verification reference')
                        ->helperText('For example: DESK-2026-11-17-0042, or the vendor check id.')
                        ->required()
                        ->minLength(8)
                        ->maxLength(96)
                        ->regex('/^[A-Za-z0-9:\-._]+$/'),
                ],
                'action' => static function (KycDocument $record, array $data): void {
                    if (! self::guard($record)) {
                        return;
                    }

                    $operator = AdminAccess::operator();

                    if (! $operator instanceof User) {
                        return;
                    }

                    OperatorActionFactory::run(
                        static fn () => app(KycDocumentService::class)->verify(
                            $record,
                            (int) $operator->getKey(),
                            strtoupper((string) $data['verification_reference']),
                        ),
                        'Document verified.',
                    );
                },
            ],
            [
                'name' => 'reject',
                'label' => 'Reject document',
                'icon' => 'heroicon-o-x-circle',
                'color' => 'danger',
                'heading' => 'Reject this document',
                'description' => 'Say what is wrong: the reason is stored on the row and shown to the player, so "rejected" without a reason is a support ticket waiting to happen.',
                'visible' => static fn (KycDocument $record): bool => self::mayReview()
                    && self::notOwnEvidence($record)
                    && self::statusOf($record)->canTransitionTo(KycDocumentVerificationStatus::Rejected),
                'form' => [
                    Forms\Components\Textarea::make('reason')
                        ->label('Why is this document refused?')
                        ->required()
                        ->minLength(8)
                        ->maxLength(255),
                ],
                'action' => static function (KycDocument $record, array $data): void {
                    if (! self::guard($record)) {
                        return;
                    }

                    $operator = AdminAccess::operator();

                    if (! $operator instanceof User) {
                        return;
                    }

                    OperatorActionFactory::run(
                        static fn () => app(KycDocumentService::class)->reject(
                            $record,
                            (int) $operator->getKey(),
                            (string) $data['reason'],
                        ),
                        'Document rejected.',
                    );
                },
            ],
            [
                'name' => 'expire',
                'label' => 'Mark expired',
                'icon' => 'heroicon-o-clock',
                'color' => 'warning',
                'heading' => 'Pronounce this evidence lapsed',
                'description' => 'For evidence whose own validity date has passed but whose row still reads as live. Verified evidence can lapse; a rejected document cannot be re-labelled — that decision stands.',
                'confirm' => true,
                'visible' => static fn (KycDocument $record): bool => self::mayReview()
                    && self::statusOf($record)->canTransitionTo(KycDocumentVerificationStatus::Expired),
                'action' => static function (KycDocument $record): void {
                    if (! self::guard($record)) {
                        return;
                    }

                    OperatorActionFactory::run(
                        static fn () => app(KycDocumentService::class)->expire($record),
                        'Evidence pronounced expired.',
                    );
                },
            ],
        ];
    }

    /**
     * The one refusal the service does not make for us.
     *
     * Four-eyes: an operator may never decide their own identity evidence. The service gap is
     * documented in the class docblock; this is where the rule is actually enforced.
     */
    private static function guard(KycDocument $record): bool
    {
        if (self::notOwnEvidence($record)) {
            return true;
        }

        \Filament\Notifications\Notification::make()
            ->danger()
            ->title('Self-review refused')
            ->body('You cannot decide on your own identity evidence. Ask another reviewer.')
            ->persistent()
            ->send();

        return false;
    }

    private static function notOwnEvidence(KycDocument $record): bool
    {
        $operator = AdminAccess::operator();

        if (! $operator instanceof User) {
            return false;
        }

        return (int) $record->user_id !== (int) $operator->getKey();
    }

    private static function statusOf(KycDocument $record): KycDocumentVerificationStatus
    {
        // One reader for "what state is this document in", on the model, so the two lanes and the
        // screen cannot drift apart again (the old `instanceof` branch here could never be false).
        return $record->verificationState() ?? KycDocumentVerificationStatus::Uploaded;
    }

    private static function mayReview(): bool
    {
        return AdminAccess::permits(AdminPermission::KYC_REVIEW)
            || AdminAccess::permits(AdminPermission::KYC_APPROVE);
    }

    private static function mayApprove(): bool
    {
        return AdminAccess::permits(AdminPermission::KYC_APPROVE);
    }
}
