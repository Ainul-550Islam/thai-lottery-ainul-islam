<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Enums\AuditAction;
use App\Enums\PrivacyRequestStatus;
use App\Enums\RiskLevel;
use App\Models\DataSubjectRequest;
use App\Models\DataSubjectRequestEvent;
use App\Models\PrivacyVerificationToken;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The proof that a public request came from the address it names.
 *
 * =========================================================================
 * WHAT A TOKEN IS, AND WHAT IT IS NOT
 * =========================================================================
 * The token is 32 random bytes, handed out exactly once - in the mail - and
 * stored only as a sha256 digest. So:
 *
 *  - a database copy cannot verify somebody else's request,
 *  - a token found in a log is not a credential (the log only ever records the
 *    OUTCOME, never the string),
 *  - a link works once. consume() is a compare-and-set inside a row lock, so two
 *    clicks arriving together resolve to ONE verification and the second sees
 *    "already used" rather than a second state change.
 *
 * =========================================================================
 * THE FOUR OUTCOMES ARE INTERNAL, NOT PUBLIC
 * =========================================================================
 * verified / used / expired / invalid matter to an operator reading a timeline
 * and to the log; the page that renders them prints a different heading for
 * each (a person whose link expired needs to know to submit again) but never
 * reveals an account, an address, a request type or a description. The
 * distinction is about the LINK, never about the person.
 *
 * =========================================================================
 * WHAT IT WILL NOT DO
 * =========================================================================
 * It will not verify a request twice, will not resurrect an expired one, will
 * not shorten or extend the SLA window it sets (that is config), and will not
 * mail anything except the "received, the clock has started" note that closes
 * the verification.
 */
final class PrivacyVerificationService
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Mint a token for a request and hand back the plaintext once.
     *
     * @return array{token: PrivacyVerificationToken, plain: string, expires_at: Carbon}
     */
    public function issue(DataSubjectRequest $request, ?Carbon $expiresAt = null): array
    {
        $expiry = $expiresAt ?? Carbon::now()->addMinutes($this->ttlMinutes());

        $issued = PrivacyVerificationToken::issue((int) $request->getKey(), $expiry);

        return [
            'token' => $issued['token'],
            'plain' => $issued['plain'],
            'expires_at' => $expiry,
        ];
    }

    public function ttlMinutes(): int
    {
        return max(5, (int) config('privacy_requests.verification_ttl_minutes', 1440));
    }

    /**
     * Consume a link.
     *
     * @return array{state: string, reference: string|null}
     */
    public function verify(string $plainToken): array
    {
        $requests = app(PrivacyRequestService::class);

        $token = PrivacyVerificationToken::findByPlainToken($plainToken);

        if (! $token instanceof PrivacyVerificationToken) {
            return ['state' => PrivacyRequestService::VERIFY_INVALID, 'reference' => null];
        }

        // Belt and braces after the digest lookup: the comparison is constant
        // time, so the endpoint's timing says nothing about how close a guess was.
        if (! $token->matches($plainToken)) {
            return ['state' => PrivacyRequestService::VERIFY_INVALID, 'reference' => null];
        }

        $request = $token->request;

        if (! $request instanceof DataSubjectRequest) {
            return ['state' => PrivacyRequestService::VERIFY_INVALID, 'reference' => null];
        }

        if ($token->isConsumed() || $request->status !== PrivacyRequestStatus::PendingVerification) {
            return ['state' => PrivacyRequestService::VERIFY_USED, 'reference' => $request->reference];
        }

        if ($token->isExpired()) {
            $requests->expireUnverified($request, 'verification_window_closed');

            return ['state' => PrivacyRequestService::VERIFY_EXPIRED, 'reference' => $request->reference];
        }

        $verified = DB::transaction(function () use ($token, $request, $requests): ?DataSubjectRequest {
            /** @var PrivacyVerificationToken $lockedToken */
            $lockedToken = PrivacyVerificationToken::query()->lockForUpdate()->find($token->getKey());
            /** @var DataSubjectRequest $lockedRequest */
            $lockedRequest = DataSubjectRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if (! $lockedToken->consume()) {
                return null;
            }

            if ($lockedRequest->status !== PrivacyRequestStatus::PendingVerification) {
                return null;
            }

            $receivedAt = Carbon::now();

            $lockedRequest->status = PrivacyRequestStatus::Received;
            $lockedRequest->verified_at = $receivedAt;
            $lockedRequest->received_at = $receivedAt;
            $lockedRequest->due_at = $receivedAt->copy()->addDays($requests->slaDays());
            $lockedRequest->save();

            DataSubjectRequestEvent::query()->create([
                'request_id' => (int) $lockedRequest->getKey(),
                'actor_type' => DataSubjectRequestEvent::ACTOR_REQUESTER,
                'actor_id' => null,
                'event' => DataSubjectRequestEvent::EVENT_VERIFIED,
                'from_status' => PrivacyRequestStatus::PendingVerification->value,
                'to_status' => PrivacyRequestStatus::Received->value,
                'created_at' => $receivedAt,
            ]);

            return $lockedRequest;
        }, 3);

        if (! $verified instanceof DataSubjectRequest) {
            return ['state' => PrivacyRequestService::VERIFY_USED, 'reference' => $request->reference];
        }

        $this->audit->log(
            userId: $verified->user_id,
            action: AuditAction::Update,
            riskLevel: RiskLevel::Low,
            auditable: $verified,
            description: 'A privacy request was verified by its requester.',
            metadata: [
                'reference' => $verified->reference,
                'type' => $verified->type->value,
                'due_at' => $verified->due_at?->toIso8601String(),
            ],
        );

        $requests->mailReceived($verified);

        Log::info('privacy_request_verified', [
            'reference' => $verified->reference,
            'type' => $verified->type->value,
        ]);

        return ['state' => PrivacyRequestService::VERIFIED, 'reference' => $verified->reference];
    }

    /**
     * Retire tokens that can no longer do anything.
     *
     * A consumed token is a spent credential; an expired one is a dead one. Both
     * are a digest of a secret somebody once held, so neither should outlive its
     * usefulness.
     */
    public function retireSpent(): int
    {
        return PrivacyVerificationToken::query()
            ->where(function ($query): void {
                $query->whereNotNull('consumed_at')
                    ->orWhere('expires_at', '<', Carbon::now());
            })
            ->delete();
    }
}
