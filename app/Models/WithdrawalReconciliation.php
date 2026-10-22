<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Evidence and maker facts are immutable through this application model. */
final class WithdrawalReconciliation extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['facts', 'request_key', 'snapshot_hash', 'evidence_sha256', 'decision_note'];

    protected function casts(): array
    {
        return ['facts' => 'encrypted:array', 'decision_note' => 'encrypted', 'decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $row): void {
            $allowed = ['status', 'checker_id', 'decision_note', 'decided_at', 'updated_at'];
            if ($row->getRawOriginal('status') !== 'pending' || array_diff(array_keys($row->getDirty()), $allowed)
                || ! in_array($row->status, ['applied', 'rejected'], true) || ! $row->checker_id
                || (int) $row->checker_id === (int) $row->maker_id || ! $row->decided_at) {
                throw new LogicException('Reconciliation evidence or final decision is immutable.');
            }
        });
        self::deleting(fn () => throw new LogicException('Reconciliation evidence cannot be deleted through the application.'));
    }
}
