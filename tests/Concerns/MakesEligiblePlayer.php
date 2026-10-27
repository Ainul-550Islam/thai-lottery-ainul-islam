<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\AgeVerification;
use App\Models\User;

/**
 * Makes a test user an attested adult who has accepted the current legal
 * documents: the state the money-moving player routes require.
 */
trait MakesEligiblePlayer
{
    use RecordsCurrentLegalConsent;

    protected function makeEligiblePlayer(User $user): User
    {
        $user->forceFill(['date_of_birth' => now()->subYears(30)->toDateString()])->save();

        AgeVerification::query()->create([
            'user_id' => $user->getKey(),
            'status' => 'eligible',
            'minimum_age' => 18,
            'method' => 'self_declaration',
            'attested_at' => now(),
            'source' => 'test',
        ]);

        $user->unsetRelation('ageVerification');
        $this->recordCurrentLegalConsent($user);

        return $user;
    }
}
