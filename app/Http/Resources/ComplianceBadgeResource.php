<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A compliance badge or notice.
 *
 * `attested` is the field that matters: true means a valid, unrevoked, evidence-bearing
 * attestation row backs this label; false means it is a policy notice the operator
 * applies to itself (18+, play responsibly) and asserts no third-party credential.
 * A client that renders the two identically is making a claim the operator did not.
 *
 * `document_hash` is published so a reader can ask the operator for the matching
 * document. The document itself is never served from this system.
 *
 * @property array<string, mixed> $resource
 */
final class ComplianceBadgeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $badge */
        $badge = (array) $this->resource;

        // The field itself is the statement; never infer it from a missing key,
        // because a notice nobody typed `attested` into would silently become a
        // claim. Anything that is not literally true is not attested.
        $isNotice = ! (($badge['attested'] ?? false) === true);

        return [
            'key' => (string) ($badge['key'] ?? ''),
            'label' => (string) ($badge['label'] ?? ''),
            'attested' => ! $isNotice,
            'code' => $badge['code'] ?? null,
            'description' => $badge['description'] ?? null,
            'reference' => $badge['reference'] ?? null,
            'issuer' => $badge['issuer'] ?? null,
            'valid_from' => $badge['valid_from'] ?? null,
            'valid_to' => $badge['valid_to'] ?? null,
            'document_hash' => $badge['document_hash'] ?? null,
            'evidence_on_file' => (bool) ($badge['evidence_on_file'] ?? false),
        ];
    }
}
