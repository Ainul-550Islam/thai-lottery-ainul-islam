<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Enums\AuditAction;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Models\AccountVerification;
use App\Models\AccountVerificationDocument;
use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Security\KycVerificationService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Account Verification FACADE over the existing canonical KYC stack.
 *
 * ONE identity state: this service never invents a second status
 * vocabulary — it maps KycStatus / KycVerificationStatus to the public
 * NOT_SUBMITTED…EXPIRED words for the account page only.
 *
 * Submissions compose App\Services\Security\KycVerificationService
 * (private storage + audit) and Compliance KycVerificationService
 * (derived verdicts). Duplicate open requests are rejected; clients
 * can never supply status=approved.
 */
final class AccountVerificationService
{
    public function __construct(
        private readonly KycVerificationService $kycUpload,
        private readonly AccountVerificationDocumentService $documents,
    ) {
    }

    /**
     * Public status for the logged-in user (server-derived).
     */
    public function publicStatus(User $user): string
    {
        $kyc = $user->kycStatus();

        return AccountVerification::publicStatusFromKycStatus($kyc);
    }

    /**
     * Account information block safe for the owner only.
     *
     * @return array<string, mixed>
     */
    public function accountInfo(User $user): array
    {
        $joined = $user->created_at?->format('Y-m-d') ?? '';
        // Renewal: yearly anniversary of join when tracked; else NOT_CONFIGURED.
        $renew = $joined !== ''
            ? $user->created_at?->copy()->addYear()->format('Y-m-d')
            : null;

        return [
            'account_number' => (string) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'join_date' => $joined,
            'renew_date' => $renew ?? 'NOT_CONFIGURED',
            'verification_status' => $this->publicStatus($user),
            'method' => (string) config('account.verification.method', 'DOCUMENT_UPLOAD_VERIFICATION'),
            'phone_verification' => (bool) config('account.verification.phone.otp_enabled', false)
                ? 'CONFIGURED'
                : 'PHONE_VERIFICATION_NOT_CONFIGURED',
            'phone' => $user->phone !== null && $user->phone !== ''
                ? $this->maskPhone((string) $user->phone)
                : null,
            'phone_verified' => $user->phone_verified_at !== null,
        ];
    }

    /**
     * Whether an open verification conversation blocks a new submit.
     */
    public function hasOpenRequest(User $user): bool
    {
        $openDoc = KycDocument::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [KycStatus::Pending, KycStatus::UnderReview])
            ->exists();

        if ($openDoc) {
            return true;
        }

        return AccountVerification::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'under_review'])
            ->exists();
    }

    /**
     * Submit a verification package: optional phone capture (server
     * normalized, never trusted as verified) + one primary document.
     *
     * Client-supplied status / phone_verified / approved are ignored.
     *
     * @param  array{
     *     country_code?: string|null,
     *     mobile?: string|null,
     *     document_type: string,
     *     document_number?: string|null,
     *     document: UploadedFile,
     *     document_back?: UploadedFile|null,
     * }  $payload
     *
     * @return array{status: string, document: AccountVerificationDocument, open_request: bool}
     *
     * @throws InvalidArgumentException
     */
    public function submit(User $user, array $payload, ?string $ipAddress = null): array
    {
        if ($this->hasOpenRequest($user)) {
            throw new InvalidArgumentException(
                (string) trans('account_services.verification_error_duplicate'),
            );
        }

        $typeValue = (string) ($payload['document_type'] ?? '');
        $type = KycDocumentType::tryFrom($typeValue);
        if (! $type instanceof KycDocumentType) {
            throw new InvalidArgumentException(
                (string) trans('account_services.verification_error_invalid_type'),
            );
        }

        // Phone: normalize + store contact only; NEVER set phone_verified.
        $mobile = $this->normalizeMobile(
            (string) ($payload['country_code'] ?? ''),
            (string) ($payload['mobile'] ?? ''),
        );
        if ($mobile !== null) {
            // Explicit attribute write — phone is fillable but verified_at is not.
            if ($user->phone !== $mobile) {
                $user->phone = $mobile;
                $user->save();
            }
        }

        $front = $payload['document'] ?? null;
        if (! $front instanceof UploadedFile) {
            throw new InvalidArgumentException('A document file is required.');
        }

        // Hardened document path (MIME/ext/size/path-traversal/fingerprint).
        $document = $this->documents->storeDocument(
            user: $user,
            type: $type,
            file: $front,
            documentNumber: isset($payload['document_number']) ? (string) $payload['document_number'] : null,
            ipAddress: $ipAddress,
        );

        // Optional second file (back / support) under the same rules.
        $back = $payload['document_back'] ?? null;
        if ($back instanceof UploadedFile) {
            $this->documents->storeDocument(
                user: $user,
                type: KycDocumentType::Other,
                file: $back,
                documentNumber: null,
                ipAddress: $ipAddress,
            );
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => AuditAction::Create,
            'auditable_type' => AccountVerification::class,
            'auditable_id' => $document->id,
            'ip_address' => $ipAddress,
            'metadata' => [
                'action_type' => 'account_verification_submitted',
                'document_id' => $document->id,
                'document_type' => $type->value,
                'method' => (string) config('account.verification.method'),
            ],
        ]);

        return [
            'status' => 'PENDING',
            'document' => $document,
            'open_request' => true,
        ];
    }

    /**
     * Owner-scoped document list (metadata only — never storage URLs).
     *
     * @return list<array<string, mixed>>
     */
    public function documentsFor(User $user): array
    {
        return AccountVerificationDocument::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (AccountVerificationDocument $doc): array => $doc->toPublicArray())
            ->all();
    }

    /**
     * Reviewer-only decision path (authorization enforced by controller/policy).
     * Delegates to the canonical Security service — four-eyes + audit included.
     */
    public function review(
        KycDocument $document,
        User $reviewer,
        bool $approved,
        ?string $reason = null,
    ): void {
        $this->kycUpload->reviewDocument($document, $reviewer, $approved, $reason);

        AuditLog::create([
            'user_id' => $reviewer->id,
            'action' => AuditAction::Update,
            'auditable_type' => AccountVerification::class,
            'auditable_id' => $document->id,
            'metadata' => [
                'action_type' => $approved ? 'account_verification_approved' : 'account_verification_rejected',
                'target_user_id' => $document->user_id,
            ],
        ]);
    }

    private function normalizeMobile(string $countryCode, string $mobile): ?string
    {
        $cc = trim($countryCode);
        $num = trim($mobile);
        if ($cc === '' && $num === '') {
            return null;
        }
        if ($num === '') {
            return null;
        }

        $defaultCc = (string) config('account.verification.phone.default_country_code', '+66');
        if ($cc === '') {
            $cc = $defaultCc;
        }
        // Country code: + and 1–4 digits.
        if (! preg_match('/^\+\d{1,4}$/', $cc)) {
            throw new InvalidArgumentException('Invalid country code.');
        }
        // Digits only in the national number (allow spaces/dashes stripped).
        $digits = preg_replace('/[\s\-().]/', '', $num) ?? '';
        if ($digits === '' || ! preg_match('/^\d{5,15}$/', $digits)) {
            throw new InvalidArgumentException('Invalid mobile number.');
        }

        $max = (int) config('account.verification.phone.max_length', 20);
        $combined = $cc.$digits;
        if (strlen($combined) > $max) {
            throw new InvalidArgumentException('Mobile number too long.');
        }

        return $combined;
    }

    private function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }

        return str_repeat('•', $len - 4).substr($phone, -4);
    }
}
