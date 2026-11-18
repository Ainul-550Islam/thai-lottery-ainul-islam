<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ComplianceAttestationCode;
use App\Filament\Resources\ComplianceAttestationResource\Pages;
use App\Models\ComplianceAttestation;
use App\Services\Compliance\ClaimRefused;
use App\Services\Compliance\MarketingClaimGuard;
use App\Support\Admin\AdminAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin screen for compliance attestations — the evidence behind a public claim.
 *
 * THE SAVE HOOK IS THE POINT OF THIS RESOURCE
 * `public_label` is the only free-text field whose value can reach a player. It is
 * passed through MarketingClaimGuard before it is stored, so an operator cannot type
 * "Licensed by GLO, government approved, 100% secure" into a badge label and publish
 * it. The refusal names the offending phrases and does not save.
 *
 * NOTHING IS EVER HARD-DELETED
 * A revoked attestation keeps its row, its reference and its revocation reason: the
 * evidence that a claim WAS made is part of the compliance record. Revocation is an
 * action, and the badge disappears because the row stopped being valid.
 *
 * THE DOCUMENT IS NOT UPLOADED HERE
 * Only a SHA-256 hash and a reference are stored. A licence PDF does not belong in an
 * application database or in git; the hash is what ties the row to a specific version
 * of the document the operator holds.
 */
final class ComplianceAttestationResource extends Resource
{
    protected static ?string $model = ComplianceAttestation::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_SYSTEM_SETTINGS);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_SYSTEM_SETTINGS);
    }

    public static function canEdit(Model $record): bool
    {
        // A revoked row is frozen: it records what was true, and rewriting it would
        // destroy the audit trail that makes revocation meaningful.
        return AdminAccess::current(AdminAccess::MANAGE_SYSTEM_SETTINGS) && $record->revoked_at === null;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return 'Compliance attestation';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Compliance attestations';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Evidence')
                ->description('A badge is published only while this row is valid: not revoked, started, and not ended. No row means no badge — which is the correct answer when the evidence does not exist.')
                ->schema([
                    Forms\Components\Select::make('code')
                        ->required()
                        ->options(collect(ComplianceAttestationCode::cases())
                            ->mapWithKeys(static fn (ComplianceAttestationCode $c): array => [$c->value => $c->value])
                            ->all()),

                    Forms\Components\TextInput::make('reference')
                        ->required()
                        ->maxLength(190)
                        ->helperText('The licence / certificate / report number. Published with the badge so a reader can verify it.'),

                    Forms\Components\TextInput::make('issuer')
                        ->maxLength(190)
                        ->helperText('The authority or firm that issued it.'),

                    Forms\Components\TextInput::make('document_hash')
                        ->required()
                        ->maxLength(64)
                        ->rule('regex:/^[a-f0-9]{64}$/i')
                        ->helperText('SHA-256 of the evidence file (hex). The file itself is NOT stored in this system.'),

                    Forms\Components\DatePicker::make('valid_from')
                        ->required()
                        ->default(fn () => now()),

                    Forms\Components\DatePicker::make('valid_to')
                        ->helperText('Leave empty only where the code allows open-ended validity. A security audit does not.'),

                    Forms\Components\DateTimePicker::make('verified_at')
                        ->default(fn () => now()),
                ])->columns(2),

            Forms\Components\Section::make('Public label')
                ->description('Scanned on save. A label asserting a credential, an absolute or a guarantee is refused.')
                ->schema([
                    Forms\Components\TextInput::make('public_label')
                        ->maxLength(120)
                        ->helperText('Optional. Left empty, the neutral label for the code is used.'),

                    Forms\Components\TextInput::make('public_url')
                        ->maxLength(500)
                        ->helperText('Optional link to the issuer\'s own register entry.'),
                ]),
        ]);
    }

    /**
     * The guard, wired into creation. A refusal throws ClaimRefused, which Filament
     * reports and which prevents the save.
     */
    public static function assertPublicLabelIsPublishable(?string $label): void
    {
        if ($label === null || trim($label) === '') {
            return;
        }

        $guard = app(MarketingClaimGuard::class);

        try {
            $guard->assertPublishable($label, 'public_label');
        } catch (ClaimRefused $e) {
            Notification::make()
                ->title('Claim refused')
                ->body($e->getMessage())
                ->danger()
                ->send();

            throw $e;
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->badge()->sortable(),
                Tables\Columns\TextColumn::make('reference')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('issuer')->limit(30)->placeholder('—'),
                Tables\Columns\TextColumn::make('valid_from')->date()->sortable(),
                Tables\Columns\TextColumn::make('valid_to')->date()->placeholder('open-ended'),
                Tables\Columns\IconColumn::make('revoked_at')
                    ->label('Revoked')
                    ->boolean()
                    ->getStateUsing(static fn (ComplianceAttestation $record): bool => $record->revoked_at !== null),
                Tables\Columns\TextColumn::make('public_label')->limit(30)->placeholder('—'),
            ])
            ->defaultSort('valid_from', 'desc')
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(static fn (ComplianceAttestation $record): bool => $record->revoked_at === null),
                Tables\Actions\Action::make('revoke')
                    ->requiresConfirmation()
                    ->visible(static fn (ComplianceAttestation $record): bool => $record->revoked_at === null)
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (ComplianceAttestation $record, array $data): void {
                        $record->revoked_at = now();
                        $record->revoked_by_user_id = auth()->id();
                        $record->revoked_reason = (string) ($data['reason'] ?? '');
                        $record->save();

                        Notification::make()
                            ->title('Attestation revoked')
                            ->body('Any badge backed by it has stopped being published.')
                            ->warning()
                            ->send();
                    }),
            ]);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComplianceAttestations::route('/'),
            'create' => Pages\CreateComplianceAttestation::route('/create'),
            'edit' => Pages\EditComplianceAttestation::route('/{record}/edit'),
        ];
    }
}
