<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Resources\ArticleResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

/**
 * The article browse screen (D5).
 *
 * A create button IS offered here, unlike the ticket list: articles are
 * authored, and the review workflow is what controls whether they reach the
 * public — not the absence of a way to write one.
 */
class ListArticles extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('New article')
                ->url(static::getResource()::getUrl('create'))
                ->icon('heroicon-o-plus'),
        ];
    }
}
