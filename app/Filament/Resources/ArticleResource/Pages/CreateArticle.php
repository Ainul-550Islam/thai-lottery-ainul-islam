<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Resources\ArticleResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create an article (D5).
 *
 * WHY A NEW ARTICLE ALWAYS STARTS AS A DRAFT
 * There is no "publish" checkbox on this form and no way to create directly
 * into a live state. App\Enums\ArticleStatus gives Draft no transition to
 * Published, so the earliest an article can go live is after someone other
 * than the author has moved it to review and then on to published. That is a
 * property of the workflow, not of this form, so no amount of form tampering
 * can skip it.
 */
class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;
}
