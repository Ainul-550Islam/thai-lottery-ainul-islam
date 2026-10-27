<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use App\DTOs\Kyc\KycStatusResult;
use App\DTOs\Kyc\KycSubmissionResult;
use App\Exceptions\KycProviderException;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Contract for an identity-verification provider.
 *
 * THREE METHODS, AND WHY EACH ONE EXISTS
 *
 *   submit()   hand a member's submission to the provider. Returns a typed
 *              result; never a bare boolean, because "nothing was submitted
 *              because no provider is wired" and "the provider accepted it"
 *              must be distinguishable everywhere.
 *
 *   status()   ask what the member's current state is. Returns the platform's
 *              own vocabulary, so a page can render it without a translation
 *              table for a third party.
 *
 *   verifyWebhook()  prove that an inbound callback really came from the
 *              provider before anything acts on it. It must return false rather
 *              than throw when the signature is merely wrong — a wrong
 *              signature is an expected hostile input, not an outage — and it
 *              must throw only when it cannot decide at all.
 *
 * WHAT IMPLEMENTATIONS MUST NOT DO
 *  - They must not write KycVerification rows themselves. The decision ledger is
 *    owned by the existing Verification/Compliance services; a provider adapter
 *    is an intake, not a judge.
 *  - They must not log the member's document numbers, images, or the provider's
 *    raw payload. Signature comparisons happen on hashes and the payload is
 *    summarised into scalars.
 *  - They must not treat an unset credential as "disabled and therefore fine".
 *    A provider with no secret cannot verify a webhook, so it refuses.
 */
interface KycProviderInterface
{
    /**
     * Stable driver name; must match the key used in config('account.verification.providers').
     */
    public function name(): string;

    /**
     * Whether this provider can be used at all right now (credentials present).
     */
    public function isConfigured(): bool;

    /**
     * Hand a submission over.
     *
     * @param  array<string, mixed>  $payload  already validated by the caller; never raw request input
     *
     * @throws KycProviderException when the provider is unconfigured or unreachable
     */
    public function submit(User $user, array $payload): KycSubmissionResult;

    /**
     * Current state for a member, in the platform vocabulary.
     *
     * @throws KycProviderException when the provider cannot answer
     */
    public function status(User $user): KycStatusResult;

    /**
     * Prove an inbound callback came from this provider.
     *
     * @return bool true only when the signature is valid AND fresh
     */
    public function verifyWebhook(Request $request): bool;
}
