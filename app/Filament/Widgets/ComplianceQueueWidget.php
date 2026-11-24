<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AmlRiskLevel;
use App\Enums\KycStatus;
use App\Enums\SelfExclusionStatus;
use App\Models\AmlRiskAssessment;
use App\Models\FraudAlert;
use App\Models\KycDocument;
use App\Models\SelfExclusion;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What compliance is waiting on, per desk.
 *
 * WHY A SEPARATE WIDGET
 *
 * PlatformStatsWidget answers "is the platform alive?" — players, draws, stake today. It
 * says nothing about the queues that only grow when nobody looks: KYC documents waiting
 * for a human, fraud alerts nobody has picked up, AML assessments at high risk, players
 * in a self-exclusion window. Those are the numbers that turn into regulatory findings.
 *
 * EVERY STAT IS GATED SEPARATELY
 *
 * A widget that hides itself entirely when one permission is missing teaches operators to
 * ask for the broadest role. Each stat is therefore shown only to an operator holding the
 * permission that owns it — a KYC officer sees the KYC queue and nothing else, an AML
 * officer sees the AML count and nothing else — and the widget disappears when the
 * operator holds none of them.
 *
 * The counts are real queries over real tables; nothing here is cached or seeded.
 */
class ComplianceQueueWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return AdminAccess::permitsAny([
            AdminPermission::KYC_VIEW,
            AdminPermission::FRAUD_VIEW,
            AdminPermission::AML_VIEW,
            AdminPermission::USERS_VIEW,
        ]);
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $stats = [];

        if (AdminAccess::permits(AdminPermission::KYC_VIEW)) {
            $pendingKyc = KycDocument::query()
                ->whereIn('status', [KycStatus::Pending, KycStatus::UnderReview])
                ->count();

            $stats[] = Stat::make('KYC awaiting review', (string) $pendingKyc)
                ->description($pendingKyc > 0 ? 'Documents a player is waiting on' : 'Queue empty')
                ->descriptionIcon($pendingKyc > 0 ? 'heroicon-m-identification' : 'heroicon-m-check-circle')
                ->color($pendingKyc > 0 ? 'warning' : 'success');
        }

        if (AdminAccess::permits(AdminPermission::FRAUD_VIEW)) {
            $openAlerts = FraudAlert::query()
                ->whereIn('status', ['OPEN', 'UNDER_REVIEW'])
                ->count();

            $highRisk = FraudAlert::query()
                ->whereIn('status', ['OPEN', 'UNDER_REVIEW'])
                ->where('risk_score', '>=', 70)
                ->count();

            $stats[] = Stat::make('Fraud alerts open', (string) $openAlerts)
                ->description($highRisk > 0
                    ? sprintf('%d at risk score 70+', $highRisk)
                    : 'No high-score alerts')
                ->descriptionIcon('heroicon-m-shield-exclamation')
                ->color($highRisk > 0 ? 'danger' : ($openAlerts > 0 ? 'warning' : 'success'));
        }

        if (AdminAccess::permits(AdminPermission::AML_VIEW)) {
            $highRiskAssessments = AmlRiskAssessment::query()
                ->where('status', AmlRiskAssessment::STATUS_CURRENT)
                ->whereIn('risk_level', [AmlRiskLevel::High, AmlRiskLevel::Critical])
                ->count();

            $stats[] = Stat::make('AML high / critical', (string) $highRiskAssessments)
                ->description('Current assessments at high or critical risk')
                ->descriptionIcon('heroicon-m-scale')
                ->color($highRiskAssessments > 0 ? 'danger' : 'success');
        }

        if (AdminAccess::permits(AdminPermission::USERS_VIEW)) {
            $excluded = SelfExclusion::query()
                ->where('status', SelfExclusionStatus::Active)
                ->count();

            $stats[] = Stat::make('Self-exclusions active', (string) $excluded)
                ->description('Players the platform must refuse a bet to')
                ->descriptionIcon('heroicon-m-hand-raised')
                ->color($excluded > 0 ? 'info' : 'gray');
        }

        return $stats;
    }
}
