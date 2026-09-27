<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\TicketStatus;
use App\Models\GloTicket;
use App\Models\Ticket;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Digital ticket authenticity (PROMPT 4).
 *
 * THE ONE SENTENCE THAT DEFINES THIS CLASS
 * A database row proves that a RECORD exists. It does not prove that a piece
 * of paper in someone's hand is genuine, and this service will never say that
 * it does.
 *
 * VERDICT VOCABULARY (config('ticket_verification.authenticity_states'))
 *   DIGITAL_RECORD_VERIFIED  a canonical record was found AND the stronger
 *                            checks passed: it belongs to a real draw, its
 *                            product is known, it carries an issue record,
 *                            its ownership state is coherent and (for
 *                            operator tickets) its integrity fingerprint is
 *                            present.
 *   PUBLIC_RECORD_FOUND      a public record exists, but the stronger checks
 *                            are unavailable for it - typically a GLO ticket,
 *                            where this platform holds a public projection
 *                            and not the state lottery's own issue registry.
 *   REVOKED                  the record exists and is cancelled/expired/void.
 *   NOT_VERIFIED             nothing could be affirmed.
 *
 * PHYSICAL SECURITY FEATURES
 * Watermarks, security fibre, ink and embossing are described on the public
 * page as INFORMATIONAL guidance for inspecting paper by hand. They are never
 * inputs to a verdict here, and config('ticket_verification.authenticity
 * .physical_features_are_informational_only') exists so that this stays a
 * reviewable policy rather than a comment.
 *
 * NO PII EVER LEAVES THIS CLASS. The owner is reduced to a boolean
 * "ownership_recorded"; no id, name, contact or document is returned.
 */
final class TicketAuthenticityService
{
    public const DIGITAL_RECORD_VERIFIED = 'DIGITAL_RECORD_VERIFIED';

    public const PUBLIC_RECORD_FOUND = 'PUBLIC_RECORD_FOUND';

    public const NOT_VERIFIED = 'NOT_VERIFIED';

    public const REVOKED = 'REVOKED';

    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Authenticity of a GLO ticket projection held by this platform.
     *
     * @return array{
     *     state: string,
     *     product: string,
     *     draw_id: int|null,
     *     ownership_recorded: bool,
     *     issue_recorded: bool,
     *     fingerprint_checked: bool,
     *     paper_authenticity_claimed: false,
     *     notes: list<string>
     * }
     */
    public function forGloTicket(?GloTicket $ticket): array
    {
        if (! $ticket instanceof GloTicket) {
            return $this->verdict(self::NOT_VERIFIED, TicketIdentityService::PRODUCT_UNKNOWN);
        }

        $product = strtolower((string) $ticket->product) === 'n3'
            ? TicketIdentityService::PRODUCT_GLO_N3
            : TicketIdentityService::PRODUCT_GLO_L6;

        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];

        $void = (bool) ($metadata['void'] ?? false)
            || (bool) ($metadata['revoked'] ?? false);

        if ($void) {
            return $this->verdict(
                self::REVOKED,
                $product,
                drawId: (int) $ticket->draw_id,
                notes: ['RECORD_MARKED_VOID'],
            );
        }

        $ownershipRecorded = $ticket->owner_user_id !== null;
        $issueRecorded = (string) $ticket->ticket_reference !== '';

        // This platform is not the state lottery's issue registry. Even with a
        // complete local record, the honest ceiling for a GLO ticket is that a
        // public record was found.
        return $this->verdict(
            self::PUBLIC_RECORD_FOUND,
            $product,
            drawId: (int) $ticket->draw_id,
            ownershipRecorded: $ownershipRecorded,
            issueRecorded: $issueRecorded,
            notes: ['PUBLIC_PROJECTION_ONLY'],
        );
    }

    /**
     * Authenticity of one of this platform's own betting tickets.
     *
     * Here the stronger verdict IS available, because this application issued
     * the ticket: it owns the issue record, the ownership row and the
     * integrity fingerprint that App\Services\Ticket\TicketQrVerificationService
     * attests with.
     *
     * @return array{state: string, product: string, draw_id: int|null, ownership_recorded: bool, issue_recorded: bool, fingerprint_checked: bool, paper_authenticity_claimed: false, notes: list<string>}
     */
    public function forOperatorTicket(?Ticket $ticket): array
    {
        if (! $ticket instanceof Ticket) {
            return $this->verdict(self::NOT_VERIFIED, TicketIdentityService::PRODUCT_OPERATOR);
        }

        $status = $ticket->status;

        if ($status === TicketStatus::Cancelled || $status === TicketStatus::Expired) {
            return $this->verdict(
                self::REVOKED,
                TicketIdentityService::PRODUCT_OPERATOR,
                drawId: (int) $ticket->draw_id,
                ownershipRecorded: true,
                issueRecorded: true,
                notes: ['TICKET_'.strtoupper($status->value)],
            );
        }

        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
        $hasFingerprint = isset($metadata['fingerprint']) && is_string($metadata['fingerprint'])
            && $metadata['fingerprint'] !== '';

        $requireOwnership = (bool) $this->config->get(
            'ticket_verification.authenticity.require_ownership_for_digital_verified',
            true,
        );
        $requireFingerprint = (bool) $this->config->get(
            'ticket_verification.authenticity.require_fingerprint_for_digital_verified',
            true,
        );

        $ownershipRecorded = $ticket->user_id !== null;
        $issueRecorded = $ticket->issued_at !== null || $status === TicketStatus::Confirmed;

        $qualifies = $issueRecorded
            && (! $requireOwnership || $ownershipRecorded)
            && (! $requireFingerprint || $hasFingerprint);

        return $this->verdict(
            $qualifies ? self::DIGITAL_RECORD_VERIFIED : self::PUBLIC_RECORD_FOUND,
            TicketIdentityService::PRODUCT_OPERATOR,
            drawId: (int) $ticket->draw_id,
            ownershipRecorded: $ownershipRecorded,
            issueRecorded: $issueRecorded,
            fingerprintChecked: $requireFingerprint && $hasFingerprint,
            notes: $qualifies ? [] : ['INTEGRITY_CHECKS_INCOMPLETE'],
        );
    }

    /**
     * Informational-only physical inspection guidance for the public page.
     *
     * Returned as translation keys so no claim is hardcoded in English, and
     * flagged so the view cannot render it next to a verdict as if the paper
     * had been examined.
     *
     * @return array{informational_only: bool, keys: list<string>}
     */
    public function physicalInspectionGuidance(): array
    {
        return [
            'informational_only' => (bool) $this->config->get(
                'ticket_verification.authenticity.physical_features_are_informational_only',
                true,
            ),
            'keys' => [
                'prize_discount.physical_note_intro',
                'prize_discount.physical_note_markings',
                'prize_discount.physical_note_barcode',
                'prize_discount.physical_note_limits',
            ],
        ];
    }

    /**
     * @param  list<string>  $notes
     * @return array{state: string, product: string, draw_id: int|null, ownership_recorded: bool, issue_recorded: bool, fingerprint_checked: bool, paper_authenticity_claimed: false, notes: list<string>}
     */
    private function verdict(
        string $state,
        string $product,
        ?int $drawId = null,
        bool $ownershipRecorded = false,
        bool $issueRecorded = false,
        bool $fingerprintChecked = false,
        array $notes = [],
    ): array {
        $allowed = (array) $this->config->get('ticket_verification.authenticity_states', []);

        if ($allowed !== [] && ! in_array($state, $allowed, true)) {
            $state = self::NOT_VERIFIED;
        }

        return [
            'state' => $state,
            'product' => $product,
            'draw_id' => $drawId,
            'ownership_recorded' => $ownershipRecorded,
            'issue_recorded' => $issueRecorded,
            'fingerprint_checked' => $fingerprintChecked,
            // Structural, not a value that can drift: this system never
            // asserts anything about physical paper.
            'paper_authenticity_claimed' => false,
            'notes' => $notes,
        ];
    }
}
