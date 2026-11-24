<?php

declare(strict_types=1);

namespace App\Filament\Resources\FraudAlertResource\Pages;

use App\Filament\Resources\FraudAlertResource;
use App\Models\FraudAlert;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * One alert, with its adjudication verbs in the header.
 *
 * The verbs are the table's, re-used rather than re-implemented, so there is exactly one
 * path from a click to FraudAlertReviewService::review().
 */
class ViewFraudAlert extends ViewRecord
{
    protected static string $resource = FraudAlertResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();

        if (! $record instanceof FraudAlert || ! FraudAlertResource::mayAdjudicate($record)) {
            return [];
        }

        return [
            Actions\Action::make('release_allow')
                ->record($record)
                ->label('Release — allow')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->form([\Filament\Forms\Components\Textarea::make('reason')->label('Why is this alert being released?')->required()->minLength(10)->maxLength(255)])
                ->requiresConfirmation()
                ->action(fn (array $data) => FraudAlertResource::review($record, 'RELEASE_ALLOW', (string) $data['reason'])),

            Actions\Action::make('reject_deny')
                ->record($record)
                ->label('Reject — deny')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->form([\Filament\Forms\Components\Textarea::make('reason')->label('What makes this genuinely fraudulent?')->required()->minLength(10)->maxLength(255)])
                ->requiresConfirmation()
                ->action(fn (array $data) => FraudAlertResource::review($record, 'REJECT_DENY', (string) $data['reason'])),
        ];
    }
}
