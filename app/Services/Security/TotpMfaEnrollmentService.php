<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\DTOs\Security\TotpActionProofData;
use App\Models\TotpActionProof;
use App\Models\TotpCredential;
use App\Models\TotpPendingEnrollment;
use App\Models\TotpRecoveryCode;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

final class TotpMfaEnrollmentService
{
    private const ENROLLMENT_TTL_SECONDS = 900;

    private const ACTION_PROOF_TTL_SECONDS = 300;

    private const MAX_CONFIRMATION_ATTEMPTS = 5;

    private const RECOVERY_CODE_COUNT = 8;

    private readonly TotpCodeVerifier $verifier;

    public function __construct(
        ?TotpCodeVerifier $verifier = null,
        private readonly ?TotpActionProofInvalidationService $invalidation = null,
    ) {
        $this->verifier = $verifier ?? new TotpCodeVerifier(new class implements ClockInterface
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable;
            }
        });
    }

    /**
     * @return array{enabled: bool, confirmed_at_epoch: int|null, pending_enrollment: bool, remaining_recovery_codes: int}
     */
    public function status(User $user): array
    {
        $userId = (int) $user->getKey();
        $credential = TotpCredential::query()->where('user_id', $userId)->first();
        $nowEpoch = now()->getTimestamp();

        $pending = TotpPendingEnrollment::query()
            ->where('user_id', $userId)
            ->where('expires_at_epoch', '>', $nowEpoch)
            ->exists();

        $remainingCodes = 0;
        if ($credential instanceof TotpCredential) {
            $remainingCodes = TotpRecoveryCode::query()
                ->where('totp_credential_id', $credential->getKey())
                ->whereNull('consumed_at_epoch')
                ->count();
        }

        return [
            'enabled' => $credential instanceof TotpCredential,
            'confirmed_at_epoch' => $credential instanceof TotpCredential ? (int) $credential->confirmed_at_epoch : null,
            'pending_enrollment' => $pending,
            'remaining_recovery_codes' => $remainingCodes,
        ];
    }

    /**
     * @return array{reference: string, secret: string, otpauth_uri: string, expires_at_epoch: int}
     */
    public function startEnrollment(User $user): array
    {
        $userId = (int) $user->getKey();

        if (TotpCredential::query()->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages([
                'mfa' => ['TOTP multi-factor authentication is already enabled for this account.'],
            ]);
        }

        $secret = $this->verifier->generateSecret();
        $reference = bin2hex(random_bytes(16));
        $issuedAt = now()->getTimestamp();
        $expiresAt = $issuedAt + self::ENROLLMENT_TTL_SECONDS;

        DB::transaction(function () use ($userId, $reference, $secret, $issuedAt, $expiresAt): void {
            TotpPendingEnrollment::query()->where('user_id', $userId)->delete();

            $pending = new TotpPendingEnrollment;
            $pending->forceFill([
                'user_id' => $userId,
                'reference' => $reference,
                'secret' => $secret,
                'issued_at_epoch' => $issuedAt,
                'expires_at_epoch' => $expiresAt,
                'confirmation_attempts' => 0,
            ])->save();
        });

        $issuer = rawurlencode((string) config('app.name', 'ThaiLotteryEnterprise'));
        $account = rawurlencode((string) ($user->email ?: $user->username ?: ('user-'.$userId)));
        $uri = "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";

        return [
            'reference' => $reference,
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'expires_at_epoch' => $expiresAt,
        ];
    }

    /**
     * @return array{enabled: bool, confirmed_at_epoch: int, recovery_codes: list<string>}
     */
    public function confirmEnrollment(User $user, string $code): array
    {
        $userId = (int) $user->getKey();
        $nowEpoch = now()->getTimestamp();

        return DB::transaction(function () use ($user, $userId, $code, $nowEpoch): array {
            /** @var TotpPendingEnrollment|null $pending */
            $pending = TotpPendingEnrollment::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if (! $pending instanceof TotpPendingEnrollment || (int) $pending->expires_at_epoch <= $nowEpoch) {
                if ($pending instanceof TotpPendingEnrollment) {
                    $pending->delete();
                }

                throw ValidationException::withMessages([
                    'code' => ['No active TOTP enrollment found or enrollment has expired.'],
                ]);
            }

            if ((int) $pending->confirmation_attempts >= self::MAX_CONFIRMATION_ATTEMPTS) {
                $pending->delete();

                throw ValidationException::withMessages([
                    'code' => ['Maximum confirmation attempts exceeded. Start a new enrollment.'],
                ]);
            }

            $matchedStep = $this->verifier->matchingTimeStep((string) $pending->secret, $code);
            if ($matchedStep === null) {
                $pending->forceFill([
                    'confirmation_attempts' => (int) $pending->confirmation_attempts + 1,
                ])->save();

                throw ValidationException::withMessages([
                    'code' => ['Invalid TOTP verification code.'],
                ]);
            }

            $credential = new TotpCredential;
            $credential->forceFill([
                'user_id' => $userId,
                'enrollment_reference' => (string) $pending->reference,
                'secret' => (string) $pending->secret,
                'confirmed_at_epoch' => $nowEpoch,
                'last_accepted_step' => $matchedStep,
            ])->save();

            $pending->delete();

            $recoveryCodes = $this->issueRecoveryCodes($credential, $nowEpoch);

            $user->forceFill([
                'two_factor_enabled' => true,
                'two_factor_secret' => (string) $credential->secret,
            ])->save();

            return [
                'enabled' => true,
                'confirmed_at_epoch' => $nowEpoch,
                'recovery_codes' => $recoveryCodes,
            ];
        });
    }

    /**
     * @return array{verified: bool, action: string, expires_at_epoch: int, proof: TotpActionProofData}
     */
    public function verifyTotp(User $user, string $code, string $sessionId, string $action = 'login_mfa'): array
    {
        $userId = (int) $user->getKey();
        $nowEpoch = now()->getTimestamp();

        return DB::transaction(function () use ($userId, $code, $sessionId, $action, $nowEpoch): array {
            /** @var TotpCredential|null $credential */
            $credential = TotpCredential::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if (! $credential instanceof TotpCredential) {
                throw ValidationException::withMessages([
                    'code' => ['TOTP multi-factor authentication is not enabled for this account.'],
                ]);
            }

            $matchedStep = $this->verifier->matchingTimeStep((string) $credential->secret, $code);
            if ($matchedStep === null || $matchedStep <= (int) $credential->last_accepted_step) {
                throw ValidationException::withMessages([
                    'code' => ['Invalid or already-used TOTP verification code.'],
                ]);
            }

            $credential->forceFill([
                'last_accepted_step' => $matchedStep,
            ])->save();

            $proof = $this->mintActionProof($credential, $sessionId, $action, $nowEpoch);

            return [
                'verified' => true,
                'action' => $action,
                'expires_at_epoch' => $proof->expiresAtEpoch,
                'proof' => $proof,
            ];
        });
    }

    /**
     * @return array{verified: bool, action: string, expires_at_epoch: int, remaining_recovery_codes: int, proof: TotpActionProofData}
     */
    public function verifyRecoveryCode(User $user, string $recoveryCode, string $sessionId, string $action = 'login_mfa'): array
    {
        $userId = (int) $user->getKey();
        $nowEpoch = now()->getTimestamp();
        $normalizedCode = strtoupper(trim($recoveryCode));
        $codeHash = hash('sha256', $normalizedCode);

        return DB::transaction(function () use ($userId, $codeHash, $sessionId, $action, $nowEpoch): array {
            /** @var TotpCredential|null $credential */
            $credential = TotpCredential::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if (! $credential instanceof TotpCredential) {
                throw ValidationException::withMessages([
                    'recovery_code' => ['TOTP multi-factor authentication is not enabled for this account.'],
                ]);
            }

            /** @var TotpRecoveryCode|null $record */
            $record = TotpRecoveryCode::query()
                ->where('totp_credential_id', $credential->getKey())
                ->where('code_hash', $codeHash)
                ->whereNull('consumed_at_epoch')
                ->lockForUpdate()
                ->first();

            if (! $record instanceof TotpRecoveryCode) {
                throw ValidationException::withMessages([
                    'recovery_code' => ['Invalid or already consumed recovery code.'],
                ]);
            }

            $record->forceFill([
                'consumed_at_epoch' => $nowEpoch,
            ])->save();

            $remaining = TotpRecoveryCode::query()
                ->where('totp_credential_id', $credential->getKey())
                ->whereNull('consumed_at_epoch')
                ->count();

            $proof = $this->mintActionProof($credential, $sessionId, $action, $nowEpoch);

            return [
                'verified' => true,
                'action' => $action,
                'expires_at_epoch' => $proof->expiresAtEpoch,
                'remaining_recovery_codes' => $remaining,
                'proof' => $proof,
            ];
        });
    }

    public function disable(User $user, string $code, string $sessionId): void
    {
        $userId = (int) $user->getKey();

        DB::transaction(function () use ($user, $userId, $code): void {
            /** @var TotpCredential|null $credential */
            $credential = TotpCredential::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if (! $credential instanceof TotpCredential) {
                throw ValidationException::withMessages([
                    'code' => ['TOTP multi-factor authentication is not enabled for this account.'],
                ]);
            }

            $matchedStep = $this->verifier->matchingTimeStep((string) $credential->secret, $code);
            if ($matchedStep === null || $matchedStep <= (int) $credential->last_accepted_step) {
                throw ValidationException::withMessages([
                    'code' => ['Invalid or already-used TOTP verification code.'],
                ]);
            }

            TotpActionProof::query()->where('totp_credential_id', $credential->getKey())->delete();
            TotpRecoveryCode::query()->where('totp_credential_id', $credential->getKey())->delete();
            $credential->delete();

            $user->forceFill([
                'two_factor_enabled' => false,
                'two_factor_secret' => null,
            ])->save();

            ($this->invalidation ?? app(TotpActionProofInvalidationService::class))
                ->invalidate($user, TotpActionProofInvalidationService::TOTP_CHANGED);
        });
    }

    /**
     * @return list<string>
     */
    private function issueRecoveryCodes(TotpCredential $credential, int $issuedAt): array
    {
        $batchRef = bin2hex(random_bytes(16));
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(4)));
            $codes[] = $raw;

            $row = new TotpRecoveryCode;
            $row->forceFill([
                'totp_credential_id' => $credential->getKey(),
                'batch_reference' => $batchRef,
                'code_hash' => hash('sha256', $raw),
                'issued_at_epoch' => $issuedAt,
                'consumed_at_epoch' => null,
            ])->save();
        }

        return $codes;
    }

    private function mintActionProof(TotpCredential $credential, string $sessionId, string $action, int $nowEpoch): TotpActionProofData
    {
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = $nowEpoch + self::ACTION_PROOF_TTL_SECONDS;

        $proofRow = new TotpActionProof;
        $proofRow->forceFill([
            'totp_credential_id' => $credential->getKey(),
            'token_hash' => hash('sha256', $rawToken),
            'session_hash' => hash('sha256', $sessionId),
            'credential_binding' => hash('sha256', $credential->getKey().':'.$credential->enrollment_reference),
            'action' => $action,
            'issued_at_epoch' => $nowEpoch,
            'expires_at_epoch' => $expiresAt,
            'consumed_at_epoch' => null,
        ])->save();

        return new TotpActionProofData($action, $expiresAt, $rawToken);
    }
}
