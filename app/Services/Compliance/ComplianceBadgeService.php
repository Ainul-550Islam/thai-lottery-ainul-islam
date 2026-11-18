<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Enums\ComplianceAttestationCode;
use App\Models\ComplianceAttestation;
use Illuminate\Support\Carbon;

/**
 * The ONLY producer of a public credential statement.
 *
 * THE RULE
 * A badge is returned if and only if a compliance_attestations row exists that is
 *   - not revoked,
 *   - started on or before today,
 *   - not ended, or ending today or later,
 *   - and carries a reference plus a well-formed document hash.
 *
 * config('player_experience.compliance.badge_requirements') decides which code a
 * public badge key needs, so turning a badge off is a config change and not a
 * deployment. An empty map means no badges, which is the honest state of a system
 * with no attestation rows: the endpoint returns an empty list, never a
 * placeholder.
 *
 * WHAT IT WILL SAY
 * code-neutral, factual labels from ComplianceAttestationCode::neutralLabel(),
 * the attestation reference, the issuer and the validity window. Nothing
 * superlative, nothing comparative, and specifically not "Licensed by GLO" —
 * that string is in the forbidden-claims list, and if the operator genuinely
 * holds a GLO licence the evidence document says so and the badge still only says
 * "Operator licence on file" with a reference a reader can check.
 *
 * WHAT IS ALWAYS ALLOWED
 * The 18+ notice and the responsible-gaming notice: they assert a policy the
 * operator applies to itself, which needs no third-party evidence. They are
 * listed in config('player_experience.compliance.unattested_allowed') and are
 * returned by unattestedNotices() — never as a "badge" with an implied audit
 * behind it.
 */
final class ComplianceBadgeService
{
    /**
     * Every badge that may be published right now.
     *
     * @return list<array<string, mixed>>
     */
    public function badges(?Carbon $at = null): array
    {
        $now = $at ?? Carbon::now();

        /** @var array<string, string> $requirements */
        $requirements = (array) config('player_experience.compliance.badge_requirements', []);

        if ($requirements === []) {
            return [];
        }

        $out = [];

        foreach ($requirements as $badgeKey => $codeValue) {
            $code = ComplianceAttestationCode::tryFrom((string) $codeValue);

            if (! $code instanceof ComplianceAttestationCode) {
                // A misconfigured requirement must not publish anything.
                continue;
            }

            $attestation = $this->validAttestationFor($code, $now);

            if (! $attestation instanceof ComplianceAttestation) {
                continue;
            }

            $out[] = [
                'key' => (string) $badgeKey,
                // Explicit, not inferred from the presence of `code`. The resource
                // publishes this field verbatim so `attested: true` is a statement
                // the service made about a row it verified, not a guess.
                'attested' => true,
                'code' => $code->value,
                'label' => $code->neutralLabel(),
                'description' => $code->description(),
                'reference' => $attestation->reference,
                'issuer' => $attestation->issuer,
                'valid_from' => $attestation->valid_from?->toDateString(),
                'valid_to' => $attestation->valid_to?->toDateString(),
                // The hash is published so a reader can ask the operator for the
                // matching document. The document itself is not served here.
                'document_hash' => $attestation->document_hash,
                'evidence_on_file' => $attestation->hasEvidence(),
            ];
        }

        return $out;
    }

    /**
     * The notices that need no attestation — always available, always neutral.
     *
     * @return list<array<string, string>>
     */
    public function unattestedNotices(): array
    {
        /** @var list<string> $allowed */
        $allowed = (array) config('player_experience.compliance.unattested_allowed', []);

        $labels = [
            'age_18_plus' => '18+ only',
            'responsible_gaming_notice' => 'Play responsibly. Set your own limits.',
        ];

        $out = [];

        foreach ($allowed as $key) {
            if (isset($labels[$key])) {
                $out[] = [
                    'key' => (string) $key,
                    'label' => $labels[$key],
                    // A policy notice the operator applies to itself. It asserts
                    // no third-party credential and must never render as a badge.
                    'attested' => false,
                ];
            }
        }

        return $out;
    }

    /**
     * Whether a specific code is currently provable. Used by the responsible
     * gaming summary and by tests; it is the same predicate as badges().
     */
    public function hasValidAttestation(ComplianceAttestationCode $code, ?Carbon $at = null): bool
    {
        return $this->validAttestationFor($code, $at ?? Carbon::now()) instanceof ComplianceAttestation;
    }

    /**
     * The newest valid attestation of a code with usable evidence, or null.
     *
     * The `hasEvidence()` filter is applied AFTER the validity scope, so an
     * expired-but-complete row cannot win over a valid-but-hashless one: a row
     * without a hash is a note, and a note cannot carry a public claim.
     */
    public function validAttestationFor(ComplianceAttestationCode $code, ?Carbon $at = null): ?ComplianceAttestation
    {
        $now = $at ?? Carbon::now();

        $rows = ComplianceAttestation::query()
            ->ofCode($code)
            ->valid($now)
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get();

        foreach ($rows as $row) {
            if (! $row->hasEvidence()) {
                continue;
            }

            if (! $code->allowsOpenEndedValidity() && $row->valid_to === null) {
                // An audit with no end date is stale by definition.
                continue;
            }

            return $row;
        }

        return null;
    }

    /**
     * The payload the public endpoint returns. Badges and notices are kept in
     * separate keys so a client can never render a notice as an audited badge.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(?Carbon $at = null): array
    {
        $now = $at ?? Carbon::now();

        return [
            'badges' => $this->badges($now),
            'notices' => $this->unattestedNotices(),
            'attested_count' => count($this->badges($now)),
            'generated_at' => $now->toIso8601String(),
        ];
    }
}
