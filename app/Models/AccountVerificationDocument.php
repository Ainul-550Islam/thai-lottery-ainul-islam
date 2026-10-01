<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Account-facing view of the CANONICAL kyc_documents row (metadata only).
 *
 * File bytes live on the private local disk under a server-generated name;
 * this model never exposes a public URL and never serves raw content.
 * Extends KycDocument so columns, casts and lifecycle stay single-sourced.
 */
class AccountVerificationDocument extends KycDocument
{
    /** Same canonical table as KycDocument — no second schema. */
    protected $table = 'kyc_documents';

    /**
     * Safe presentation payload for the owner-facing page:
     * type, status, timestamps — NEVER the storage path as a URL.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $type = $this->document_type instanceof KycDocumentType
            ? $this->document_type->value
            : (string) $this->document_type;
        $status = $this->status instanceof KycStatus
            ? $this->status->value
            : (string) $this->status;

        return [
            'document_type' => $type,
            'status' => $status,
            'mime_type' => (string) $this->mime_type,
            'file_size' => (int) $this->file_size,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // Original name is display-only; storage uses a server name.
            'display_name' => (string) $this->original_filename,
        ];
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
