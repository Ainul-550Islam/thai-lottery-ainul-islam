<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\PayoutStatus;
use App\Enums\PrizePayoutMethod;
use App\Exceptions\FinancialException;
use App\Models\Payout;
use App\Models\User;
use App\Services\Finance\PayoutApprovalGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Evidence archival is not provider authentication. Final consumption is atomic with canonical accounting. */
final class PayoutEvidenceService
{
    public function upload(User $actor, int $payoutId, UploadedFile $file, string $note): array
    {
        app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::UPLOAD_EVIDENCE);
        if (DB::transactionLevel() > 0 || mb_strlen(trim($note)) < 15 || mb_strlen($note) > 1000) {
            $this->refuse();
        }
        $store = app(PayoutEvidenceStore::class);
        $blob = $store->store($file);
        try {
            return DB::transaction(function () use ($actor, $payoutId, $note, $blob): array {
                $current = app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::UPLOAD_EVIDENCE);
                $row = Payout::query()->lockForUpdate()->findOrFail($payoutId);
                $guard = app(PayoutApprovalGuard::class);
                $guard->assertApproved($row);
                if ((int) $row->user_id === (int) $current->id || $guard->method($row) === PrizePayoutMethod::Wallet
                    || ! in_array($row->status, [PayoutStatus::Pending, PayoutStatus::Processing], true)) {
                    $this->refuse();
                }
                $id = DB::table('payout_transfer_evidence')->insertGetId(array_merge($blob, ['payout_id' => $row->id,
                    'uploaded_by' => $current->id, 'facts_hash' => $guard->fingerprint($row), 'legal_hold' => true, 'created_at' => now()]));
                app(PayoutTransferService::class)->audit($row, $current, 'Private external prize evidence archived; authenticity not automatically verified.',
                    ['evidence_id' => $id, 'note_sha256' => hash('sha256', $note)]);

                return $this->project(DB::table('payout_transfer_evidence')->find($id));
            }, 3);
        } catch (\Throwable $e) {
            $store->discardUncommitted($blob['blob_key']);
            throw $e;
        }
    }

    public function listing(User $actor, int $payoutId): array
    {
        $current = app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::READ_EVIDENCE);
        $row = Payout::withTrashed()->findOrFail($payoutId);
        if ((int) $row->user_id === (int) $current->id) {
            $this->refuse();
        }

        return DB::table('payout_transfer_evidence')->where('payout_id', $row->id)->orderByDesc('id')->limit(50)->get()->map(fn ($r) => $this->project($r))->all();
    }

    /** Authenticated attachment read, including retained deleted-payout history, with append-only access audit. */
    public function read(User $actor, int $payoutId, int $evidenceId): string
    {
        app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::READ_EVIDENCE);

        return DB::transaction(function () use ($actor, $payoutId, $evidenceId): string {
            $current = app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::READ_EVIDENCE);
            $row = Payout::withTrashed()->findOrFail($payoutId);
            $e = DB::table('payout_transfer_evidence')->where('payout_id', $row->id)->where('id', $evidenceId)->first();
            if (! $e || (int) $row->user_id === (int) $current->id) {
                $this->refuse();
            }
            $bytes = app(PayoutEvidenceStore::class)->read($e);
            DB::table('payout_transfer_evidence_reads')->insert(['evidence_id' => $e->id, 'user_id' => $current->id, 'created_at' => now()]);
            app(PayoutTransferService::class)->audit($row, $current, 'Authorized operator downloaded private external prize evidence.', ['evidence_id' => $e->id]);

            return $bytes;
        });
    }

    /** Caller owns payout/Payment locks. No signature/provider-authentication assertion is inferred from these bytes. */
    public function consume(Payout $row, User $actor, string $outcome, ?string $reference, ?int $evidenceId, bool $replay): int
    {
        if (DB::transactionLevel() < 1) {
            $this->refuse();
        }
        app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::REVIEW);
        app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::READ_EVIDENCE);
        $query = DB::table('payout_transfer_evidence')->where('payout_id', $row->id);
        $items = ($evidenceId === null ? $query->limit(2) : $query->where('id', $evidenceId))->lockForUpdate()->get();
        $e = $items->first();
        $hash = $reference === null ? null : hash('sha256', $reference);
        if ($items->count() !== 1 || ! $e || (int) $e->uploaded_by === (int) $actor->id || (int) $row->user_id === (int) $actor->id
            || ! hash_equals($e->facts_hash, app(PayoutApprovalGuard::class)->fingerprint($row))
            || ! DB::table('payout_transfer_evidence_reads')->where('evidence_id', $e->id)->where('user_id', $actor->id)->where('created_at', '>=', now()->subDay())->exists()
            || ! in_array($outcome, ['completed', 'failed'], true) || ($outcome === 'completed') !== ($reference !== null)) {
            $this->refuse();
        }
        app(PayoutEvidenceStore::class)->read($e); // Check decryptability/length/digest again inside finalization.
        if ($e->consumed_at !== null) {
            if (! $replay || $e->outcome !== $outcome || $e->provider_reference_hash !== $hash || $e->consumed_by === null) {
                $this->refuse();
            }

            return (int) $e->id;
        }
        if ($replay) {
            $this->refuse(); // Never invent evidence for a historical terminal record on a fast-path replay.
        }
        app(PayoutTransferAccess::class)->authorize(User::query()->find($e->uploaded_by), PayoutTransferAccess::UPLOAD_EVIDENCE);
        DB::table('payout_transfer_evidence')->where('id', $e->id)->update(['consumed_by' => $actor->id, 'consumed_at' => now(),
            'outcome' => $outcome, 'provider_reference_hash' => $hash]);

        return (int) $e->id;
    }

    /** Read-only terminal projection: no retrospective archive creation or evidence consumption. */
    public function assertAttached(Payout $row, array $record): void
    {
        $e = DB::table('payout_transfer_evidence')->where('payout_id', $row->id)->where('id', $record['evidence_id'] ?? 0)->first();
        $reference = $record['gateway_reference'] ?? null;
        if (! $e || $e->consumed_at === null || $e->outcome !== ($record['lane_status'] ?? null)
            || (int) $e->consumed_by !== (int) ($record['reviewed_by'] ?? 0)
            || $e->provider_reference_hash !== ($reference === null ? null : hash('sha256', $reference))
            || ! hash_equals($e->facts_hash, app(PayoutApprovalGuard::class)->fingerprint($row))) {
            $this->refuse();
        }
        app(PayoutEvidenceStore::class)->read($e);
    }

    public function project(object $e): array
    {
        return ['id' => (int) $e->id, 'payout_id' => (int) $e->payout_id, 'bytes' => (int) $e->bytes, 'mime' => $e->mime,
            'uploaded_at' => $e->created_at, 'consumed' => $e->consumed_at !== null, 'legal_hold' => (bool) $e->legal_hold,
            'retention_until' => $e->retention_until, 'provenance' => 'human_uploaded', 'provider_authenticity_verified' => false];
    }

    private function refuse(): never
    {
        throw FinancialException::withCode('private_payout_evidence_refused', 'Private archived evidence, recent authorized inspection and an independent reviewer are required; retain uncertainty without resend.');
    }
}
