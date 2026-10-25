<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueName;
use App\Mail\PrivacySlaDigestMail;
use App\Models\DataSubjectRequest;
use App\Models\DataSubjectRequestEvent;
use App\Services\Privacy\PrivacyRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The daily compliance-desk digest.
 *
 * WHY A DIGEST AND NOT ONE MAIL PER REQUEST. A desk that receives one mail per
 * request stops reading them. This job sends at most one message per day,
 * naming the requests that are due soon and the requests that are already
 * overdue, and it records in each request's timeline that the reminder went out.
 *
 * IDEMPOTENCY IS A ROW, NOT A PROMISE. "Once per day per request" is decided by
 * looking for an sla_reminder event created since midnight: a re-run of the
 * command, a retried job or a double-dispatch therefore produces no second mail.
 *
 * WHAT IT WILL NOT DO. With no compliance address configured it sends nothing
 * and says so in the log - a digest addressed to a mailbox nobody owns is worse
 * than no digest. And it never changes a request's state to make it fit the
 * report: overdue is computed, not stamped, except for the one durable
 * 'overdue' event that marks the first time the desk was told.
 */
final class SendPrivacySlaRemindersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue(QueueName::Notifications->value);
    }

    public function uniqueId(): string
    {
        return 'privacy-sla-digest:'.Carbon::now()->toDateString();
    }

    /**
     * @return array{overdue: int, due_soon: int, mailed: bool}
     */
    public function handle(PrivacyRequestService $requests): array
    {
        $overdue = DataSubjectRequest::query()->overdue()->orderBy('due_at')->get();
        $dueSoon = DataSubjectRequest::query()->dueSoon()->orderBy('due_at')->get();

        $fresh = [];
        $alreadyInformed = 0;

        foreach ($overdue->concat($dueSoon) as $request) {
            if (! $request instanceof DataSubjectRequest) {
                continue;
            }

            if ($this->alreadyRemindedToday($request)) {
                $alreadyInformed++;

                continue;
            }

            if ($request->isOverdue() && ! $this->alreadyMarkedOverdue($request)) {
                $requests->recordEvent(
                    $request,
                    DataSubjectRequestEvent::EVENT_OVERDUE,
                    DataSubjectRequestEvent::ACTOR_SYSTEM,
                    null,
                    'due_at:'.$request->due_at?->toIso8601String(),
                );
            }

            $fresh[] = $request;
        }

        $mailed = false;

        if ($fresh !== []) {
            $mailed = $this->sendDigest($overdue->count(), $dueSoon->count(), $fresh);

            if ($mailed) {
                foreach ($fresh as $request) {
                    $requests->recordEvent(
                        $request,
                        DataSubjectRequestEvent::EVENT_SLA_REMINDER,
                        DataSubjectRequestEvent::ACTOR_SYSTEM,
                        null,
                        'digest:'.Carbon::now()->toDateString(),
                    );
                }
            }
        }

        Log::info('privacy_sla_digest', [
            'overdue' => $overdue->count(),
            'due_soon' => $dueSoon->count(),
            'reminded' => count($fresh),
            'skipped_today' => $alreadyInformed,
            'mailed' => $mailed,
        ]);

        return [
            'overdue' => $overdue->count(),
            'due_soon' => $dueSoon->count(),
            'mailed' => $mailed,
        ];
    }

    private function alreadyRemindedToday(DataSubjectRequest $request): bool
    {
        return DataSubjectRequestEvent::query()
            ->where('request_id', (int) $request->getKey())
            ->where('event', DataSubjectRequestEvent::EVENT_SLA_REMINDER)
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->exists();
    }

    private function alreadyMarkedOverdue(DataSubjectRequest $request): bool
    {
        return DataSubjectRequestEvent::query()
            ->where('request_id', (int) $request->getKey())
            ->where('event', DataSubjectRequestEvent::EVENT_OVERDUE)
            ->exists();
    }

    /**
     * One plain-text digest to the compliance desk.
     *
     * @param  list<DataSubjectRequest>  $fresh
     */
    private function sendDigest(int $overdueCount, int $dueSoonCount, array $fresh): bool
    {
        $destination = config('privacy_requests.compliance_email');

        if (! is_string($destination) || trim($destination) === '') {
            Log::warning('privacy_sla_digest_not_configured', [
                'reason' => 'compliance_email_absent',
            ]);

            return false;
        }

        $lines = [];

        foreach ($fresh as $request) {
            $key = $request->isOverdue()
                ? 'privacy_requests.sla.line_overdue'
                : 'privacy_requests.sla.line_due_soon';

            $lines[] = (string) trans($key, [
                'reference' => $request->reference,
                'type' => (string) trans($request->type->labelKey(), [], 'en'),
                'due' => $request->due_at?->format('Y-m-d') ?? '',
            ], 'en');
        }

        try {
            Mail::to(trim($destination))->queue(new PrivacySlaDigestMail(
                overdueCount: $overdueCount,
                dueSoonCount: $dueSoonCount,
                lines: $lines,
            ));

            return true;
        } catch (Throwable $exception) {
            Log::error('privacy_sla_digest_failed', ['exception' => $exception::class]);

            return false;
        }
    }
}
