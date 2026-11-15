<?php

declare(strict_types=1);

namespace App\Filament\Resources\LotteryGameResource\Pages;

use App\Filament\Resources\LotteryGameResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The list page carries the whole lane: the table shows the verification state, the
 * checksum drift and the live sellability reason, and the actions are the only two
 * writes the lane has.
 */
final class ListLotteryGames extends ListRecords
{
    protected static string $resource = LotteryGameResource::class;

    protected function getHeaderActions(): array
    {
        // No create action: see LotteryGameResource's class comment.
        return [];
    }
}
