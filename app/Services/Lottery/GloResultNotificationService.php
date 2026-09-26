<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\DTOs\Notification\NotificationMessageData;
use App\Enums\AuditAction;
use App\Enums\DrawStatus;
use App\Enums\GloSavedTicketStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Enums\NotificationPriority;
use App\Enums\RiskLevel;
use App\Exceptions\GloDealerException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloNotificationDelivery;
use App\Models\GloPrizePaymentHold;
use App\Models\GloSavedTicket;
use App\Models\GloTicket;
use App\Models\GloTicketFreeze;
use App\Services\Notification\NotificationDispatchService;
use Illuminate\Database\DatabaseManager;

/**
 * GLO-17 result notification for saved tickets.
 *
 * Trigger: verified result on a finalized draw → active saved tickets for
 * that draw → per-user notification via the SHARED NotificationDispatchService
 * (message_fingerprint unique = no duplicate for the same result version).
 *
 * Delivery rows (glo_notification_deliveries) use delivery_key unique
 * (user|ticket|result_version|type). Push provider is honestly recorded
 * (not_configured) — we never claim push delivery succeeded.
 *
 * Content is public-safe: draw, ticket ref, category, prize when calculated,
 * hold/frozen flag without private legal detail.
 */
class GloResultNotificationService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly NotificationDispatchService $dispatch,
        private readonly GloTicketChecker $checker,
    ) {}

    /**
     * Process all active saved tickets for a draw that has a verified result.
     *
     * @return array{processed: int, notified: int, replayed: int, skipped: int, result_version: string, failures: list<string>}
     */
    public function processDraw(Draw $draw, bool $dryRun = false): array
    {
        $drawId = (int) $draw->getKey();

        if (! in_array($draw->status, [DrawStatus::ResultPublished, DrawStatus::Completed], true)) {
            throw GloDealerException::resultNotVerified();
        }

        $resultVersion = $this->resultVersion($drawId);

        $tickets = GloSavedTicket::query()
            ->where('draw_id', $drawId)
            ->where('status', GloSavedTicketStatus::Active->value)
            ->orderBy('id')
            ->get();

        $processed = 0;
        $notified = 0;
        $replayed = 0;
        $skipped = 0;
        $failures = [];

        foreach ($tickets as $saved) {
            $processed++;

            try {
                $outcome = $this->notifyOne($saved, $resultVersion, $dryRun);

                if ($outcome === 'notified') {
                    $notified++;
                } elseif ($outcome === 'replayed') {
                    $replayed++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failures[] = $saved->getKey().':'.$e->getMessage();
            }
        }

        return [
            'processed' => $processed,
            'notified' => $notified,
            'replayed' => $replayed,
            'skipped' => $skipped,
            'result_version' => $resultVersion,
            'failures' => $failures,
        ];
    }

    /**
     * Idempotent notify for one saved ticket.
     *
     * @return string notified | replayed | suppressed | skipped
     */
    public function notifyOne(GloSavedTicket $saved, string $resultVersion, bool $dryRun = false): string
    {
        $type = (string) config('glo.notifications.event_result', 'glo_result_available');
        $deliveryKey = GloNotificationDelivery::buildKey(
            (int) $saved->user_id,
            (int) $saved->ticket_id,
            $resultVersion,
            $type,
        );

        $existing = GloNotificationDelivery::query()
            ->where('delivery_key', $deliveryKey)
            ->first();

        // Settled deliveries (queued/sent/not_configured) never re-fire.
        // Failed deliveries are retryable: same unique delivery_key, the row
        // advances on the next worker pass (no duplicate notification rows).
        if ($existing !== null && $existing->delivery_state !== 'failed') {
            return 'replayed';
        }

        if ($dryRun) {
            return 'notified';
        }

        return $this->db->connection()->transaction(function () use ($saved, $resultVersion, $type, $deliveryKey): string {
            $locked = GloNotificationDelivery::query()
                ->where('delivery_key', $deliveryKey)
                ->lockForUpdate()
                ->first();

            if ($locked !== null && $locked->delivery_state !== 'failed') {
                return 'replayed';
            }

            $payload = $this->buildPayload($saved);

            $channel = NotificationChannel::from(
                (string) config('glo.notifications.default_channel', 'in_app')
            );

            $result = $this->dispatch->pronounce(
                (int) $saved->user_id,
                NotificationMessageData::fromInput([
                    'user_id' => (int) $saved->user_id,
                    'event_type' => NotificationEventType::GloResultAvailable,
                    'channel' => $channel,
                    'priority' => NotificationPriority::Normal,
                    'subject' => 'GLO draw result for your saved ticket',
                    'body' => $payload['summary'],
                    'expires_at' => now()->addDays(60),
                ]),
            );

            $notification = $result['notification'];
            $pushProvider = (string) config('glo.notifications.push_provider', 'not_configured');
            $deliveryState = $notification === null ? 'failed' : 'queued';

            if ($result['suppressed']) {
                $deliveryState = 'failed';
            }

            $provider = $channel === NotificationChannel::Push && $pushProvider === 'not_configured'
                ? 'not_configured'
                : ($channel === NotificationChannel::InApp ? 'database' : $pushProvider);

            if ($provider === 'not_configured' && $channel === NotificationChannel::Push) {
                $deliveryState = 'not_configured';
            }

            $deliveryAttributes = [
                'notification_id' => $notification?->getKey(),
                'user_id' => (int) $saved->user_id,
                'ticket_id' => (int) $saved->ticket_id,
                'draw_id' => (int) $saved->draw_id,
                'result_version' => $resultVersion,
                'notification_type' => $type,
                'delivery_state' => $deliveryState,
                'channel' => $channel->value,
                'provider' => $provider,
                'provider_reference' => null,
                'failure_reason' => $result['suppressed'] ? $result['reason'] : null,
                'payload_summary' => $payload,
                'queued_at' => now(),
                'sent_at' => $deliveryState === 'queued' ? now() : null,
            ];

            if ($locked instanceof GloNotificationDelivery) {
                $locked->fill($deliveryAttributes);
                $locked->save();
            } else {
                GloNotificationDelivery::create(array_merge(
                    ['delivery_key' => $deliveryKey],
                    $deliveryAttributes,
                ));
            }

            if ($notification !== null && ! $result['suppressed']) {
                $saved->notification_state = 'notified';
                $saved->notification_sent_at = now();
                $saved->result_version = $resultVersion;
                $saved->save();
            }

            AuditLog::create([
                'user_id' => (int) $saved->user_id,
                'action' => AuditAction::Update,
                'risk_level' => RiskLevel::Medium,
                'auditable_type' => GloSavedTicket::class,
                'auditable_id' => $saved->getKey(),
                'description' => 'glo_result_notification_queued',
                'metadata' => [
                    'action_type' => 'glo_result_notification_queued',
                    'delivery_key' => $deliveryKey,
                    'result_version' => $resultVersion,
                    'delivery_state' => $deliveryState,
                    'provider' => $provider,
                    'ticket_reference' => $saved->ticket_reference,
                ],
            ]);

            if ($result['suppressed']) {
                return 'suppressed';
            }

            return $notification === null ? 'skipped' : 'notified';
        });
    }

    /**
     * Public-safe notification body fields (no other users, no legal detail).
     *
     * @return array<string, mixed>
     */
    public function buildPayload(GloSavedTicket $saved): array
    {
        $check = null;
        $ticketNumber = $this->sixDigitFromReference($saved->ticket_reference);

        if ($ticketNumber !== null) {
            try {
                $check = $this->checker->check((int) $saved->draw_id, $ticketNumber);
            } catch (\Throwable) {
                $check = null;
            }
        }

        $won = $check['won'] ?? false;
        $total = $check['total_prize'] ?? '0.00';

        $hold = false;
        $holdNotice = null;

        if ($ticketNumber !== null) {
            $gloTicket = GloTicket::query()
                ->where('draw_id', $saved->draw_id)
                ->where('ticket_number', $ticketNumber)
                ->first();

            if ($gloTicket !== null) {
                $activeFreeze = GloTicketFreeze::query()
                    ->where('ticket_id', $gloTicket->getKey())
                    ->where('status', 'frozen')
                    ->exists();

                $paymentHold = GloPrizePaymentHold::query()
                    ->where('ticket_id', $gloTicket->getKey())
                    ->where('status', 'active')
                    ->exists();

                if ($activeFreeze || $paymentHold) {
                    $hold = true;
                    $holdNotice = 'Payment for this prize is currently on hold; a claim cannot proceed right now.';
                }
            }
        }

        $draw = Draw::query()->find($saved->draw_id);
        $summary = sprintf(
            'Draw %s: ticket %s %s%s',
            $draw?->draw_number ?? (string) $saved->draw_id,
            $saved->ticket_reference,
            $won ? 'matched a winning category' : 'did not match a winning number',
            $won ? ' (prize '.($total !== '0.00' ? $total.' THB' : 'pending official ladder').')' : '',
        );

        if ($hold) {
            $summary .= ' — payment is currently on hold.';
        }

        return [
            'summary' => $summary,
            'draw_id' => (int) $saved->draw_id,
            'draw_number' => $draw?->draw_number,
            'draw_date' => $draw?->scheduled_at?->toDateString(),
            'ticket_reference' => $saved->ticket_reference,
            'product' => $saved->product,
            'won' => $won,
            'winning_categories' => $check['matches'] ?? [],
            'prize_amount' => $won ? $total : '0.00',
            'claim_status' => $won ? 'eligible_to_claim_offline' : 'not_a_winner',
            'payment_hold' => $hold,
            'hold_notice' => $holdNotice,
            'source_state' => (string) config('glo.result_experience.source_labels.reconciled', 'INTERNAL_RECONCILED'),
        ];
    }

    /**
     * Stable result version from draw_results metadata import fingerprint.
     */
    public function resultVersion(int $drawId): string
    {
        $result = DrawResult::query()->where('draw_id', $drawId)->first();

        if ($result === null) {
            throw GloDealerException::resultNotVerified();
        }

        $meta = is_array($result->metadata) ? $result->metadata : [];
        $lane = is_array($meta['glo'] ?? null) ? $meta['glo'] : [];

        if (! empty($lane['import_fingerprint'])) {
            return (string) $lane['import_fingerprint'];
        }

        if (! empty($lane['n3_fingerprint'])) {
            return (string) $lane['n3_fingerprint'];
        }

        return 'pub-'.sha1(
            implode('|', [
                (string) $drawId,
                (string) $result->first_prize,
                (string) ($result->published_at?->getTimestamp() ?? $result->getKey()),
            ])
        );
    }

    private function sixDigitFromReference(string $reference): ?string
    {
        if (preg_match('/(\d{6})/', $reference, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
