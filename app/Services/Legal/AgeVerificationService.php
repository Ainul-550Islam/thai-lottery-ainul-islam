<?php

declare(strict_types=1);

namespace App\Services\Legal;

use App\Models\AgeVerification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persists a self-declared 18+ eligibility attestation and checks it on money routes.
 *
 * This is an age gate, not identity proof or document-based age assurance. The
 * date of birth stays on the existing users row; this record stores only the
 * attestation decision, policy threshold, timestamp, source, IP, and user agent.
 */
final class AgeVerificationService
{
    public function attestFromRegistration(User $user, Request $request): AgeVerification
    {
        return $this->attest($user, $request, null, 'registration');
    }

    public function attest(User $user, Request $request, ?string $submittedDateOfBirth, string $source = 'account_settings'): AgeVerification
    {
        if (! $request->boolean('age_attestation')) {
            throw ValidationException::withMessages([
                'age_attestation' => 'You must explicitly attest that your date of birth is accurate and you meet the minimum age requirement.',
            ]);
        }

        $minimumAge = $this->minimumAge();
        $storedDate = $user->date_of_birth?->toDateString();
        $submittedDateOfBirth = is_string($submittedDateOfBirth) && trim($submittedDateOfBirth) !== ''
            ? trim($submittedDateOfBirth)
            : null;

        if ($storedDate !== null
            && $submittedDateOfBirth !== null
            && $storedDate !== $submittedDateOfBirth) {
            throw ValidationException::withMessages([
                'date_of_birth' => 'Date of birth is locked after registration. Contact support for a reviewed correction.',
            ]);
        }

        $dateOfBirth = $storedDate ?? $submittedDateOfBirth;

        if ($dateOfBirth === null) {
            throw ValidationException::withMessages([
                'date_of_birth' => 'A date of birth is required before financial actions are available.',
            ]);
        }

        try {
            $birthDate = Carbon::createFromFormat('!Y-m-d', $dateOfBirth, (string) config('app.timezone', 'UTC'));
        } catch (\Throwable) {
            $birthDate = null;
        }

        if (! $birthDate instanceof Carbon
            || $birthDate->format('Y-m-d') !== $dateOfBirth
            || $birthDate->isFuture()
            || $birthDate->age < $minimumAge) {
            throw ValidationException::withMessages([
                'date_of_birth' => sprintf('You must be at least %d years old to use financial services.', $minimumAge),
            ]);
        }

        return DB::transaction(function () use ($user, $request, $dateOfBirth, $storedDate, $minimumAge, $source): AgeVerification {
            if ($storedDate === null) {
                $user->forceFill(['date_of_birth' => $dateOfBirth])->save();
            }

            return AgeVerification::query()->updateOrCreate(
                ['user_id' => (int) $user->getKey()],
                [
                    'status' => 'eligible',
                    'minimum_age' => $minimumAge,
                    'method' => 'self_declaration',
                    'attested_at' => now(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $this->userAgent($request),
                    'source' => $source,
                ],
            );
        }, 3);
    }

    public function isEligible(User $user): bool
    {
        $record = $user->ageVerification;

        if (! $record instanceof AgeVerification || $record->status !== 'eligible') {
            return false;
        }

        $requiredAge = max($this->minimumAge(), (int) $record->minimum_age);
        $actualAge = $user->ageAt(now());

        return $actualAge !== null && $actualAge >= $requiredAge;
    }

    /** @return array<string, mixed> */
    public function status(User $user): array
    {
        $record = $user->ageVerification;

        return [
            'eligible' => $this->isEligible($user),
            'minimum_age' => max($this->minimumAge(), (int) ($record?->minimum_age ?? 0)),
            'method' => $record?->method,
            'status' => $record?->status ?? 'not_attested',
            'attested_at' => $record?->attested_at?->toIso8601String(),
            'date_of_birth_on_file' => $user->date_of_birth !== null,
        ];
    }

    private function minimumAge(): int
    {
        return min(255, max(18, (int) config('legal.minimum_age', 18)));
    }

    private function userAgent(Request $request): ?string
    {
        $value = $request->userAgent();

        return is_string($value) && $value !== '' ? substr($value, 0, 512) : null;
    }
}
