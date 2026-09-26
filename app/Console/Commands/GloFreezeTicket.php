<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\GloFreezeStatus;
use App\Exceptions\GloFreezeException;
use App\Models\GloTicketFreeze;
use App\Models\User;
use App\Services\Lottery\GloTicketFreezeService;
use Illuminate\Console\Command;

/**
 * Operational CLI for the GLO-11 freeze state machine.
 *
 *   glo:freeze-ticket  request a freeze case (evidence required)
 *   glo:freeze-review  requested → under_review
 *   glo:freeze-approve under_review → frozen (evidence completeness enforced)
 *   glo:freeze-reject  requested|under_review → rejected
 *   glo:freeze-release frozen → released
 *   glo:expire-freezes  sweep due freezes → expired (audited, history kept)
 *
 * Authorization: --actor=email must be an active operator holding the GLO
 * freeze permission (or super-admin). Missing actor fails closed.
 */
class GloFreezeTicket extends Command
{
    protected $signature = 'glo:freeze-ticket
        {ticket : GloTicket id or ticket_reference}
        {--draw= : Draw id when resolving by raw number via --number}
        {--number= : Exact ticket digit string (enables reference resolution)}
        {--series= : Optional set/series}
        {--product=l6 : l6 or n3}
        {--authority= : Requesting authority name}
        {--jurisdiction= : Jurisdiction}
        {--case-reference= : Official case reference}
        {--evidence-reference= : Evidence document reference (id only)}
        {--evidence-type=official_notice : Evidence type}
        {--evidence-document-id= : Secure document id}
        {--evidence-content-hash= : Evidence content hash}
        {--evidence-mime-type= : Evidence MIME type}
        {--expiry= : Optional ISO expiry timestamp}
        {--actor= : Operator email executing this command}';

    protected $description = 'Request a GLO ticket freeze case (GLO-11, evidence required)';

    public function handle(GloTicketFreezeService $freezes): int
    {
        $actor = $this->resolveActor();

        if ($actor === null) {
            return self::FAILURE;
        }

        $ticketRef = (string) $this->argument('ticket');
        $number = (string) $this->option('number');
        $product = (string) $this->option('product');

        if ($number === '') {
            // Resolve by GloTicket id or ticket_reference.
            $ticket = \App\Models\GloTicket::query()
                ->whereKey(is_numeric($ticketRef) ? (int) $ticketRef : 0)
                ->orWhere('ticket_reference', $ticketRef)
                ->first();

            if ($ticket === null) {
                $this->error('Ticket not found: '.$ticketRef);

                return self::FAILURE;
            }

            $drawId = (int) $ticket->draw_id;
            $number = (string) $ticket->ticket_number;
            $product = (string) $ticket->product;
            $series = $ticket->set_series;
        } else {
            $drawId = (int) $this->option('draw');
            $series = $this->option('series');
        }

        $authority = (string) $this->option('authority');
        $caseReference = (string) $this->option('case-reference');
        $evidenceReference = (string) $this->option('evidence-reference');

        if ($authority === '' || $caseReference === '' || $evidenceReference === '') {
            $this->error('--authority, --case-reference and --evidence-reference are required before a freeze request.');

            return self::FAILURE;
        }

        try {
            $freeze = $freezes->requestFreeze([
                'draw_id' => $drawId,
                'product' => $product,
                'ticket_number' => $number,
                'set_series' => $series ?? null,
                'requesting_authority' => $authority,
                'jurisdiction' => (string) ($this->option('jurisdiction') ?: 'TH'),
                'case_reference' => $caseReference,
                'evidence_reference' => $evidenceReference,
                'evidence_type' => (string) $this->option('evidence-type'),
                'evidence_document_id' => $this->option('evidence-document-id'),
                'evidence_content_hash' => $this->option('evidence-content-hash'),
                'evidence_mime_type' => $this->option('evidence-mime-type'),
                'expiry_at' => $this->option('expiry'),
            ], $actor);
        } catch (GloFreezeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Freeze case %s created (%s) for ticket %s',
            $freeze->freeze_case_id,
            $freeze->status->value,
            $freeze->ticket?->ticket_reference ?? (string) $freeze->ticket_id,
        ));

        return self::SUCCESS;
    }

    private function resolveActor(): ?User
    {
        $email = (string) $this->option('actor');

        if ($email === '') {
            $this->error('--actor=<email> is required (default deny without an operator identity).');

            return null;
        }

        $actor = User::query()->where('email', $email)->first();

        if ($actor === null || ! $actor->isActive()) {
            $this->error('Actor not found or inactive.');

            return null;
        }

        if (! \App\Support\Admin\AdminAccess::allows($actor, \App\Support\Admin\AdminAccess::REQUEST_GLO_FREEZES)) {
            $this->error('Actor lacks the "request glo freezes" permission.');

            return null;
        }

        return $actor;
    }
}
