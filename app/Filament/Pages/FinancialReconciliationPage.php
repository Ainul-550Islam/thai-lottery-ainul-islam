<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\DTOs\Finance\FinancialReconciliationReport;
use App\Enums\Currency;
use App\Services\Finance\FinancialReconciliationService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Filament Admin Panel Page for Financial Reconciliation & Accounting Integrity.
 */
class FinancialReconciliationPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Reconciliation';

    protected static ?string $title = 'Financial Reconciliation & Accounting Control';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.financial-reconciliation-page';

    public ?string $from = null;

    public ?string $to = null;

    public ?string $currency = null;

    public static function canAccess(): bool
    {
        return AdminAccess::permitsAny([
            AdminPermission::LEDGER_RECONCILE,
            AdminPermission::LEDGER_VIEW,
            AdminPermission::REPORTS_VIEW,
        ]);
    }

    public function getReport(): FinancialReconciliationReport
    {
        $service = app(FinancialReconciliationService::class);

        $fromDate = is_string($this->from) && trim($this->from) !== '' ? Carbon::parse($this->from) : null;
        $toDate = is_string($this->to) && trim($this->to) !== '' ? Carbon::parse($this->to) : null;
        $curr = is_string($this->currency) && trim($this->currency) !== '' ? Currency::tryFrom($this->currency) : null;

        return $service->reconcile(
            from: $fromDate,
            to: $toDate,
            currency: $curr,
            initiatedBy: 'AdminPanel:'.(auth()->user()?->email ?? 'Operator'),
        );
    }
}
