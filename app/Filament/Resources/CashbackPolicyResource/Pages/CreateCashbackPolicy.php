<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashbackPolicyResource\Pages;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Filament\Resources\CashbackPolicyResource;
use App\Models\AuditLog;
use Filament\Resources\Pages\CreateRecord;

final class CreateCashbackPolicy extends CreateRecord
{
    protected static string $resource = CashbackPolicyResource::class;

    /**
     * Admin edits of money-affecting configuration are always audited.
     */
    protected function afterCreate(): void
    {
        $changed = array_keys($this->record->getAttributes());

        AuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => AuditAction::Create->value,
            'auditable_type' => $this->record::class,
            'auditable_id' => (int) $this->record->getKey(),
            'risk_level' => RiskLevel::Medium->value,
            'description' => 'Admin created cashback policy.',
            'metadata' => [
                'changed_fields' => array_values(array_diff($changed, ['updated_at', 'created_at'])),
            ],
        ]);
    }
}
