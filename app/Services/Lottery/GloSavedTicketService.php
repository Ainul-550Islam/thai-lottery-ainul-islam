<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\DrawStatus;
use App\Enums\GloSavedTicketStatus;
use App\Enums\RiskLevel;
use App\Enums\TicketStatus;
use App\Exceptions\GloDealerException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\GloSavedTicket;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * GLO-17 saved tickets.
 *
 * Save rules: ticket exists, belongs to user, draw not finalized, eligible
 * (not cancelled), unique user+ticket. Removal deactivates — history kept.
 */
class GloSavedTicketService
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @return array{saved: GloSavedTicket, replayed: bool}
     */
    public function save(User $user, int $ticketId, ?string $product = null): array
    {
        return $this->db->connection()->transaction(function () use ($user, $ticketId, $product): array {
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->first();

            if ($ticket === null) {
                throw GloDealerException::ticketNotFound();
            }

            if ((int) $ticket->user_id !== (int) $user->getKey()) {
                throw GloDealerException::ticketNotOwned();
            }

            if ($ticket->status === TicketStatus::Cancelled) {
                throw GloDealerException::ticketNotEligible('cancelled ticket');
            }

            if (! in_array($ticket->status, [TicketStatus::Pending, TicketStatus::Confirmed], true)) {
                throw GloDealerException::ticketNotEligible('status '.$ticket->status->value);
            }

            $draw = Draw::query()->find($ticket->draw_id);

            if ($draw === null) {
                throw GloDealerException::ticketNotEligible('draw missing');
            }

            $finalStates = [
                DrawStatus::ResultPublished->value,
                DrawStatus::Completed->value,
                DrawStatus::Cancelled->value,
            ];

            if (in_array($draw->status->value, $finalStates, true)) {
                throw GloDealerException::drawFinalized();
            }

            $activeCount = GloSavedTicket::query()
                ->where('user_id', $user->getKey())
                ->where('status', GloSavedTicketStatus::Active->value)
                ->count();

            $existing = GloSavedTicket::query()
                ->where('user_id', $user->getKey())
                ->where('ticket_id', $ticket->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->isActive()) {
                throw GloDealerException::alreadySaved();
            }

            if ($existing === null) {
                $max = (int) config('glo.saved_tickets.max_per_user', 100);
                if ($activeCount >= $max) {
                    throw GloDealerException::limitReached($max);
                }

                $saved = GloSavedTicket::create([
                    'user_id' => $user->getKey(),
                    'ticket_id' => $ticket->getKey(),
                    'draw_id' => $ticket->draw_id,
                    'product' => $product ?? $this->inferProduct($ticket),
                    // Digit/uuid reference — never int-cast.
                    'ticket_reference' => (string) $ticket->ticket_number,
                    'status' => GloSavedTicketStatus::Active,
                    'saved_at' => now(),
                    'notification_state' => 'pending',
                    'metadata' => ['action' => 'saved'],
                ]);
            } else {
                $existing->status = GloSavedTicketStatus::Active;
                $existing->removed_at = null;
                $existing->saved_at = now();
                $existing->notification_state = 'pending';
                $existing->save();
                $saved = $existing;
            }

            AuditLog::create([
                'user_id' => $user->getKey(),
                'action' => AuditAction::Create,
                'risk_level' => RiskLevel::Low,
                'auditable_type' => GloSavedTicket::class,
                'auditable_id' => $saved->getKey(),
                'description' => 'glo_saved_ticket_created',
                'metadata' => [
                    'action_type' => 'glo_saved_ticket_created',
                    'ticket_id' => $ticket->getKey(),
                    'draw_id' => $ticket->draw_id,
                    'ticket_reference' => $ticket->ticket_number,
                ],
            ]);

            return ['saved' => $saved, 'replayed' => false];
        });
    }

    /**
     * Deactivate — never deletes the row.
     */
    public function remove(User $user, int $ticketId): GloSavedTicket
    {
        return $this->db->connection()->transaction(function () use ($user, $ticketId): GloSavedTicket {
            $saved = GloSavedTicket::query()
                ->where('user_id', $user->getKey())
                ->where('ticket_id', $ticketId)
                ->lockForUpdate()
                ->first();

            if ($saved === null || ! $saved->isActive()) {
                throw GloDealerException::notSaved();
            }

            $saved->status = GloSavedTicketStatus::Inactive;
            $saved->removed_at = now();
            $saved->save();

            AuditLog::create([
                'user_id' => $user->getKey(),
                'action' => AuditAction::Update,
                'risk_level' => RiskLevel::Low,
                'auditable_type' => GloSavedTicket::class,
                'auditable_id' => $saved->getKey(),
                'description' => 'glo_saved_ticket_removed',
                'metadata' => [
                    'action_type' => 'glo_saved_ticket_removed',
                    'ticket_id' => $ticketId,
                    // Evidence retained: row remains.
                    'evidence_retained' => true,
                ],
            ]);

            return $saved;
        });
    }

    /**
     * User's own saved tickets (active by default).
     *
     * @return list<array<string, mixed>>
     */
    public function listOwn(User $user, bool $includeInactive = false, int $limit = 50): array
    {
        $query = GloSavedTicket::query()->where('user_id', $user->getKey());

        if (! $includeInactive) {
            $query->where('status', GloSavedTicketStatus::Active->value);
        }

        return $query
            ->orderByDesc('saved_at')
            ->limit(max(1, min($limit, 100)))
            ->get()
            ->map(static fn (GloSavedTicket $s): array => [
                'ticket_reference' => $s->ticket_reference,
                'product' => $s->product,
                'draw_id' => $s->draw_id,
                'status' => $s->status->value,
                'saved_at' => $s->saved_at?->toIso8601String(),
                'notification_state' => $s->notification_state,
                'notification_sent_at' => $s->notification_sent_at?->toIso8601String(),
            ])
            ->all();
    }

    private function inferProduct(Ticket $ticket): string
    {
        $meta = is_array($ticket->metadata) ? $ticket->metadata : [];
        $product = isset($meta['product']) ? (string) $meta['product'] : 'l6';

        return in_array($product, ['l6', 'n3'], true) ? $product : 'l6';
    }
}
