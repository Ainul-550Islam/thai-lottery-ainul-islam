<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AccountVerificationDocument;
use App\Models\User;
use App\Services\Account\AccountVerificationDocumentService;
use App\Services\Account\AccountVerificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Authenticated Account Verification page.
 *
 * Ownership is always $request->user() — never a client-supplied id.
 * Status / approved / phone_verified are server-derived only.
 */
final class AccountVerificationController
{
    public function __construct(
        private readonly AccountVerificationService $verification,
        private readonly AccountVerificationDocumentService $documents,
    ) {
    }

    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('account.verification', [
            'meta' => [
                'title' => (string) trans('account_services.verification_meta_title'),
                'description' => (string) trans('account_services.verification_meta_description'),
            ],
            'account' => $this->verification->accountInfo($user),
            'documents' => $this->verification->documentsFor($user),
            'canSubmit' => ! $this->verification->hasOpenRequest($user),
            'documentTypes' => (array) config('account.verification.document_types', []),
            'maxFileKb' => (int) config('account.verification.max_file_kb', 10240),
            'defaultCountryCode' => (string) config('account.verification.phone.default_country_code', '+66'),
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Client status / approved / phone_verified keys are intentionally
        // NOT in the validation rules — they are never read.
        $validated = $request->validate([
            'country_code' => ['nullable', 'string', 'max:8'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'document_type' => ['required', 'string', 'in:'.implode(',', (array) config('account.verification.document_types', []))],
            'document_number' => ['nullable', 'string', 'max:100'],
            'document' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'max:'.(int) config('account.verification.max_file_kb', 10240),
            ],
            'document_back' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'max:'.(int) config('account.verification.max_file_kb', 10240),
            ],
        ]);

        try {
            $this->verification->submit($user, [
                'country_code' => $validated['country_code'] ?? null,
                'mobile' => $validated['mobile'] ?? null,
                'document_type' => (string) $validated['document_type'],
                'document_number' => $validated['document_number'] ?? null,
                'document' => $request->file('document'),
                'document_back' => $request->file('document_back'),
            ], $request->ip());
        } catch (InvalidArgumentException $e) {
            return back()
                ->withInput()
                ->withErrors(['document' => $e->getMessage()]);
        }

        return redirect()
            ->route('account.verification')
            ->with('success', (string) trans('account_services.verification_success'));
    }

    /**
     * Owner-authorized document download through the controller —
     * never a public /storage path.
     */
    public function download(Request $request, int $documentId): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response
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
            $payload = $this->documents->readForAuthorized($document, $user, false);
        } catch (InvalidArgumentException) {
            abort(403);
        }

        return response($payload['contents'], 200, [
            'Content-Type' => $payload['mime'],
            'Content-Disposition' => 'attachment; filename="'.$payload['name'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
