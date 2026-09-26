<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Enums\AuditAction;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Models\AccountVerificationDocument;
use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Security\KycVerificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Secure document storage wrapper for the Account Verification page.
 *
 * Composes Security\KycVerificationService (private disk, server-generated
 * name, MIME allow-list, audit) and ADDS upload-attack hardening:
 *   - original filename path-traversal / null-byte rejection
 *   - executable / double-extension rejection before storage
 *   - content fingerprint recorded in metadata (hash)
 *
 * Never uses the original filename as the storage path. Never publishes
 * a public URL for document bytes.
 */
final class AccountVerificationDocumentService
{
    private const FORBIDDEN_EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
        'exe', 'dll', 'so', 'sh', 'bash', 'bat', 'cmd', 'com', 'ps1',
        'js', 'mjs', 'cgi', 'pl', 'py', 'rb', 'jar', 'asp', 'aspx',
        'svg', 'html', 'htm', 'xhtml', 'swf',
    ];

    public function __construct(
        private readonly KycVerificationService $kyc,
    ) {
    }

    /**
     * Validate + store one document via the canonical KYC upload lane.
     *
     * @throws InvalidArgumentException
     */
    public function storeDocument(
        User $user,
        KycDocumentType $type,
        UploadedFile $file,
        ?string $documentNumber = null,
        ?string $ipAddress = null,
    ): AccountVerificationDocument {
        $this->assertSafeOriginalName((string) $file->getClientOriginalName());

        $ext = strtolower((string) $file->getClientOriginalExtension());
        if ($ext !== '' && in_array($ext, self::FORBIDDEN_EXTENSIONS, true)) {
            throw new InvalidArgumentException('Document file extension is not allowed.');
        }

        // Size ceiling from config (KB) before canonical service checks.
        $maxKb = (int) config('account.verification.max_file_kb', 10240);
        if ($file->getSize() !== false && (int) $file->getSize() > $maxKb * 1024) {
            throw new InvalidArgumentException('Document exceeds maximum size limit of '.$maxKb.'KB.');
        }

        // Fingerprint the bytes (sha256) for audit — never store contents in logs.
        $hash = hash_file('sha256', (string) $file->getRealPath());
        if ($hash === false) {
            throw new InvalidArgumentException('Unable to read uploaded document.');
        }

        $document = $this->kyc->submitDocument(
            user: $user,
            type: $type,
            file: $file,
            documentNumber: $documentNumber,
            ipAddress: $ipAddress,
        );

        // Stamp fingerprint + hardening provenance on the metadata lane.
        $meta = is_array($document->metadata) ? $document->metadata : [];
        $meta['content_sha256'] = $hash;
        $meta['upload_lane'] = 'account_verification_document_service';
        $meta['storage_disk'] = (string) config('account.verification.storage_disk', 'local');
        $document->metadata = $meta;
        $document->save();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => AuditAction::Create,
            'auditable_type' => AccountVerificationDocument::class,
            'auditable_id' => $document->id,
            'ip_address' => $ipAddress,
            'metadata' => [
                'action_type' => 'verification_document_added',
                'document_id' => $document->id,
                'document_type' => $type->value,
                'content_sha256' => $hash,
                // Never log file bytes or full ID numbers.
            ],
        ]);

        /** @var AccountVerificationDocument $mapped */
        $mapped = AccountVerificationDocument::query()->find((int) $document->id);

        return $mapped instanceof AccountVerificationDocument
            ? $mapped
            : AccountVerificationDocument::query()->findOrFail((int) $document->id);
    }

    /**
     * Reject path traversal, null bytes, and absolute paths in original names.
     */
    private function assertSafeOriginalName(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Document filename is required.');
        }
        if (str_contains($name, "\0")) {
            throw new InvalidArgumentException('Document filename contains a null byte.');
        }
        if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new InvalidArgumentException('Document filename contains an illegal path.');
        }
        // Double extension attack: "invoice.pdf.php"
        $parts = explode('.', strtolower($name));
        if (count($parts) > 2) {
            foreach ($parts as $part) {
                if (in_array($part, self::FORBIDDEN_EXTENSIONS, true)) {
                    throw new InvalidArgumentException('Document filename contains a forbidden extension.');
                }
            }
        }
    }

    /**
     * Authorized download path (owner or reviewer) — streams from private disk.
     * Never exposes /storage/... public URLs.
     *
     * @return array{contents: string, mime: string, name: string}
     */
    public function readForAuthorized(KycDocument $document, User $viewer, bool $isReviewer): array
    {
        if ((int) $document->user_id !== (int) $viewer->getKey() && ! $isReviewer) {
            throw new InvalidArgumentException('Not authorized to view this document.');
        }

        $diskName = (string) config('account.verification.storage_disk', 'local');
        $disk = Storage::disk($diskName);
        $path = (string) $document->file_path;

        // Path traversal guard on the stored path as well.
        if (str_contains($path, '..')) {
            throw new InvalidArgumentException('Illegal document path.');
        }
        if (! $disk->exists($path)) {
            throw new InvalidArgumentException('Document file not found.');
        }

        $contents = $disk->get($path);
        if ($contents === null || $contents === false) {
            throw new InvalidArgumentException('Document file unreadable.');
        }

        AuditLog::create([
            'user_id' => $viewer->id,
            'action' => AuditAction::Update,
            'auditable_type' => KycDocument::class,
            'auditable_id' => $document->id,
            'metadata' => [
                'action_type' => 'verification_document_viewed',
                'viewer_role' => $isReviewer ? 'reviewer' : 'owner',
            ],
        ]);

        return [
            'contents' => (string) $contents,
            'mime' => (string) $document->mime_type,
            'name' => 'document_'.(int) $document->id,
        ];
    }
}
