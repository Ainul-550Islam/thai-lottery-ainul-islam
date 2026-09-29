<?php

declare(strict_types=1);

namespace App\Http\Controllers\Verification;

use App\Models\AccountVerification;
use App\Models\AccountVerificationDocument;
use App\Models\User;
use App\Services\Account\AccountVerificationDocumentService;
use App\Services\Verification\AccountVerificationService;
use App\Services\Verification\DocumentStorageService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/*
 * PROMPT 3 — the member Account Verify controller.
 *
 * THIN BY CONTRACT: authorize (policy), validate (Request), delegate
 * (Service), answer. No storage logic, no state-machine logic, no
 * fee/grade arithmetic — nothing but orchestration.
 *
 * SELF-SCOPING: the subject is ALWAYS $request->user(). A client-
 * supplied user_id / account_id / verification_id is never read.
 */
final class AccountVerificationController
{
    public function __construct(
        private readonly AccountVerificationService $verification,
        private readonly AccountVerificationDocumentService $documents,
        private readonly DocumentStorageService $storage,
    ) {
    }

    /**
     * The authenticated Account Verify page: account summary, status,
     * the submission form and the member-safe history.
     */
    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('account-verification.index', [
            'meta' => [
                'title' => (string) trans('account_services.verification_meta_title'),
                'description' => (string) trans('account_services.verification_meta_description'),
            ],
            'account' => $this->verification->accountInfo($user),
            'documents' => $this->verification->documentsFor($user),
            'history' => $this->verification->historyFor($user),
            'canSubmit' => ! $this->verification->hasOpenRequest($user),
            'documentTypes' => array_map(
                static fn ($case): string => $case->value,
                \App\Enums\VerificationDocumentType::configured(),
            ),
            'countryCodes' => (array) config('account_verification.phone.country_codes', ['+66']),
            'defaultCountryCode' => (string) config('account_verification.phone.default_country_code', '+66'),
            'maxFileKb' => (int) config('account_verification.uploads.max_file_kb', 10240),
            'requireBack' => (bool) config('account_verification.uploads.require_back_document', false),
        ]);
    }

    /**
     * Submit a verification package. Every service-level policy
     * violation lands on the 'document' key — the established error
     * surface this page's tests already assert.
     */
    public function submit(\App\Http\Requests\Verification\SubmitAccountVerificationRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $this->verification->submit($user, [
                'country_code' => $request->input('country_code'),
                'mobile' => $request->input('mobile'),
                'document_type' => (string) $request->input('document_type'),
                'document_number' => $request->input('document_number'),
                'document' => $request->file('document'),
                'document_back' => $request->file('document_back'),
            ], $request->ip());
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->withErrors(['document' => $exception->getMessage()]);
        }

        return redirect()
            ->route('account.verification')
            ->with('success', (string) trans('account_services.verification_success'));
    }

    /**
     * Owner-authorized document download — never a public /storage
     * path, always a streaming response through authorization.
     */
    public function download(Request $request, int $documentId): BinaryFileResponse|Response
    {
        /** @var User $user */
        $user = $request->user();

        $document = AccountVerificationDocument::query()
            ->where('user_id', $user->id)
            ->whereKey($documentId)
            ->first();

        if ($document === null) {
            abort(404);
        }

        try {
            $payload = $this->storage->readForAuthorized($document, $user, false);
        } catch (InvalidArgumentException) {
            abort(403);
        }

        return response($payload['contents'], 200, [
            'Content-Type' => $payload['mime'],
            'Content-Disposition' => 'attachment; filename="'.$payload['name'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Reviewer decision on a submission aggregate (policy-authorized;
     * members can never reach this — see AccountVerificationPolicy).
     */
    public function decide(Request $request, int $verificationId): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $verification = AccountVerification::query()->whereKey($verificationId)->first();

        if ($verification === null) {
            abort(404);
        }

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approve,reject,under_review'],
            'reason' => ['nullable', 'string', 'max:'.(int) config('account_verification.review.max_reason_length', 500)],
        ]);

        // The policy is the wall: owner-scoped members fail here.
        if (! Gate::allows('decide', $verification)) {
            abort(403);
        }

        try {
            match ($validated['decision']) {
                'approve' => $this->verification->review($verification, $user, true, $validated['reason'] ?? null),
                'reject' => $this->verification->review($verification, $user, false, $validated['reason'] ?? null),
                'under_review' => $this->verification->markUnderReview($verification, $user),
            };
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return back()->with('status', __('account_services.verification_decision_recorded'));
    }
}
