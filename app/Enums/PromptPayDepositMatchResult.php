<?php

declare(strict_types=1);

namespace App\Enums;

enum PromptPayDepositMatchResult: string
{
    case Credited = 'credited';
    case AlreadyCredited = 'already_credited';
    case NeedsReview = 'needs_review';
    case Unmatched = 'unmatched';
}
