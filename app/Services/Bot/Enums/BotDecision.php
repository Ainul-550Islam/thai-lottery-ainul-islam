<?php

declare(strict_types=1);

namespace App\Services\Bot\Enums;

enum BotDecision: string
{
    case Allow = 'allow';
    case Challenge = 'challenge';
    case Block = 'block';
}
