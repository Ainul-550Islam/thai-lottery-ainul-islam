<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Resources\ArticleResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit an article (D5).
 *
 * WHAT IS DELIBERATELY ABSENT
 * No delete action. An article that has been live has a URL that other sites
 * may link to, and removing the row turns every one of those links into a 404.
 * Archiving withdraws it from the public site while keeping the record, which
 * is the outcome an operator actually wants; a hard delete is not offered
 * because it is almost always the wrong answer and cannot be undone.
 */
class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
