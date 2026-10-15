<?php

declare(strict_types=1);

namespace App\Services\Bot\Contracts;

use App\Services\Bot\DTOs\SignalResult;
use Illuminate\Http\Request;

interface BotSignal
{
    public function score(Request $request): SignalResult;
}
