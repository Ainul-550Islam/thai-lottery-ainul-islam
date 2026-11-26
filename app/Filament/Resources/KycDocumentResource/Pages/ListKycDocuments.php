<?php

declare(strict_types=1);

namespace App\Filament\Resources\KycDocumentResource\Pages;

use App\Enums\KycDocumentVerificationStatus;
use App\Filament\Resources\KycDocumentResource;
use App\Models\KycDocument;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * The evidence queue.
 *
 * Tab order is the work: nothing read yet, claimed but undecided, then the two terminal
 * decisions, then the whole retention history. A tab is a filter — no document moves because it
 * was displayed.
 *
 * There is no create action: a document exists because a player submitted bytes that were
 * fingerprinted, and a hand-typed row would carry no evidence.
 */
class ListKycDocuments extends ListRecords
{
    protected static string $resource = KycDocumentResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $unread = [KycDocumentVerificationStatus::Uploaded->value, KycDocumentVerificationStatus::Processing->value];

        return [
            'awaiting' => Tab::make('Awaiting a decision')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('verification_status', $unread))
                ->badge(fn (): int => KycDocument::query()->whereIn('verification_status', $unread)->count())
                ->badgeColor('warning'),

            'lapsed' => Tab::make('Lapsed evidence')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', now()))
                ->badge(fn (): int => KycDocument::query()->whereNotNull('expires_at')->where('expires_at', '<', now())->count())
                ->badgeColor('danger'),

            'verified' => Tab::make('Verified')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('verification_status', KycDocumentVerificationStatus::Verified->value)),

            'refused' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('verification_status', KycDocumentVerificationStatus::Rejected->value)),

            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return KycDocument::query()
            ->whereIn('verification_status', [
                KycDocumentVerificationStatus::Uploaded->value,
                KycDocumentVerificationStatus::Processing->value,
            ])
            ->exists() ? 'awaiting' : 'all';
    }
}
