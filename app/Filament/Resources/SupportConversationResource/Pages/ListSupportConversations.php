<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportConversationResource\Pages;

use App\Filament\Resources\SupportConversationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

/**
 * The support inbox list. No create action: conversations are opened by members and
 * by the chat widget, never by an administrator on somebody's behalf.
 */
class ListSupportConversations extends ListRecords
{
    protected static string $resource = SupportConversationResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('openConsole')
                ->label('Open the live operator console')
                ->url('/operator/chat'),
        ];
    }
}
