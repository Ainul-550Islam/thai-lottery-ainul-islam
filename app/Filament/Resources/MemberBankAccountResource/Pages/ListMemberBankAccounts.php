<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberBankAccountResource\Pages;

use App\Filament\Resources\MemberBankAccountResource;
use Filament\Resources\Pages\ListRecords;

final class ListMemberBankAccounts extends ListRecords
{
    protected static string $resource = MemberBankAccountResource::class;
}
