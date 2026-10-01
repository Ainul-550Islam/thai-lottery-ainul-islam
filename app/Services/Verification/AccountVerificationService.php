<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Enums\AccountVerificationStatus;
use App\Enums\AuditAction;
use App\Enums\KycDocumentType;
use App\Enums\VerificationDocumentType;
use App\Models\AccountVerification;
use App\Models\AccountVerificationDocument;
use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Account\AccountVerificationDocumentService;
use App\Services\Account\AccountVerificationService as AccountVerificationFacade;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/*
 * PROMPT 3 — the canonical member verification workflow.
 *
 * A GAP-CLOSURE LAYER over the existing KYC stack, not a replacement:
 * - the member-facing account summary / public status words /
 *   duplicate-open-request guard delegate to the EXISTING
 *   Services\Account\AccountVerificationService (which itself composes
 *   the hardened Security KYC storage and the compliance verdicts);
 * - the NEW part is the immutable submission-event aggregate: every
 *   submission writes one account_verifications row (reference,
 *   country/mobile pair, document pair, rule version, fingerprint);
 *   every review decision closes exactly that row and nothing else.
 *
 * STATE MACHINE: explicit, enforced by AccountVerificationStatus::
 * canTransitionTo(). A member submission can never approve itself —
 * transitions to terminal states exist only through review(), which
 * the controller authorizes via AccountVerificationPolicy.
 *
 * RETRIES: rejected/terminal submissions never block a new one; the
 * historical row is never rewritten (append-only), so a later
 * rejection can never silently erase an earlier approval.
 *
 * AUDIT: AuditLog rows carry who/when/what-type/reference — never
 * document bytes, never storage paths as clickable URLs.
 */
final class AccountVerificationService
{
    public function __construct(
        private readonly AccountVerificationFacade $facade,
        private readonly DocumentStorageService $storage,
        private readonly AccountVerificationDocumentService $documents,
    ) {
    }

    /*
    |----------------------------------------------------------------------
    | Read surface (delegated — one source of truth)
    |----------------------------------------------------------------------
    */

    /**
     * The member's account summary block (owner-only data).
     *
     * @return array<string, mixed>
     */
    public function accountInfo(User $user): array
    {
        return $this->facade->accountInfo($user);
    }

    /**
     * The public status word for the member's identity state.
     */
    public function publicStatus(User $user): string
    {
        return $this->facade->publicStatus($user);
    }

    /**
     * Owner-scoped document metadata list (never storage URLs).
     *
     * @return list<array<string, mixed>>
     */
    public function documentsFor(User $user): array
    {
        return $this->facade->documentsFor($user);
    }

    public function documentForDownload(User $user, string $token): ?AccountVerificationDocument
    {
        return $this->facade->documentForDownload($user, $token);
    }

    /**
     * The immutable submission history (newest first), member-safe.
     *
     * @return list<array<string, mixed>>
     */
    public function historyFor(User $user): array
    {
        $limit = max(1, (int) config('account_verification.submission.history_limit', 10));

        return AccountVerification::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(static fn (AccountVerification $row): array => $row->toPublicArray())
            ->all();
    }

    /**
     * Whether an open submission blocks a duplicate submit (existing
     * KYC open documents OR an open aggregate row).
     */
    public function hasOpenRequest(User $user): bool
    {
        return $this->facade->hasOpenRequest($user);
    }

    /*
    |----------------------------------------------------------------------
    | Submission (the new canonical path)
    |----------------------------------------------------------------------
    */

    /**
     * Submit a verification package (front required, back optional
     * per the configured policy).
     *
     * Client-supplied status / approved / phone_verified are never
     * read. The server derives every state.
     *
     * @param  array{
     *     country_code?: string|null,
     *     mobile?: string|null,
     *     document_type?: string|null,
     *     document_number?: string|null,
     *     document?: UploadedFile|null,
     *     document_back?: UploadedFile|null,
     * }  $payload
     *
     * @return array{status: string, reference: string}
     *
     * @throws InvalidArgumentException on policy violations (the
     *                                   controller maps these to the
     *                                   'document' error key).
     */
    public function submit(User $user, array $payload, ?string $ipAddress = null): array
    {
        if ($this->hasOpenRequest($user)) {
            throw new InvalidArgumentException(
                (string) trans('account_services.verification_error_duplicate'),
            );
        }

        $type = VerificationDocumentType::tryFrom((string) ($payload['document_type'] ?? ''));

        if (! $type instanceof VerificationDocumentType
            || ! in_array($type, VerificationDocumentType::configured(), true)) {
            throw new InvalidArgumentException(
                (string) trans('account_services.verification_error_invalid_type'),
            );
        }

        $countryCode = $this->normalizeCountryCode((string) ($payload['country_code'] ?? ''));
        $mobile = $this->normalizeMobile($countryCode, (string) ($payload['mobile'] ?? ''));

        $front = $payload['document'] ?? null;

        if (! $front instanceof UploadedFile) {
            throw new InvalidArgumentException('A document file is required.');
        }

        $back = $payload['document_back'] ?? null;
        $requireBack = (bool) config('account_verification.uploads.require_back_document', false);

        if ($requireBack && ! $back instanceof UploadedFile) {
            throw new InvalidArgumentException(
                (string) trans('account_services.verification_error_back_required'),
            );
        }

        return DB::transaction(function () use ($user, $type, $front, $back, $countryCode, $mobile, $payload, $ipAddress): array {
            // 1) Capture the mobile pair on the account (never as verified).
            if ($mobile !== null && $user->phone !== $mobile) {
                $user->phone = $mobile;
                $user->save();
            }

            // 2) The document pair — through the private storage
            //    abstraction with server-generated object keys.
            $frontDocument = $this->storage->store(
                $user,
                $type->toKycDocumentType(),
                $front,
                isset($payload['document_number']) ? (string) $payload['document_number'] : null,
                $ipAddress,
            );

            $backDocument = null;

            if ($back instanceof UploadedFile) {
                $backDocument = $this->storage->store(
                    $user,
                    KycDocumentType::Other,
                    $back,
                    null,
                    $ipAddress,
                );
            }

            // 3) The immutable submission-event row (fingerprint-unique:
            //    the constraint makes duplicate attachment impossible).
            $reference = $this->nextReference();
            $ruleVersion = (string) config('account_verification.rule_version', '1');

            $aggregate = AccountVerification::create([
                'verification_reference' => $reference,
                'user_id' => $user->id,
                'status' => AccountVerificationStatus::Pending,
                'country_code' => $countryCode,
                'mobile' => $mobile,
                'document_type' => $type->value,
                'front_document_id' => $frontDocument->id,
                'back_document_id' => $backDocument?->id,
                'submitted_at' => now(),
                'rule_version' => $ruleVersion,
                'fingerprint' => $this->fingerprint(
                    $user,
                    $type,
                    (int) $frontDocument->id,
                    $backDocument?->id,
                    $mobile,
                    $ruleVersion,
                ),
                'metadata' => [
                    'submission_ip' => $ipAddress,
                    'submitted_via' => 'web',
                ],
            ]);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => AuditAction::Create,
                'auditable_type' => AccountVerification::class,
                'auditable_id' => $aggregate->id,
                'ip_address' => $ipAddress,
                'metadata' => [
                    'action_type' => 'account_verification_submitted',
                    'verification_reference' => $reference,
                    'document_type' => $type->value,
                    'front_document_id' => $frontDocument->id,
                    'back_document_id' => $backDocument?->id,
                    'rule_version' => $ruleVersion,
                ],
            ]);

            return [
                'status' => AccountVerificationStatus::Pending->publicWord(),
                'reference' => $reference,
            ];
        });
    }

    /*
    |----------------------------------------------------------------------
    | Review (privileged, policy-authorized)
    |----------------------------------------------------------------------
    */

    /**
     * Move an open submission into review.
     */
    public function markUnderReview(AccountVerification $verification, User $reviewer): void
    {
        $this->applyTransition(
            $verification,
            $reviewer,
            AccountVerificationStatus::UnderReview,
            null,
        );
    }

    /**
     * Close a submission with a decision. The canonical KYC document
     * rows receive the same decision through the EXISTING review path
     * (four-eyes + audit included there).
     */
    public function review(AccountVerification $verification, User $reviewer, bool $approved, ?string $reason = null): void
    {
        $target = $approved ? AccountVerificationStatus::Approved : AccountVerificationStatus::Rejected;

        $reason = $reason !== null && $reason !== ''
            ? mb_substr(trim($reason), 0, (int) config('account_verification.review.max_reason_length', 500))
            : null;

        $this->applyTransition($verification, $reviewer, $target, $reason);

        // Mirror the decision onto the canonical document rows via the
        // existing facade (audited, reviewer-recorded there).
        $front = $verification->frontDocument()->first();

        if ($front instanceof KycDocument) {
            $this->facade->review($front, $reviewer, $approved, $reason);
        }

        $back = $verification->backDocument()->first();

        if ($back instanceof KycDocument) {
            $this->facade->review($back, $reviewer, $approved, $reason);
        }
    }

    /**
     * Expire a stale open submission (retention/supersede path).
     */
    public function expire(AccountVerification $verification, User $actor): void
    {
        $this->applyTransition($verification, $actor, AccountVerificationStatus::Expired, null);
    }

    /**
     * The single transition authority: legal-move check, then the
     * audited state write. Illegal moves throw; terminal rows are
     * immutable.
     */
    private function applyTransition(
        AccountVerification $verification,
        User $actor,
        AccountVerificationStatus $to,
        ?string $reason,
    ): void {
        $current = $verification->status instanceof AccountVerificationStatus
            ? $verification->status
            : AccountVerificationStatus::tryFrom((string) $verification->status);

        if (! $current instanceof AccountVerificationStatus || ! $current->canTransitionTo($to)) {
            throw new InvalidArgumentException(
                sprintf('Illegal verification transition: %s -> %s.', $current?->value ?? 'unknown', $to->value),
            );
        }

        $verification->status = $to;
        $verification->processed_at = now();
        $verification->reviewer_id = $actor->id;
        $verification->review_reason = $reason;
        $verification->save();

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => AuditAction::Update,
            'auditable_type' => AccountVerification::class,
            'auditable_id' => $verification->id,
            'metadata' => [
                'action_type' => 'account_verification_'.$to->value,
                'target_user_id' => $verification->user_id,
                'verification_reference' => (string) $verification->verification_reference,
                'rule_version' => (string) $verification->rule_version,
            ],
        ]);
    }

    /*
    |----------------------------------------------------------------------
    | Normalization helpers (Section Q — structured pair policy)
    |----------------------------------------------------------------------
    */

    /**
     * Country code must come from the controlled catalogue.
     */
    private function normalizeCountryCode(string $countryCode): ?string
    {
        $cc = trim($countryCode);

        if ($cc === '') {
            $cc = (string) config('account_verification.phone.default_country_code', '+66');
        }

        if (! preg_match('/^\+\d{1,4}$/', $cc)) {
            throw new InvalidArgumentException('Invalid country code.');
        }

        $catalogue = (array) config('account_verification.phone.country_codes', ['+66']);

        if (! in_array($cc, $catalogue, true)) {
            throw new InvalidArgumentException(
                (string) trans('account_services.verification_error_country'),
            );
        }

        return $cc;
    }

    /**
     * Normalized canonical mobile (country code + digits). Null when
     * nothing was supplied at all.
     */
    private function normalizeMobile(?string $countryCode, string $mobile): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', trim($mobile)) ?? '';

        // No mobile supplied at all: nothing to capture, no error —
        // mobile is optional on the submission surface.
        if ($digits === '') {
            return null;
        }

        $min = max(1, (int) config('account_verification.phone.min_nsn_digits', 5));
        $max = max($min, (int) config('account_verification.phone.max_nsn_digits', 15));

        if ($digits === '' || ! preg_match('/^\d{'.$min.','.$max.'}$/', $digits)) {
            throw new InvalidArgumentException('Invalid mobile number.');
        }

        $combined = $countryCode.$digits;
        $maxCombined = (int) config('account_verification.phone.max_combined_length', 20);

        if (strlen($combined) > $maxCombined) {
            throw new InvalidArgumentException('Mobile number too long.');
        }

        return $combined;
    }

    /**
     * Immutable public-safe reference (no storage path, no internal id).
     */
    private function nextReference(): string
    {
        return 'AV-'.now()->format('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    }

    /**
     * sha256 idempotency fingerprint over the submission identity.
     */
    private function fingerprint(User $user, VerificationDocumentType $type, int $frontId, ?int $backId, ?string $mobile, string $ruleVersion): string
    {
        return hash('sha256', implode('|', [
            (string) $user->id,
            $type->value,
            (string) $frontId,
            (string) ($backId ?? 0),
            (string) ($mobile ?? ''),
            $ruleVersion,
            now()->toDateString(),
        ]));
    }
}
