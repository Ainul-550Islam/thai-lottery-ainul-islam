<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use App\DTOs\Kyc\KycStatusResult;
use App\DTOs\Kyc\KycSubmissionResult;
use App\Exceptions\KycProviderException;
use App\Models\AccountVerification;
use App\Models\User;
use App\Services\Verification\AccountVerificationService;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * The default identity provider: a person reads the documents and decides.
 *
 * THIS IS A REAL PROVIDER, NOT A PLACEHOLDER. It is the correct provider for an
 * operator who has not signed with an e-KYC vendor, and it is the one that is
 * always available. Choosing it is a decision with consequences a member can
 * read: documents go to a human queue, nothing is decided automatically, and the
 * member is told so.
 *
 * IT COMPOSES THE EXISTING AGGREGATE. `AccountVerificationService` already owns
 * the submission transaction, the private document storage, the fingerprint and
 * the status vocabulary. This class does not re-implement any of that: it
 * validates that the caller passed a real submission, delegates, and maps the
 * outcome into a typed result. There is exactly one identity state machine in
 * this application and this class is not a second one.
 *
 * NO WEBHOOKS. A manual queue produces no callbacks, so `verifyWebhook()` returns
 * false for every request. Returning false rather than throwing is deliberate:
 * the endpoint that receives the callback answers 404 identically to an unknown
 * route, which tells a prober nothing.
 */
final class ManualReviewKycProvider implements KycProviderInterface
{
    public const DRIVER = 'manual_review';

    public function __construct(
        private readonly AccountVerificationService $verification,
    ) {}

    public function name(): string
    {
        return self::DRIVER;
    }

    /**
     * A manual review queue needs no credentials, so it is always configured.
     * The document storage disk is the only prerequisite, and a missing disk is
     * caught by the submission itself rather than pretended away here.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function submit(User $user, array $payload): KycSubmissionResult
    {
        try {
            $result = $this->verification->submit(
                $user,
                $payload,
                isset($payload['ip_address']) && is_string($payload['ip_address']) ? $payload['ip_address'] : null,
            );
        } catch (InvalidArgumentException $e) {
            // A policy refusal at intake (duplicate open request, bad document
            // type, missing back page). Not an outage: the caller must not retry
            // the same payload.
            return KycSubmissionResult::refused(self::DRIVER, 'intake_refused');
        } catch (Throwable $e) {
            throw KycProviderException::unavailable(self::DRIVER, 'submission_failed', [
                'exception' => $e::class,
            ]);
        }

        $reference = isset($result['reference']) && is_string($result['reference']) ? $result['reference'] : '';

        if ($reference === '') {
            throw KycProviderException::unavailable(self::DRIVER, 'submission_returned_no_reference');
        }

        return KycSubmissionResult::accepted(self::DRIVER, $reference, [
            'state' => isset($result['status']) && is_string($result['status']) ? $result['status'] : 'PENDING',
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function status(User $user): KycStatusResult
    {
        $publicStatus = $this->verification->publicStatus($user);

        $latest = AccountVerification::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('id')
            ->first();

        return KycStatusResult::make(
            provider: self::DRIVER,
            state: $publicStatus,
            reference: $latest?->verification_reference,
            decidedAt: $latest?->processed_at?->toIso8601String(),
            expiresAt: null,
            reasonCode: $latest?->review_reason === null ? null : 'review_recorded',
        );
    }

    /**
     * {@inheritDoc}
     *
     * A manual queue has no callback channel. Every inbound "webhook" is a
     * forgery by definition, so it is refused rather than verified.
     */
    public function verifyWebhook(Request $request): bool
    {
        return false;
    }
}
