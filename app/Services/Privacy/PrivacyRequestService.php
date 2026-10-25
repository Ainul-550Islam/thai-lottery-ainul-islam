<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Enums\AuditAction;
use App\Enums\PrivacyRequestStatus;
use App\Enums\PrivacyRequestType;
use App\Enums\RiskLevel;
use App\Exceptions\Privacy\PrivacyRequestException;
use App\Mail\PersonalDataExportReadyMail;
use App\Mail\PrivacyRequestReceivedMail;
use App\Mail\PrivacyRequestUpdateMail;
use App\Mail\PrivacyRequestVerificationMail;
use App\Models\DataSubjectRequest;
use App\Models\DataSubjectRequestEvent;
use App\Models\PersonalDataExport;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The data-subject request workflow.
 *
 * =========================================================================
 * THE THREE RULES THIS CLASS EXISTS TO KEEP
 * =========================================================================
 * 1. NO ENUMERATION. submitPublic() returns the SAME answer for an address
 *    with an account, an address without one, an address already holding the
 *    maximum number of open requests, and a submission the honeypot caught.
 *    The caller - an HTTP controller - has nothing to branch on, so it cannot
 *    leak the difference even by accident. (A rate ceiling is the one exception,
 *    and it is keyed on the connection, not on the address.)
 *
 * 2. NOTHING PROGRESSES UNVERIFIED. A public request starts in
 *    pending_verification and has no due date. Only the single-use link sent
 *    to the address it names can move it to received and start the clock.
 *    Staff cannot shortcut that: PendingVerification's only legal successors
 *    are received (requester), expired (clock) and cancelled. An authenticated
 *    submission needs no mail because the session IS the proof of identity.
 *
 * 3. EVERY MOVE IS RECORDED. transition() takes a row lock, checks the
 *    lifecycle table, writes an append-only event, writes an audit row and only
 *    then queues the requester's mail. A refused transition writes nothing at
 *    all - no event, no audit, no status.
 *
 * =========================================================================
 * WHAT THIS CLASS DOES NOT DO
 * =========================================================================
 * It never deletes anything. Erasure and restriction are REVIEWED decisions:
 * transition() records the decision, PrivacyRetentionGuard states what must be
 * kept, and no code path here destroys a financial or compliance record. It
 * also never resolves an account for a public request at intake time - that
 * happens in PersonalDataExporter, after verification, because doing it earlier
 * would turn "we sent you a mail" into an account-existence oracle.
 */
final class PrivacyRequestService
{
    /** Neutral answer for every public submission outcome. */
    public const OUTCOME_ACCEPTED = 'ACCEPTED';

    public const VERIFIED = 'verified';

    public const VERIFY_USED = 'used';

    public const VERIFY_EXPIRED = 'expired';

    /**
     * The only lawful reason to refuse an erasure or a restriction.
     */
    public const RETENTION_REASON_CODE = 'retention_obligation';

    /**
     * How much an operator has to write before completing a request the
     * retention guard says must be blocked. Long enough that "ok" is not a
     * justification, short enough not to invite padding.
     */
    public const RETENTION_OVERRIDE_MIN_LENGTH = 20;

    public const VERIFY_INVALID = 'invalid';

    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('privacy_requests.enabled', false);
    }

    /**
     * Accept a public submission.
     *
     * ALWAYS returns the same shape. Nothing about the address, an existing
     * account or an existing request reaches the return value; an over-limit or
     * honeypot-tripped submission is simply not written.
     *
     * @return array{state: string, reference: string|null}
     */
    public function submitPublic(
        string $email,
        PrivacyRequestType $type,
        string $locale,
        ?string $details,
        ?string $ip = null,
        bool $honeypotTripped = false,
    ): array {
        if (! $this->enabled()) {
            throw PrivacyRequestException::disabled();
        }

        $this->assertAcceptedType($type);

        $normalised = DataSubjectRequest::normaliseEmail($email);
        $emailHash = DataSubjectRequest::hashEmail($normalised);
        $ipHash = DataSubjectRequest::hashIp($ip);

        // The honeypot is answered exactly like a success, and nothing is
        // written: a bot must not be able to tell that it was caught, and a
        // caught submission must not consume a real address's allowance.
        if ($honeypotTripped) {
            return ['state' => self::OUTCOME_ACCEPTED, 'reference' => null];
        }

        if (! $this->withinRateLimits($emailHash, $ipHash)) {
            // Same answer: the ceiling is on the connection, and telling an
            // anonymous caller "you are rate limited for that address" would
            // confirm the address matters.
            return ['state' => self::OUTCOME_ACCEPTED, 'reference' => null];
        }

        if ($this->openCount($emailHash) >= $this->maxOpenPerEmail()) {
            return ['state' => self::OUTCOME_ACCEPTED, 'reference' => null];
        }

        try {
            [$request, $plainToken] = $this->createRequest(
                emailHash: $emailHash,
                email: $normalised,
                type: $type,
                locale: $locale,
                details: $details,
                ipHash: $ipHash,
                userId: null,
                status: PrivacyRequestStatus::PendingVerification,
            );
        } catch (Throwable $exception) {
            // A storage failure must not become an oracle either. The caller
            // gets the neutral answer; the operator gets the log line.
            Log::critical('privacy_request_submit_failed', [
                'exception' => $exception::class,
            ]);

            return ['state' => self::OUTCOME_ACCEPTED, 'reference' => null];
        }

        $this->recordRateLimit($emailHash, $ipHash);

        Mail::to($normalised)->queue(new PrivacyRequestVerificationMail($request, $plainToken));

        Log::info('privacy_request_submitted', [
            'reference' => $request->reference,
            'type' => $type->value,
            'locale' => $locale,
        ]);

        return ['state' => self::OUTCOME_ACCEPTED, 'reference' => $request->reference];
    }

    /**
     * Accept a submission from a signed-in account.
     *
     * The session proves the address, so no verification mail is needed and the
     * clock starts immediately. The address used is the ACCOUNT's, never one a
     * client supplied - a player cannot open a request in somebody else's name.
     *
     * @return array{state: string, reference: string, request: DataSubjectRequest}
     */
    public function submitAuthenticated(
        User $user,
        PrivacyRequestType $type,
        ?string $details,
        ?string $ip = null,
    ): array {
        if (! $this->enabled()) {
            throw PrivacyRequestException::disabled();
        }

        $this->assertAcceptedType($type);

        $email = (string) $user->email;
        $emailHash = DataSubjectRequest::hashEmail($email);
        $ipHash = DataSubjectRequest::hashIp($ip);

        if ($this->openCount($emailHash) >= $this->maxOpenPerEmail()) {
            throw PrivacyRequestException::tooManyOpen();
        }

        [$request] = $this->createRequest(
            emailHash: $emailHash,
            email: DataSubjectRequest::normaliseEmail($email),
            type: $type,
            locale: (string) app()->getLocale(),
            details: $details,
            ipHash: $ipHash,
            userId: (int) $user->getKey(),
            status: PrivacyRequestStatus::Received,
            verifiedImmediately: true,
        );

        $this->mailRequester($request, new PrivacyRequestReceivedMail($request));

        return [
            'state' => self::OUTCOME_ACCEPTED,
            'reference' => $request->reference,
            'request' => $request,
        ];
    }

    /**
     * Consume a verification token.
     *
     * The token mechanics live in PrivacyVerificationService; this method is the
     * workflow's entry point so callers do not need to know that. The four
     * outcomes are distinct internally (an operator needs "expired" and
     * "forged" in the log) but the PAGE that renders them reveals nothing about
     * accounts: every branch prints the same kind of message and, at most, the
     * reference the token names.
     *
     * @return array{state: string, reference: string|null}
     */
    public function verify(string $plainToken): array
    {
        return app(\App\Services\Privacy\PrivacyVerificationService::class)->verify($plainToken);
    }

    /**
     * The requester withdraws an open request.
     */
    public function cancel(
        DataSubjectRequest $request,
        string $actorType = DataSubjectRequestEvent::ACTOR_REQUESTER,
        ?int $actorId = null,
    ): DataSubjectRequest {
        if ($request->status->isTerminal()) {
            throw PrivacyRequestException::notCancellable($request->status->value);
        }

        return $this->transition(
            $request,
            PrivacyRequestStatus::Cancelled,
            $actorType,
            $actorId,
            null,
            false,
        );
    }

    /**
     * Move a request to a new state, or refuse and write nothing.
     *
     * The order inside the transaction matters: lock the row, re-read the
     * state, check the lifecycle, write the row, write the event. Only after the
     * transaction commits does the requester's mail get queued - so a mail can
     * never announce a state the database refused.
     */
    public function transition(
        DataSubjectRequest $request,
        PrivacyRequestStatus $target,
        string $actorType = DataSubjectRequestEvent::ACTOR_SYSTEM,
        ?int $actorId = null,
        ?string $note = null,
        bool $notifyRequester = true,
        ?string $reasonCode = null,
        ?string $retentionOverrideReason = null,
    ): DataSubjectRequest {
        // THE RETENTION GATE. Checked before anything is written, so a blocked
        // request keeps its state, its timeline and its deadline. It is checked
        // for a COMPLETION of an erasure or restriction - the two request types
        // whose fulfilment would destroy or wall off records the platform is
        // obliged to keep - and for their rejection, which has to cite that
        // obligation rather than invent a different excuse.
        $override = $this->assertRetentionAllows($request, $target, $reasonCode, $retentionOverrideReason);

        $this->assertIdentityVerified($request, $target);

        $updated = DB::transaction(function () use ($request, $target, $actorType, $actorId, $note, $reasonCode, $override): DataSubjectRequest {
            /** @var DataSubjectRequest $locked */
            $locked = DataSubjectRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if (! $locked->status->canTransitionTo($target)) {
                throw PrivacyRequestException::invalidTransition($locked->status->value, $target->value);
            }

            $from = $locked->status;

            $locked->status = $target;

            if ($target === PrivacyRequestStatus::Completed) {
                $locked->completed_at = Carbon::now();
            }

            if ($target === PrivacyRequestStatus::Rejected && $reasonCode !== null) {
                $locked->rejected_reason_code = $reasonCode;
            }

            $locked->save();

            DataSubjectRequestEvent::query()->create([
                'request_id' => (int) $locked->getKey(),
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'event' => DataSubjectRequestEvent::EVENT_STATUS_CHANGE,
                'from_status' => $from->value,
                'to_status' => $target->value,
                'note_encrypted' => $note,
                'created_at' => Carbon::now(),
            ]);

            if ($override !== null) {
                // The override is an EVENT, not a footnote: a decision to
                // complete an erasure against a retention report is exactly the
                // kind of thing an auditor reads the timeline for. It is not
                // mailed to the requester - it is an internal justification.
                DataSubjectRequestEvent::query()->create([
                    'request_id' => (int) $locked->getKey(),
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'event' => DataSubjectRequestEvent::EVENT_RETENTION_CHECK,
                    'from_status' => $from->value,
                    'to_status' => $target->value,
                    'note_encrypted' => 'override: '.$override,
                    'created_at' => Carbon::now(),
                ]);
            }

            return $locked;
        }, 3);

        $this->audit->log(
            userId: $actorId,
            action: AuditAction::Update,
            riskLevel: $target === PrivacyRequestStatus::Rejected || $target === PrivacyRequestStatus::Completed
                ? RiskLevel::Medium
                : RiskLevel::Low,
            auditable: $updated,
            description: 'A data-subject request changed state.',
            metadata: [
                'reference' => $updated->reference,
                'type' => $updated->type->value,
                'status' => $updated->status->value,
                'actor_type' => $actorType,
                'has_note' => $note !== null && $note !== '',
                'reason_code' => $reasonCode,
                // A boolean, not the sentence: the justification itself lives
                // in the request's encrypted timeline, where it belongs.
                'retention_overridden' => $override !== null,
            ],
        );

        if ($notifyRequester && $updated->isVerified()) {
            $this->mailRequester($updated, new PrivacyRequestUpdateMail($updated, $note));
        }

        return $updated;
    }

    /**
     * May this request be closed (or rejected) at all?
     *
     * Returns the justification when an operator has explicitly overridden a
     * retention block, and null when nothing needed overriding.
     *
     * THREE OUTCOMES, and the middle one is the important one:
     *
     *  1. A request that cannot destroy records (access, portability, and the
     *     rest) is not gated: there is nothing to retain against.
     *  2. An erasure or restriction being COMPLETED while the retention guard
     *     reports a reason is refused unless an operator gives a written
     *     justification of real length. The refusal names the reasons, so the
     *     operator is not told "computer says no".
     *  3. An erasure or restriction being REJECTED must cite the retention
     *     obligation, because that is the only lawful reason to refuse one -
     *     a rejection filed under "duplicate" would be a false record.
     *
     * Nothing here deletes anything: the guard only answers a question.
     */
    /**
     * Nothing moves forward on an unverified identity.
     *
     * The two states a request may reach without a verified address are the two
     * that END it: expired (the link's window closed) and cancelled (the person,
     * or the public form's owner, withdrew it). Everything else - acknowledging
     * it, asking for more information, completing it, refusing it - is an act
     * about a person, and acting on an unverified address is acting on whoever
     * happens to own the mailbox this week.
     *
     * This is enforced in the SERVICE, not only in the panel, because the panel
     * is one caller of many (the console commands and the jobs are others).
     */
    private function assertIdentityVerified(DataSubjectRequest $request, PrivacyRequestStatus $target): void
    {
        if ($request->isVerified()) {
            return;
        }

        if (in_array($target, [PrivacyRequestStatus::Expired, PrivacyRequestStatus::Cancelled], true)) {
            return;
        }

        throw PrivacyRequestException::notVerified();
    }

    private function assertRetentionAllows(
        DataSubjectRequest $request,
        PrivacyRequestStatus $target,
        ?string $reasonCode,
        ?string $overrideReason,
    ): ?string {
        $override = $overrideReason === null ? null : trim($overrideReason);
        $override = ($override === '') ? null : $override;

        if (! $request->type->touchesRetention()) {
            return null;
        }

        if ($target === PrivacyRequestStatus::Rejected) {
            if ($reasonCode !== self::RETENTION_REASON_CODE) {
                throw PrivacyRequestException::retentionReasonRequired($request->reference);
            }

            return null;
        }

        if ($target !== PrivacyRequestStatus::Completed) {
            return null;
        }

        $report = app(PrivacyRetentionGuard::class)->evaluate($request);

        if (! (bool) ($report['blocked'] ?? false)) {
            return null;
        }

        $codes = array_values(array_map(
            static fn (array $reason): string => (string) ($reason['code'] ?? ''),
            (array) ($report['reasons'] ?? []),
        ));

        if ($override === null || mb_strlen($override) < self::RETENTION_OVERRIDE_MIN_LENGTH) {
            throw PrivacyRequestException::retentionBlocked($codes);
        }

        return $override;
    }

    /**
     * Append an internal timeline note. No state changes, no mail.
     */
    public function note(DataSubjectRequest $request, string $actorType, ?int $actorId, string $body): DataSubjectRequestEvent
    {
        return DataSubjectRequestEvent::query()->create([
            'request_id' => (int) $request->getKey(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'event' => DataSubjectRequestEvent::EVENT_NOTE,
            'from_status' => $request->status->value,
            'to_status' => $request->status->value,
            'note_encrypted' => $body,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Append a lifecycle event that is not a status change (an export was
     * built, a reminder was sent, a retention check was run).
     */
    public function recordEvent(
        DataSubjectRequest $request,
        string $event,
        string $actorType = DataSubjectRequestEvent::ACTOR_SYSTEM,
        ?int $actorId = null,
        ?string $note = null,
    ): DataSubjectRequestEvent {
        return DataSubjectRequestEvent::query()->create([
            'request_id' => (int) $request->getKey(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'event' => $event,
            'from_status' => $request->status->value,
            'to_status' => $request->status->value,
            'note_encrypted' => $note,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Retire a request whose verification window closed.
     *
     * Only PendingVerification may expire through this path; anything already
     * received has a clock of its own and is expired by the SLA sweep if a
     * policy says so.
     */
    public function expireUnverified(DataSubjectRequest $request, string $reason): void
    {
        if ($request->status !== PrivacyRequestStatus::PendingVerification) {
            return;
        }

        DB::transaction(function () use ($request, $reason): void {
            /** @var DataSubjectRequest $locked */
            $locked = DataSubjectRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->status !== PrivacyRequestStatus::PendingVerification) {
                return;
            }

            $locked->status = PrivacyRequestStatus::Expired;
            $locked->save();

            DataSubjectRequestEvent::query()->create([
                'request_id' => (int) $locked->getKey(),
                'actor_type' => DataSubjectRequestEvent::ACTOR_SYSTEM,
                'actor_id' => null,
                'event' => DataSubjectRequestEvent::EVENT_STATUS_CHANGE,
                'from_status' => PrivacyRequestStatus::PendingVerification->value,
                'to_status' => PrivacyRequestStatus::Expired->value,
                'note_encrypted' => $reason,
                'created_at' => Carbon::now(),
            ]);
        }, 3);
    }

    /**
     * The decrypted address a request names.
     *
     * Kept here rather than on the model because the only legitimate readers
     * are the exporter and the operator panel; a public projection never sees it.
     */
    public function emailOf(DataSubjectRequest $request): string
    {
        return DataSubjectRequest::normaliseEmail((string) $request->email_encrypted);
    }

    /**
     * The verification link lives for this long, in minutes.
     */
    public function verificationTtlMinutes(): int
    {
        return max(5, (int) config('privacy_requests.verification_ttl_minutes', 1440));
    }

    public function slaDays(): int
    {
        return max(1, (int) config('privacy_requests.sla_days', 30));
    }

    public function maxOpenPerEmail(): int
    {
        return max(1, (int) config('privacy_requests.max_open_per_email', 3));
    }

    /**
     * The count that the ceiling compares against - open requests only, so a
     * person who has had three requests answered may submit a fourth.
     */
    public function openCount(string $emailHash): int
    {
        return DataSubjectRequest::query()
            ->where('email_hash', $emailHash)
            ->open()
            ->count();
    }

    private function assertAcceptedType(PrivacyRequestType $type): void
    {
        if (! in_array($type->value, PrivacyRequestType::acceptedValues(), true)) {
            throw PrivacyRequestException::typeUnsupported($type->value);
        }
    }

    /**
     * Persist the request, its first event and (for a public intake) its token.
     *
     * Everything happens inside one transaction so a request without a token
     * - which nobody could ever verify - cannot be committed.
     *
     * @return array{0: DataSubjectRequest, 1: string|null}
     */
    private function createRequest(
        string $emailHash,
        string $email,
        PrivacyRequestType $type,
        string $locale,
        ?string $details,
        ?string $ipHash,
        ?int $userId,
        PrivacyRequestStatus $status,
        bool $verifiedImmediately = false,
    ): array {
        return DB::transaction(function () use (
            $emailHash, $email, $type, $locale, $details, $ipHash, $userId, $status, $verifiedImmediately
        ): array {
            $now = Carbon::now();

            $request = new DataSubjectRequest;
            $request->fill([
                'user_id' => $userId,
                'email_hash' => $emailHash,
                'email_encrypted' => $email,
                'locale' => in_array($locale, ['en', 'th', 'bn'], true) ? $locale : 'en',
                'type' => $type,
                'status' => $status,
                'details_encrypted' => $details,
                'ip_hash' => $ipHash,
                'verified_at' => $verifiedImmediately ? $now : null,
                'received_at' => $verifiedImmediately ? $now : null,
                'due_at' => $verifiedImmediately ? $now->copy()->addDays($this->slaDays()) : null,
            ]);
            $request->save();

            DataSubjectRequestEvent::query()->create([
                'request_id' => (int) $request->getKey(),
                'actor_type' => DataSubjectRequestEvent::ACTOR_REQUESTER,
                'actor_id' => $userId,
                'event' => DataSubjectRequestEvent::EVENT_SUBMITTED,
                'from_status' => null,
                'to_status' => $status->value,
                'created_at' => $now,
            ]);

            $plainToken = null;

            if ($status === PrivacyRequestStatus::PendingVerification) {
                $issued = app(\App\Services\Privacy\PrivacyVerificationService::class)
                    ->issue($request, $now->copy()->addMinutes($this->verificationTtlMinutes()));
                $plainToken = $issued['plain'];
            }

            return [$request->refresh(), $plainToken];
        }, 3);
    }

    /**
     * Tell the requester their request has been received and the clock started.
     *
     * Public for the same reason as mailExportReady(): every privacy mail is
     * queued from this service, so the wording and the decision to send live in
     * one place instead of in whichever caller happens to finish first.
     */
    public function mailReceived(DataSubjectRequest $request): void
    {
        $this->mailRequester($request, new PrivacyRequestReceivedMail($request));
    }

    /**
     * Tell the requester their archive is ready.
     *
     * Public because the exporter releases the archive and this service owns
     * every mail that goes to a requester: one collaborator decides what a
     * privacy email says, and it is never the panel.
     */
    public function mailExportReady(DataSubjectRequest $request, PersonalDataExport $export): void
    {
        $this->mailRequester($request, new PersonalDataExportReadyMail($request, $export));
    }

    /**
     * Queue a mail for the requester, and never let a mail failure undo a
     * recorded decision.
     *
     * A privacy request is the record; the mail is a courtesy. If the mail lane
     * is not configured the operator sees it in the timeline and the panel, and
     * the requester can still see the state in their account area.
     */
    private function mailRequester(DataSubjectRequest $request, object $mailable): void
    {
        $email = $this->emailOf($request);

        if ($email === '') {
            return;
        }

        try {
            Mail::to($email)->queue($mailable);
        } catch (Throwable $exception) {
            Log::warning('privacy_request_mail_failed', [
                'reference' => $request->reference,
                'exception' => $exception::class,
            ]);
        }
    }

    private function withinRateLimits(string $emailHash, ?string $ipHash): bool
    {
        $perHour = max(1, (int) config('privacy_requests.rate_limits.submit_per_hour', 3));
        $emailPerDay = max(1, (int) config('privacy_requests.max_submissions_per_email_per_day', 5));
        $ipPerDay = max(1, (int) config('privacy_requests.max_submissions_per_ip_per_day', 10));

        if (RateLimiter::tooManyAttempts('privacy:submit:hour', $perHour)) {
            return false;
        }

        if (RateLimiter::tooManyAttempts('privacy:email:'.$emailHash, $emailPerDay)) {
            return false;
        }

        return ! RateLimiter::tooManyAttempts('privacy:ip:'.($ipHash ?? 'unknown'), $ipPerDay);
    }

    private function recordRateLimit(string $emailHash, ?string $ipHash): void
    {
        RateLimiter::hit('privacy:submit:hour', 3600);
        RateLimiter::hit('privacy:email:'.$emailHash, 86400);
        RateLimiter::hit('privacy:ip:'.($ipHash ?? 'unknown'), 86400);
    }
}
