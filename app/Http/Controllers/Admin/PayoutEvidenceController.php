<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\FinancialException;
use App\Exceptions\PayoutTransferException;
use App\Http\Requests\Admin\UploadPayoutEvidenceRequest;
use App\Services\Payment\PayoutEvidenceService;
use Illuminate\Http\Request;

/** Authenticated attachment delivery only. No public path, signed URL, inline rendering or controller transaction. */
final class PayoutEvidenceController
{
    public function index(Request $request, int $payoutId)
    {
        try {
            $items = app(PayoutEvidenceService::class)->listing($request->user(), $payoutId);
        } catch (FinancialException|PayoutTransferException) {
            return $this->refused();
        }

        return response()->json(['data' => $items])->header('Cache-Control', 'private, no-store');
    }

    public function store(UploadPayoutEvidenceRequest $request, int $payoutId)
    {
        try {
            $record = app(PayoutEvidenceService::class)->upload($request->user(), $payoutId, $request->file('evidence'), $request->validated('note'));
        } catch (FinancialException|PayoutTransferException) {
            return $this->refused();
        }

        return $request->expectsJson() ? response()->json(['data' => $record], 201)->header('Cache-Control', 'private, no-store')
            : redirect()->route('admin.payout-transfers.show', $payoutId)->with('status', 'Private file archived. A different authorized reviewer must download and verify it. No external payment was sent.');
    }

    public function download(Request $request, int $payoutId, int $evidenceId)
    {
        try {
            $bytes = app(PayoutEvidenceService::class)->read($request->user(), $payoutId, $evidenceId);
        } catch (FinancialException|PayoutTransferException) {
            return $this->refused();
        }

        return response($bytes)->header('Content-Type', 'application/octet-stream')
            ->header('Content-Disposition', 'attachment; filename="payout-evidence.bin"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "sandbox; default-src 'none'")->header('Referrer-Policy', 'no-referrer');
    }

    private function refused()
    {
        return response()->json(['code' => 'private_payout_evidence_refused'], 409)->header('Cache-Control', 'private, no-store');
    }
}
