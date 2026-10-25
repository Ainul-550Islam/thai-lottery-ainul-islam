<?php

declare(strict_types=1);

namespace App\Http\Controllers\Privacy;

use App\Enums\PrivacyRequestType;
use App\Exceptions\Privacy\PrivacyRequestException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Privacy\SubmitPrivacyRequestRequest;
use App\Models\DataSubjectRequest;
use App\Models\PersonalDataExport;
use App\Models\User;
use App\Services\Privacy\PersonalDataExporter;
use App\Services\Privacy\PrivacyRequestService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The player's own privacy-request area (authenticated).
 *
 * EVERY METHOD IS SCOPED BY THE SESSION USER, NEVER BY A PARAMETER. A request is
 * looked up by (reference, user_id) and nothing else; a reference that belongs to
 * somebody else is indistinguishable from one that does not exist. That is why
 * notFound() exists: one answer for both cases.
 *
 * THE EXPORT DOWNLOAD IS SIGNED, OWNER-BOUND, COUNTED AND EXPIRING.
 *  - `signed` on the route stops a hand-edited URL;
 *  - the ownership check stops a forwarded mail;
 *  - download_count/max_downloads and expires_at are enforced here, in one
 *    place, using PersonalDataExport::canBeDownloaded();
 *  - the digest is re-verified before a single byte is streamed, so a truncated
 *    archive is refused rather than delivered.
 */
final class PrivacyRequestStatusController extends Controller
{
    public function __construct(
        private readonly PrivacyRequestService $requests,
        private readonly PersonalDataExporter $exporter,
    ) {}

    /**
     * GET /account/privacy-requests
     */
    public function index(Request $request): View
    {
        $user = $this->user($request);

        $requests = DataSubjectRequest::query()
            ->where('user_id', (int) $user->getKey())
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // A download link is only offered for an archive that is ready, is not
        // expired and still has attempts left. The URL is signed and short-lived;
        // the endpoint re-checks all of it again, because a link in a page is a
        // suggestion, not an authorisation.
        $exports = PersonalDataExport::query()
            ->where('user_id', (int) $user->getKey())
            ->whereIn('request_id', $requests->pluck('id')->all())
            ->orderByDesc('id')
            ->get()
            ->unique('request_id')
            ->keyBy('request_id');

        $exportUrls = [];

        foreach ($exports as $requestId => $export) {
            if (! $export instanceof PersonalDataExport || ! $export->canBeDownloaded()) {
                continue;
            }

            $exportUrls[(int) $requestId] = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'account.privacy-requests.export',
                Carbon::now()->addMinutes(max(5, (int) config('privacy_requests.export.signed_url_ttl_minutes', 60))),
                ['reference' => (string) $requests->firstWhere('id', $requestId)?->reference, 'export' => (int) $export->getKey()],
            );
        }

        return view('account.privacy-requests', [
            'requests' => $requests,
            'types' => PrivacyRequestType::accepted(),
            'enabled' => $this->requests->enabled(),
            'status' => $request->session()->get('privacy_request_status'),
            'exports' => $exports,
            'exportUrls' => $exportUrls,
        ]);
    }

    /**
     * POST /account/privacy-requests
     */
    public function store(SubmitPrivacyRequestRequest $request): RedirectResponse
    {
        $user = $this->user($request);

        if (! $this->requests->enabled()) {
            return redirect()
                ->route('account.privacy-requests.index')
                ->with('privacy_request_status', ['state' => 'disabled']);
        }

        try {
            $this->requests->submitAuthenticated(
                user: $user,
                type: $request->requestType(),
                details: $request->details(),
                ip: $request->ip(),
            );
        } catch (PrivacyRequestException $exception) {
            $key = $exception->errorCode() === PrivacyRequestException::CODE_TOO_MANY_OPEN
                ? 'cannot_submit_open_limit'
                : 'store_failed';

            return redirect()
                ->route('account.privacy-requests.index')
                ->with('privacy_request_status', ['state' => $key]);
        }

        return redirect()
            ->route('account.privacy-requests.index')
            ->with('privacy_request_status', ['state' => 'created']);
    }

    /**
     * POST /account/privacy-requests/{reference}/cancel
     */
    public function cancel(Request $request, string $reference): RedirectResponse
    {
        $user = $this->user($request);
        $subject = $this->findOwned($user, $reference);

        if (! $subject instanceof DataSubjectRequest) {
            return redirect()
                ->route('account.privacy-requests.index')
                ->with('privacy_request_status', ['state' => 'not_owner']);
        }

        try {
            $this->requests->cancel($subject);
        } catch (PrivacyRequestException) {
            return redirect()
                ->route('account.privacy-requests.index')
                ->with('privacy_request_status', ['state' => 'cannot_cancel']);
        }

        return redirect()
            ->route('account.privacy-requests.index')
            ->with('privacy_request_status', ['state' => 'cancelled']);
    }

    /**
     * GET /account/privacy-requests/{reference}/export
     *
     * Signed, owner-only, counted, expiring, digest-verified.
     */
    public function export(Request $request, string $reference, string $exportId): StreamedResponse|JsonResponse
    {
        $user = $this->user($request);
        $subject = $this->findOwned($user, $reference);

        if (! $subject instanceof DataSubjectRequest) {
            return $this->notFound();
        }

        $export = PersonalDataExport::query()
            ->where('request_id', (int) $subject->getKey())
            ->where('user_id', (int) $user->getKey())
            ->whereKey((int) $exportId)
            ->first();

        if (! $export instanceof PersonalDataExport) {
            return $this->notFound();
        }

        if ($export->isExpired()) {
            return response()->json([
                'success' => false,
                'state' => 'EXPIRED',
                'message' => (string) trans('privacy_requests.account.download_expired'),
            ], 410);
        }

        if (! $export->isReady()) {
            return response()->json([
                'success' => false,
                'state' => 'NOT_READY',
                'message' => (string) trans('privacy_requests.account.download_unavailable'),
            ], 409);
        }

        if (! $export->hasDownloadsLeft()) {
            return response()->json([
                'success' => false,
                'state' => 'LIMIT_REACHED',
                'message' => (string) trans('privacy_requests.account.download_limit_reached'),
            ], 429);
        }

        if (! $this->exporter->verifyDigest($export)) {
            // A missing or altered archive is NOT a download: say so, and let
            // the operator see that the row no longer matches its file.
            return response()->json([
                'success' => false,
                'state' => 'UNAVAILABLE',
                'message' => (string) trans('privacy_requests.account.download_unavailable'),
            ], 404);
        }

        $disk = Storage::disk($export->disk);
        $stream = $disk->readStream($export->path);

        if (! is_resource($stream)) {
            return response()->json([
                'success' => false,
                'state' => 'UNAVAILABLE',
                'message' => (string) trans('privacy_requests.account.download_unavailable'),
            ], 404);
        }

        // Counted before the stream is handed to the client: a failure midway
        // still consumed one of the requester's attempts, which is the honest
        // reading of "you asked for a copy".
        $export->download_count = (int) $export->download_count + 1;
        $export->last_downloaded_at = Carbon::now();
        $export->save();

        $filename = sprintf('personal-data-%s.zip', (string) $subject->reference);

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, $filename, [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function findOwned(User $user, string $reference): ?DataSubjectRequest
    {
        return DataSubjectRequest::query()
            ->where('reference', strtoupper(trim($reference)))
            ->where('user_id', (int) $user->getKey())
            ->first();
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            // The route is behind `auth`; this branch exists so the method's
            // return type is honest without a nullable dance at every call.
            abort(403);
        }

        return $user;
    }

    /**
     * One answer for "does not exist" and "is not yours".
     */
    private function notFound(): JsonResponse|Response
    {
        return response()->json([
            'success' => false,
            'state' => 'NOT_FOUND',
            'message' => (string) trans('privacy_requests.account.not_owner'),
        ], 404);
    }
}
