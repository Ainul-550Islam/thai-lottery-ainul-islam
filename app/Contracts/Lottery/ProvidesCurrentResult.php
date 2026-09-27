<?php

declare(strict_types=1);

namespace App\Contracts\Lottery;

/**
 * A public result lane that can state its current published result.
 *
 * WHY THIS IS AN INTERFACE AND NOT A BASE CLASS. Three lanes - Weekly, Mega
 * and PCSO - share App\Services\Lottery\Support\AbstractLotteryResultService.
 * The National lane does not, and should not: its 3 Front and 3 After are
 * LISTS of up to four values with their own cardinality rules, which the
 * scalar-field engine cannot express. Forcing it into that base class would
 * be tidiness bought with a wrong model.
 *
 * What all four genuinely have in common is exactly one thing: asked for
 * their current result, they answer with a projection shaped the same way.
 * That is what this interface says, and nothing more - it deliberately does
 * not describe searching, history or importing, because those really do
 * differ between the lanes.
 *
 * The array is the lane's PUBLIC projection: it carries no internal id, no
 * provider credential and no endpoint, because each lane builds it through
 * its own provenance layer before returning it.
 */
interface ProvidesCurrentResult
{
    /**
     * The result the lane's landing page would show right now.
     *
     * @return array<string, mixed>
     */
    public function currentResult(): array;
}
