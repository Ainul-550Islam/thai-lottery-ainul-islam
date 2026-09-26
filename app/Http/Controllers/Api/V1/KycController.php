<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\KycDocumentType;
use App\Http\Responses\ApiResponse;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Security\KycVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Player API Controller for KYC status inquiry and document uploads.
 */
final class KycController
{
    public function __construct(
        private readonly KycVerificationService $kycService,
    ) {
    }

    /**
     * Get authenticated player's KYC status (P0-D contract).
     *
     * NEVER returns: raw file paths, reviewer notes, or the full national ID.
     * Shape is always status / verified_at / expires_at / next_action so this
     * route cannot 500 on empty document sets.
     */
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $kycStatus = $user->kycStatus();

            $documents = KycDocument::query()
                ->where('user_id', $user->id)
                ->latest('id')
                ->get();

            $latest = $documents->first();
            $verifiedAt = null;
            $expiresAt = null;

            foreach ($documents as $doc) {
                if ($doc->status instanceof \App\Enums\KycStatus && $doc->status === \App\Enums\KycStatus::Verified) {
                    $verifiedAt = $doc->verified_at?->toIso8601String() ?? $verifiedAt;
                    $expiresAt = $doc->expires_at?->toIso8601String() ?? $expiresAt;
                }
            }

            if ($verifiedAt === null && $latest?->verified_at !== null) {
                $verifiedAt = $latest->verified_at->toIso8601String();
            }
            if ($expiresAt === null && $latest?->expires_at !== null) {
                $expiresAt = $latest->expires_at->toIso8601String();
            }

            $nextAction = match ($kycStatus) {
                \App\Enums\KycStatus::Unverified => 'submit_document',
                \App\Enums\KycStatus::Pending, \App\Enums\KycStatus::UnderReview => 'wait_for_review',
                \App\Enums\KycStatus::Verified => 'none',
                \App\Enums\KycStatus::Rejected => 'resubmit_document',
                \App\Enums\KycStatus::Expired => 'resubmit_document',
            };

            return ApiResponse::success(
                data: [
                    'status' => $kycStatus->value,
                    'kyc_status' => $kycStatus->value,
                    'is_verified' => $kycStatus->isVerified(),
                    'verified_at' => $verifiedAt,
                    'expires_at' => $expiresAt,
                    'next_action' => $nextAction,
                    'documents' => $documents->map(fn (KycDocument $doc): array => [
                        'id' => $doc->id,
                        'document_type' => $doc->document_type instanceof \BackedEnum ? $doc->document_type->value : (string) $doc->document_type,
                        // Masked — never the full national ID.
                        'document_number_masked' => self::maskNationalId((string) ($doc->document_number ?? '')),
                        'status' => $doc->status instanceof \BackedEnum ? $doc->status->value : (string) $doc->status,
                        'verified_at' => $doc->verified_at?->toIso8601String(),
                        'expires_at' => $doc->expires_at?->toIso8601String(),
                        'created_at' => $doc->created_at?->toIso8601String(),
                    ])->all(),
                ],
                message: 'KYC status retrieved successfully.',
            );
        } catch (\Throwable $e) {
            // Contract: this route never 500s — degrade to Unverified shape.
            return ApiResponse::success(
                data: [
                    'status' => \App\Enums\KycStatus::Unverified->value,
                    'kyc_status' => \App\Enums\KycStatus::Unverified->value,
                    'is_verified' => false,
                    'verified_at' => null,
                    'expires_at' => null,
                    'next_action' => 'submit_document',
                    'documents' => [],
                ],
                message: 'KYC status unavailable; treated as not submitted.',
            );
        }
    }

    private static function maskNationalId(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '—';
        }
        $len = strlen($trimmed);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }

        return str_repeat('•', max(0, $len - 4)).substr($trimmed, -4);
    }

    /**
     * Upload an identity document for verification.
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', 'in:'.implode(',', array_column(KycDocumentType::cases(), 'value'))],
            'document_number' => ['nullable', 'string', 'max:100'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $type = KycDocumentType::from($validated['document_type']);
        $file = $request->file('document');

        if ($file === null || ! $file->isValid()) {
            return ApiResponse::error(
                code: 'invalid_file',
                message: 'Uploaded file is invalid or missing.',
                status: 422,
            );
        }

        try {
            $document = $this->kycService->submitDocument(
                user: $user,
                type: $type,
                file: $file,
                documentNumber: $validated['document_number'] ?? null,
                ipAddress: $request->ip(),
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error(
                code: 'kyc_upload_failed',
                message: $e->getMessage(),
                status: 422,
            );
        }

        return ApiResponse::success(
            data: [
                'document' => [
                    'id' => $document->id,
                    'document_type' => $document->document_type instanceof \BackedEnum ? $document->document_type->value : (string) $document->document_type,
                    'document_number' => $document->document_number,
                    'original_filename' => $document->original_filename,
                    'status' => $document->status instanceof \BackedEnum ? $document->status->value : (string) $document->status,
                    'created_at' => $document->created_at?->toIso8601String(),
                ],
            ],
            message: 'KYC document uploaded successfully and is pending review.',
            status: 201,
        );
    }
}
