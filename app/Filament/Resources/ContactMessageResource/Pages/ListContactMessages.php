<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactMessageResource\Pages;

use App\Filament\Resources\ContactMessageResource;
use App\Models\ContactMessage;
use Filament\Actions\Action;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * The inbox.
 *
 * TAB ORDER IS THE WORK QUEUE: unworked messages first (they are the only ones
 * a stranger is waiting on), then the ones being worked, then the closed states
 * - including SPAM, which stays visible precisely because "hidden as spam" is a
 * decision somebody must be able to review later.
 *
 * No create action: a ticket exists because a visitor sent one.
 */
final class ListContactMessages extends ListRecords
{
    protected static string $resource = ContactMessageResource::class;

    /**
     * @return array<int, Action>
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
        return [
            'new' => Tab::make((string) trans('support_tickets.status.received'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ContactMessage::STATUS_RECEIVED))
                ->badge(fn (): int => ContactMessage::query()->where('status', ContactMessage::STATUS_RECEIVED)->count())
                ->badgeColor('warning'),

            'working' => Tab::make((string) trans('support_tickets.status.in_review'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ContactMessage::STATUS_IN_REVIEW)),

            'answered' => Tab::make((string) trans('support_tickets.status.resolved'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ContactMessage::STATUS_RESOLVED)),

            'closed' => Tab::make((string) trans('support_tickets.status.closed'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    ContactMessage::STATUS_SPAM,
                    ContactMessage::STATUS_ARCHIVED,
                ])),

            'all' => Tab::make('All'),
        ];
    }
}
