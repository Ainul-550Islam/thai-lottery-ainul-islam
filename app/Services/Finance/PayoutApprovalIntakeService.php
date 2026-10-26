<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\PayoutApprovalData;
use App\DTOs\Finance\PayoutBatchData;
use App\DTOs\Finance\PayoutRequestData;
use App\Enums\PayoutApprovalStatus;
use App\Enums\PrizePayoutMethod;
use App\Models\Payout;
use App\Models\PrizeDisbursement;
use App\Models\User;
use App\Services\Payment\PayoutTransferAccess;
use App\Services\Payment\PayoutTransferService;
use Illuminate\Support\Facades\DB;

/** Thin domain intake derives money/ownership/key from SQL, never browser-supplied financial identity. */
final class PayoutApprovalIntakeService
{
    public function request(User $actor, int $id, string $method, ?array $beneficiary, string $note): Payout
    {
        app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::REQUEST);

        return DB::transaction(function () use ($actor, $id, $method, $beneficiary, $note): Payout {
            $row = Payout::query()->lockForUpdate()->findOrFail($id);
            app(\App\Services\Prize\PrizeClaimGuard::class)->assertPayableClaim($row);
            $lane = PrizePayoutMethod::tryFrom($method);
            if (PrizeDisbursement::query()->where('payout_id', $row->id)->exists() || ! $lane || mb_strlen(trim($note)) < 15 || mb_strlen($note) > 1000) {
                app(PayoutBatchMembershipService::class)->refuse();
            }
            $data = new PayoutRequestData($row->reference_number, (string) $row->amount, $row->currency, $lane,
                (int) $actor->id, (int) $row->user_id, $lane === PrizePayoutMethod::Wallet ? null : $beneficiary,
                null, PayoutRequestData::deriveRequestKey($row->reference_number, (string) $row->amount, $row->currency, (int) $row->user_id));
            app(PayoutApprovalService::class)->request($row, $data);
            app(PayoutTransferService::class)->audit($row, $actor, 'Payout maker submitted immutable original facts.', ['note_sha256' => hash('sha256', $note)]);

            return $row->fresh();
        }, 3);
    }

    public function decide(User $actor, int $id, string $decision, bool $verified, string $note): Payout
    {
        app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::APPROVE);
        $status = PayoutApprovalStatus::tryFrom($decision);
        if (! in_array($status, [PayoutApprovalStatus::Approved, PayoutApprovalStatus::Rejected], true)
            || ! $verified || mb_strlen(trim($note)) < 15 || mb_strlen($note) > 1000) {
            app(PayoutBatchMembershipService::class)->refuse();
        }

        return DB::transaction(function () use ($actor, $id, $status, $note): Payout {
            $row = Payout::query()->lockForUpdate()->findOrFail($id);
            // Notes stay hashed; do not embed destinations or arbitrary text in generic approval audit descriptions.
            app(PayoutApprovalService::class)->decide($row, new PayoutApprovalData((int) $actor->id, $status, 'Independent original-facts review recorded.'));
            app(PayoutTransferService::class)->audit($row, $actor, 'Independent payout approval verdict recorded.', ['note_sha256' => hash('sha256', $note)]);

            return $row->fresh();
        }, 3);
    }

    public function destination(User $actor, int $id): array
    {
        app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::APPROVE);

        return DB::transaction(function () use ($actor, $id): array {
            $row = Payout::query()->lockForUpdate()->findOrFail($id);
            app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::APPROVE);
            $a = $row->metadata['approval'] ?? null;
            if (! is_array($a) || ($a['facts_hash'] ?? null) !== app(PayoutApprovalGuard::class)->fingerprint($row)) {
                app(PayoutBatchMembershipService::class)->refuse();
            }
            app(PayoutTransferService::class)->audit($row, $actor, 'Checker inspected original private payout approval destination.');

            return app(PayoutApprovalGuard::class)->beneficiary($row);
        }, 3);
    }

    public function batch(User $actor, array $ids)
    {
        app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::ROUTE);
        if (! array_is_list($ids) || count($ids) < 1 || count($ids) > 200
            || array_any($ids, fn ($id) => ! is_int($id) || $id < 1) || count(array_unique($ids)) !== count($ids)) {
            app(PayoutBatchMembershipService::class)->refuse();
        }

        return DB::transaction(function () use ($actor, $ids) {
            app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::ROUTE);
            $rows = Payout::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($rows->count() !== count($ids) || $rows->contains(fn ($row) => (int) $row->user_id === (int) $actor->id)) {
                app(PayoutBatchMembershipService::class)->refuse();
            }
            $sum = '0.00';
            foreach ($rows as $row) {
                $sum = bcadd($sum, (string) $row->amount, 2);
            }

            return app(PayoutBatchService::class)->create(new PayoutBatchData($rows->pluck('reference_number')->all(), $sum, $rows->first()->currency, null))['batch'];
        }, 3);
    }
}
