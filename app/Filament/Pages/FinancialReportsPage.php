<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\DTOs\Operations\OperationalReportData;
use App\DTOs\Operations\ReportExportData;
use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Enums\RiskLevel;
use App\Models\OperationalReportJob;
use App\Services\Audit\AuditLogService;
use App\Services\Operations\OperationalReportService;
use App\Services\Operations\ReportExportService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use App\Support\Admin\AdminPermission;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The report desk: run a real projection and take the artifact away.
 *
 * WHY THIS PAGE EXISTS
 *
 * The application has had a complete reporting lane since the operations phase —
 * OperationalReportService::ask() freezes a query into a job, run() executes it against
 * the lane projections, ReportExportService::render() seals the bytes with a checksum.
 * That lane was reachable only from the admin API (`/api/v1/admin/operations/...`), which
 * means an operator holding the panel could not answer "what did we settle last month?"
 * without a token and curl.
 *
 * WHAT IT REUSES, AND WHY THAT MATTERS
 *
 * Nothing here queries the database directly. The page builds an OperationalReportData
 * (which validates the horizon and the filter vocabulary at the door), asks the service
 * to freeze and run it, and asks the export lane to render the sealed artifact. The
 * panel therefore cannot drift from the API: both produce the same job row for the same
 * ask, because the job is identified by the query fingerprint, not by who clicked.
 *
 * PERMISSIONS
 *
 *   reports.view   — may run a report and read it in the browser.
 *   reports.export — may take the artifact away (CSV/JSON download), which is the
 *                    data-exfiltration edge and therefore a separate grant.
 *
 * Every export writes an audit line with the report family, the horizon and the row
 * count. The audit line records WHAT was exported, never the rows themselves.
 */
/**
 * @property Form $form the Filament form state holder installed by InteractsWithForms
 */
class FinancialReportsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $title = 'Financial & operational reports';

    protected static ?int $navigationSort = 40;

    protected static string $view = 'filament.pages.financial-reports-page';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return AdminAccess::permitsAny([
            AdminPermission::REPORTS_VIEW,
            AdminPermission::REPORTS_EXPORT,
        ]);
    }

    public function mount(): void
    {
        $this->form->fill([
            'report_type' => ReportType::Financial->value,
            'horizon_start' => Carbon::now()->subDays((int) config('admin.reports.default_horizon_days', 30))->toDateString(),
            'horizon_end' => Carbon::now()->toDateString(),
            'format' => ReportFormat::Csv->value,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Ask')
                    ->description('A report is a frozen question. The same question always produces the same job, so re-running it cannot double-count.')
                    ->schema([
                        Forms\Components\Select::make('report_type')
                            ->label('Report family')
                            ->options(fn (): array => static::reportOptions())
                            ->required()
                            ->live()
                            ->helperText('Prize reports are not projected yet and will be refused by the reporting lane rather than returning an empty file.'),

                        Forms\Components\Select::make('currency')
                            ->label('Currency')
                            ->options(fn (): array => collect(Currency::cases())
                                ->mapWithKeys(fn (Currency $currency): array => [$currency->value => $currency->value])
                                ->all())
                            ->placeholder('All currencies')
                            ->helperText('Totals are never combined across currencies. Filter to one currency when you need a comparable figure.'),

                        Forms\Components\DatePicker::make('horizon_start')
                            ->label('From')
                            ->required()
                            ->native(false),

                        Forms\Components\DatePicker::make('horizon_end')
                            ->label('To')
                            ->required()
                            ->native(false)
                            ->after('horizon_start')
                            ->helperText(fn (): string => sprintf(
                                'Longest horizon the desk accepts in one request: %d days.',
                                (int) config('admin.reports.max_horizon_days', 366),
                            )),

                        Forms\Components\Select::make('format')
                            ->label('Format')
                            ->options(fn (): array => collect((array) config('admin.reports.formats', ['csv', 'json']))
                                ->mapWithKeys(fn (string $format): array => [$format => strtoupper($format)])
                                ->all())
                            ->required()
                            ->helperText('PDF and XLSX are not rendered locally by the export lane; asking for them would fail at the seal.'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    /**
     * Freeze the ask, run it, and hand back the sealed artifact as a download.
     *
     * Refusals from any lane (a filter outside the vocabulary, a horizon past the
     * ceiling, a family with no projection) are reported as notifications. They are
     * never swallowed and never turned into an empty download, because an empty file
     * looks like "we settled nothing" rather than "that question was refused".
     */
    public function generate(): ?StreamedResponse
    {
        if (! AdminAccess::permits(AdminPermission::REPORTS_VIEW)) {
            Notification::make()->title('Refused')->body('You do not hold reports.view.')->danger()->send();

            return null;
        }

        $state = $this->form->getState();

        $operator = AdminAccess::operator();

        if ($operator === null) {
            return null;
        }

        $filters = [];

        if (! empty($state['currency'])) {
            $filters['currency'] = (string) $state['currency'];
        }

        try {
            $job = $this->runReport(
                reportType: (string) $state['report_type'],
                filters: $filters,
                start: (string) $state['horizon_start'],
                end: (string) $state['horizon_end'],
                userId: (int) $operator->getKey(),
            );

            $format = ReportFormat::tryFrom((string) $state['format']) ?? ReportFormat::Csv;

            if (! AdminAccess::permits(AdminPermission::REPORTS_EXPORT)) {
                Notification::make()
                    ->title('Report ready')
                    ->body(sprintf('The %s report completed with %d row(s). Exporting requires reports.export.', $job->report_type->value, (int) $job->row_count))
                    ->warning()
                    ->send();

                return null;
            }

            $artifact = app(ReportExportService::class)->render(new ReportExportData(
                reportJobId: (int) $job->getKey(),
                format: $format,
            ));

            /** @var \App\Models\ReportExport $export */
            $export = $artifact['export'];

            app(AuditLogService::class)->log(
                userId: (int) $operator->getKey(),
                action: AuditAction::DataExport,
                riskLevel: RiskLevel::High,
                auditable: $job,
                description: sprintf(
                    'Exported %s report (%s, %s → %s) as %s',
                    $job->report_type->value,
                    $job->query_fingerprint,
                    $job->horizon_start?->toDateString() ?? '—',
                    $job->horizon_end?->toDateString() ?? '—',
                    $format->value,
                ),
                metadata: [
                    'report_type' => $job->report_type->value,
                    'row_count' => (int) $job->row_count,
                    'format' => $format->value,
                    'checksum' => $export->checksum,
                    'filters' => $job->filters,
                ],
            );

            $filename = sprintf(
                '%s-%s-%s.%s',
                $job->report_type->value,
                $job->horizon_start?->format('Ymd') ?? 'from',
                $job->horizon_end?->format('Ymd') ?? 'to',
                $format->value,
            );

            Notification::make()
                ->title('Report sealed')
                ->body(sprintf('%d row(s), checksum verified. The export was audited.', (int) $job->row_count))
                ->success()
                ->send();

            return response()->streamDownload(
                static fn () => print $artifact['bytes'],
                $filename,
                ['Content-Type' => $format->mime()],
            );
        } catch (Throwable $e) {
            Notification::make()
                ->title('Report refused')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }

    /**
     * Ask + run, in that order, through the canonical service.
     *
     * @param  array<string, string>  $filters
     */
    private function runReport(string $reportType, array $filters, string $start, string $end, int $userId): OperationalReportJob
    {
        $type = ReportType::tryFrom($reportType);

        if (! $type instanceof ReportType) {
            throw new \InvalidArgumentException(sprintf('[%s] is not a report family.', $reportType));
        }

        $service = app(OperationalReportService::class);

        $ask = OperationalReportData::fromInput([
            'requester_user_id' => $userId,
            'report_type' => $type->value,
            'filters' => $filters,
            'horizon_start' => $start,
            'horizon_end' => $end,
        ]);

        /** @var OperationalReportJob $job */
        $job = $service->ask($ask)['job'];

        return $service->run((string) $job->query_fingerprint);
    }

    /**
     * The report families the desk offers, with the horizon each one accepts.
     *
     * @return array<string, string>
     */
    public static function reportOptions(): array
    {
        $options = [];

        foreach (ReportType::cases() as $type) {
            $options[$type->value] = sprintf(
                '%s (up to %d days)',
                ucfirst($type->value),
                $type->syncHorizonDays(),
            );
        }

        return $options;
    }

    /**
     * The operator's own recent report jobs, newest first.
     *
     * Scoped to the requester on purpose: OperationalReportJob carries the filters of
     * somebody else's question, and a shared list would leak which player ids an AML
     * officer has been looking at.
     *
     * @return list<array<string, string>>
     */
    public function recentJobs(): array
    {
        $operator = AdminAccess::operator();

        if ($operator === null) {
            return [];
        }

        return array_values(OperationalReportJob::query()
            ->where('requester_user_id', (int) $operator->getKey())
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(static fn (OperationalReportJob $job): array => [
                'type' => ucfirst($job->report_type->value),
                'status' => $job->status->value,
                'rows' => (string) ((int) $job->row_count),
                'horizon' => sprintf(
                    '%s → %s',
                    $job->horizon_start?->format('Y-m-d') ?? '—',
                    $job->horizon_end?->format('Y-m-d') ?? '—',
                ),
                'asked' => AdminFormat::marketTime($job->created_at),
                'fingerprint' => substr((string) $job->query_fingerprint, 0, 12),
            ])
            ->all());
    }
}
