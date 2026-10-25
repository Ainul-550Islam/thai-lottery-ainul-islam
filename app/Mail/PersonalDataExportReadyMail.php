<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\QueueName;
use App\Models\DataSubjectRequest;
use App\Models\PersonalDataExport;
use App\Support\Privacy\PrivacyMailCopy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * "Your archive is ready" — sent ONLY after staff released it.
 *
 * THE LINK IS SIGNED, TEMPORARY AND OWNER-BOUND. It names the request's own
 * reference, the endpoint re-checks that the authenticated view is the owner of
 * that reference, the archive's expiry and its remaining download count. A
 * forwarded mail therefore gets a 403/404 rather than somebody else's data.
 *
 * THE ARCHIVE ITSELF IS NEVER ATTACHED. Attaching it would put a copy of
 * everything the platform holds about a person into mailboxes, backups and
 * forward chains. The mail says where to fetch it and for how long.
 *
 * THE DOWNLOAD LIMIT IS STATED. "Three times" is a real, enforced number; a
 * requester told nothing would treat a refusal as a fault.
 */
final class PersonalDataExportReadyMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly DataSubjectRequest $request,
        public readonly PersonalDataExport $export,
    ) {
        $this->afterCommit();
        $this->onQueue(QueueName::Notifications->value);
        $this->locale($request->locale);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: PrivacyMailCopy::for('mail.export_ready.subject')
                ->locate($this->request->locale)
                ->reference($this->request->reference)
                ->render(),
            from: PrivacyMailCopy::fromAddress(),
        );
    }

    public function content(): Content
    {
        $expiresAt = $this->export->expires_at instanceof Carbon
            ? $this->export->expires_at->copy()
            : Carbon::now()->addDay();

        $ttl = max(5, (int) config('privacy_requests.export.signed_url_ttl_minutes', 60));
        $urlExpiry = Carbon::now()->addMinutes($ttl);

        return new Content(
            view: 'emails.privacy.export-ready',
            with: [
                'reference' => $this->request->reference,
                'downloadUrl' => URL::temporarySignedRoute(
                    'account.privacy-requests.export',
                    $urlExpiry,
                    ['reference' => $this->request->reference, 'export' => (int) $this->export->getKey()],
                ),
                'linkExpiresAt' => $urlExpiry->format('Y-m-d H:i'),
                'archiveExpiresAt' => $expiresAt->format('Y-m-d H:i'),
                'maxDownloads' => max(1, (int) $this->export->max_downloads),
                'locale' => $this->request->locale,
            ],
        );
    }
}
