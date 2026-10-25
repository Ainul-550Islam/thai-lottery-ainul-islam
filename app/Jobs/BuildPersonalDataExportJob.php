<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PrivacyRequestStatus;
use App\Enums\QueueName;
use App\Exceptions\Privacy\PrivacyRequestException;
use App\Models\DataSubjectRequest;
use App\Models\DataSubjectRequestEvent;
use App\Services\Privacy\PersonalDataExporter;
use App\Services\Privacy\PrivacyRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds a personal-data archive off the request cycle.
 *
 * =========================================================================
 * BUILDING IS NOT DELIVERING
 * =========================================================================
 * Success means the ZIP exists on the private disk with a recorded digest. It
 * does NOT mail the requester: an operator reviews the archive's metadata first
 * and only "Release export" in the panel queues the mail. That separation is the
 * whole point - a machine that both assembles and dispatches a copy of
 * everything the platform holds about a person has no check on it.
 *
 * =========================================================================
 * UNIQUE PER REQUEST
 * =========================================================================
 * ShouldBeUnique keyed on the request id: two operators pressing "Build export"
 * at the same moment produce one build, not two racing writes to the same row.
 *
 * =========================================================================
 * HONEST FAILURE
 * =========================================================================
 * The exporter already marks the export row failed and appends an
 * export_failed event. This job logs the class of the failure (never its
 * message, which can carry SQL or a path), and lets the queue retry with a
 * backoff. If it exhausts its attempts, failed() writes one more event so the
 * timeline ends with a fact rather than with silence.
 */
final class BuildPersonalDataExportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Stop a duplicate build being queued again for an hour. */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $requestId,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return 'privacy-export:'.$this->requestId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(PersonalDataExporter $exporter, PrivacyRequestService $requests): void
    {
        $request = DataSubjectRequest::query()->find($this->requestId);

        if (! $request instanceof DataSubjectRequest) {
            return;
        }

        if ($request->status->isTerminal()) {
            return;
        }

        if (! $request->isVerified()) {
            // An unverified request has no proven owner, so there is no lawful
            // "this person's data" to assemble.
            return;
        }

        $export = $exporter->buildFor($request);

        $request->refresh();

        // The archive exists; the request is now in progress and waiting on a
        // human to review and release it.
        if ($request->status === PrivacyRequestStatus::InReview
            && $request->status->canTransitionTo(PrivacyRequestStatus::InProgress)) {
            $requests->transition(
                $request,
                PrivacyRequestStatus::InProgress,
                DataSubjectRequestEvent::ACTOR_SYSTEM,
                null,
                'export_built_awaiting_release',
                false,
            );
        }

        Log::info('privacy_export_built', [
            'reference' => $request->reference,
            'export_id' => (int) $export->getKey(),
            'status' => $export->status,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $request = DataSubjectRequest::query()->find($this->requestId);

        if (! $request instanceof DataSubjectRequest) {
            return;
        }

        try {
            app(PrivacyRequestService::class)->recordEvent(
                $request,
                DataSubjectRequestEvent::EVENT_EXPORT_FAILED,
                DataSubjectRequestEvent::ACTOR_SYSTEM,
                null,
                'job_exhausted:'.$exception::class,
            );
        } catch (Throwable) {
            // Never let the failure handler throw: the queue is already in a
            // bad state, and an exception here would hide the original one.
        }

        Log::error('privacy_export_job_failed', [
            'reference' => $request->reference,
            'exception' => $exception::class,
            'retryable' => $exception instanceof PrivacyRequestException ? $exception->errorCode() : 'build_failure',
        ]);
    }
}
