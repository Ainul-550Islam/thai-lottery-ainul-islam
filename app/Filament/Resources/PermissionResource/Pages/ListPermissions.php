<?php

declare(strict_types=1);

namespace App\Filament\Resources\PermissionResource\Pages;

use App\Filament\Resources\PermissionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The catalogue index.
 *
 * No header actions: the resource is a reference, and its write path is a config
 * change plus a seeder run — not a button an operator can press at 2am.
 */
class ListPermissions extends ListRecords
{
    protected static string $resource = PermissionResource::class;
}
