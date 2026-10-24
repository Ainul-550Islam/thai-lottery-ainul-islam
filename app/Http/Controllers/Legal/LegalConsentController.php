<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legal;

use App\Http\Requests\Legal\LegalConsentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Models\UserConsent;
use App\Services\Legal\ConsentService;
use App\Services\Legal\LegalDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class LegalConsentController
{
    public function __construct(
        private readonly ConsentService $consents,
        private readonly LegalDocumentService $documents,
    ) {}

    public function show(Request $request): View
    {
        $locale = $this->locale();

        $terms = $this->documents->currentData('terms', $locale);
        $privacy = $this->documents->currentData('privacy', $locale);
        $available = ($terms['status'] ?? null) === 'AVAILABLE'
            && ($privacy['status'] ?? null) === 'AVAILABLE'
            && is_string($terms['version'] ?? null)
            && is_string($terms['content_hash'] ?? null)
            && is_array($terms['sections'] ?? null)
            && $terms['sections'] !== []
            && is_string($privacy['version'] ?? null)
            && is_string($privacy['content_hash'] ?? null)
            && is_array($privacy['sections'] ?? null)
            && $privacy['sections'] !== [];

        return view('legal.consent', [
            'terms' => $terms,
            'privacy' => $privacy,
            'available' => $available,
            'accepted' => $request->user() instanceof User
                ? $this->consents->hasCurrentAcceptance($request->user(), $locale)
                : false,
        ]);
    }

    public function store(LegalConsentRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->consents->acceptCurrentDocuments($user, $request, 'consent_page');

        return redirect()
            ->route('player.dashboard')
            ->with('status', 'Your current Terms acceptance and Privacy Notice acknowledgement were recorded.');
    }

    public function currentApi(Request $request): JsonResponse
    {
        return ApiResponse::success(
            data: [
                'documents' => $this->consents->currentDocuments($this->locale()),
            ],
            message: 'Current legal documents.',
        );
    }

    public function historyApi(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $items = UserConsent::query()
            ->where('user_id', (int) $user->getKey())
            ->orderByDesc('consented_at')
            ->get([
                'id',
                'document_type',
                'document_version',
                'document_hash',
                'action',
                'consented_at',
                'locale',
                'source',
            ]);

        return ApiResponse::success(
            data: ['items' => $items->toArray()],
            message: 'Your legal acknowledgement history.',
        );
    }

    public function storeApi(LegalConsentRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $recorded = $this->consents->acceptCurrentDocuments($user, $request, 'api');

        return ApiResponse::success(
            data: [
                'terms' => $this->serializeConsent($recorded['terms']),
                'privacy' => $this->serializeConsent($recorded['privacy']),
            ],
            message: 'Current legal acknowledgements recorded.',
        );
    }

    /**
     * The public acceptance URL is deliberately NOT an acceptance recorder.
     *
     * WHY THIS EXISTS
     * /api/v1/public/terms/accept used to be routed straight at storeApi() behind
     * auth:sanctum, so an unauthenticated caller got a 401/422 and an authenticated
     * one silently wrote a consent record through a *public* URL. Consent evidence
     * has to come from the authenticated web flow (POST /legal/consent), where the
     * account, the rendered document version and the content hash are all known;
     * accepting it from an anonymous JSON call would fabricate the very record the
     * compliance rules require to be genuine.
     *
     * The endpoint therefore answers NOT_CONFIGURED - honestly, with a 501 - and
     * records nothing.
     */
    public function acceptNotConfiguredApi(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'state' => 'NOT_CONFIGURED',
            'message' => 'Legal acknowledgement is recorded through the authenticated consent flow, not this public endpoint.',
        ], 501);
    }

    /** @return array<string, mixed> */
    private function serializeConsent(UserConsent $consent): array
    {
        return [
            'document_type' => $consent->document_type,
            'document_version' => $consent->document_version,
            'document_hash' => $consent->document_hash,
            'action' => $consent->action,
            'consented_at' => $consent->consented_at?->toIso8601String(),
            'locale' => $consent->locale,
        ];
    }

    private function locale(): string
    {
        $locale = (string) app()->getLocale();

        return in_array($locale, ['en', 'th'], true) ? $locale : (string) config('app.fallback_locale', 'en');
    }
}
