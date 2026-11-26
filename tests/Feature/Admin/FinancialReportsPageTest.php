<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AuditAction;
use App\Enums\ReportType;
use App\Enums\UserStatus;
use App\Filament\Pages\FinancialReportsPage;
use App\Models\AuditLog;
use App\Models\OperationalReportJob;
use App\Models\ReportExport;
use App\Models\User;
use App\Support\Admin\AdminPermission;
use Database\Seeders\AdminRbacSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The report desk: who may run a report, and who may take the artifact away.
 *
 * WHY THE TWO PERMISSIONS ARE TESTED SEPARATELY
 *
 * Running a report shows numbers on a screen. Exporting it writes a file that leaves the
 * building, which is the data-exfiltration edge — the audit rule calls it a High risk
 * data_export for exactly that reason. Collapsing the two into one permission would mean
 * that anybody who may read a total may also mail it out.
 *
 * WHAT IS ASSERTED
 *
 *   - The page is closed to an operator without a reporting grant, and open to one with.
 *   - Asking for a report really goes through OperationalReportService (a job row with a
 *     query fingerprint appears) rather than querying the database directly.
 *   - The export is sealed (a ReportExport row with a checksum) and the audit line names
 *     the family, the horizon and the row count — never the rows.
 *   - A view-only operator completes the run and is refused the file.
 */
final class FinancialReportsPageTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    private const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected function setUp(): void
    {
        parent::setUp();

        $database = \DB::connection()->getDatabaseName();

        $this->assertContains(
            basename((string) $database),
            self::ALLOWED_DATABASES,
            'Refusing to run destructive tests against database: '.$database,
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(AdminRbacSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function operator(string $role): User
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $user->assignRole($role);

        $this->actingAs($user->fresh());

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function ask(Testable $page, array $state = []): Testable
    {
        return $page->fillForm(array_merge([
            'report_type' => ReportType::Financial->value,
            'horizon_start' => now()->subDays(7)->toDateString(),
            'horizon_end' => now()->toDateString(),
            'format' => 'csv',
        ], $state));
    }

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    public function test_the_page_is_closed_to_an_operator_without_a_reporting_grant(): void
    {
        $this->operator('kyc-officer');

        $this->assertFalse(FinancialReportsPage::canAccess());
    }

    public function test_the_page_opens_for_a_reporting_operator(): void
    {
        $this->operator('finance-manager');

        $this->assertTrue(FinancialReportsPage::canAccess());

        Livewire::test(FinancialReportsPage::class)
            ->assertSuccessful()
            ->assertSee('Financial & operational reports');
    }

    public function test_a_guest_can_access_nothing(): void
    {
        auth()->logout();

        $this->assertFalse(FinancialReportsPage::canAccess());
    }

    // ------------------------------------------------------------------
    // The run
    // ------------------------------------------------------------------

    public function test_running_a_report_freezes_a_job_through_the_reporting_service(): void
    {
        $operator = $this->operator('finance-manager');

        $page = $this->ask(Livewire::test(FinancialReportsPage::class));

        $page->call('generate');

        $job = OperationalReportJob::query()->firstOrFail();

        $this->assertSame((int) $operator->getKey(), (int) $job->requester_user_id);
        $this->assertSame(ReportType::Financial, $job->report_type);
        $this->assertSame('completed', $job->status->value, 'the run must complete, not merely queue');
        $this->assertNotSame('', (string) $job->query_fingerprint);
    }

    public function test_the_same_ask_replays_the_same_job_rather_than_double_counting(): void
    {
        $this->operator('finance-manager');

        $this->ask(Livewire::test(FinancialReportsPage::class))->call('generate');
        $this->ask(Livewire::test(FinancialReportsPage::class))->call('generate');

        $this->assertSame(
            1,
            OperationalReportJob::query()->count(),
            'a frozen ask is identified by its query fingerprint, so re-running it must not create a second job',
        );
    }

    public function test_an_export_is_sealed_and_audited_without_carrying_the_rows(): void
    {
        $operator = $this->operator('finance-manager');

        $this->ask(Livewire::test(FinancialReportsPage::class))->call('generate');

        $export = ReportExport::query()->firstOrFail();

        $this->assertSame('ready', $export->status);
        $this->assertSame(64, strlen((string) $export->checksum), 'the seal is a sha256 hex digest');
        $this->assertGreaterThanOrEqual(0, (int) $export->row_count);

        $audit = AuditLog::query()
            ->where('action', AuditAction::DataExport)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame((int) $operator->getKey(), (int) $audit->user_id);
        $this->assertStringContainsString('financial', (string) $audit->description);
        $this->assertArrayNotHasKey('rows', (array) $audit->metadata, 'the trail records what was exported, never the rows');
        $this->assertArrayHasKey('row_count', (array) $audit->metadata);
    }

    // ------------------------------------------------------------------
    // The second permission
    // ------------------------------------------------------------------

    public function test_a_view_only_operator_runs_the_report_but_takes_no_file(): void
    {
        $operator = $this->operator('read-only-analyst');

        // read-only-analyst holds reports.view and not reports.export.
        $this->assertTrue(
            \App\Support\Admin\AdminAccess::permits(AdminPermission::REPORTS_VIEW),
            'precondition: read-only-analyst may run a report',
        );
        $this->assertFalse(
            \App\Support\Admin\AdminAccess::permits(AdminPermission::REPORTS_EXPORT),
            'precondition: read-only-analyst may not export',
        );

        $this->ask(Livewire::test(FinancialReportsPage::class))->call('generate');

        $this->assertSame(1, OperationalReportJob::query()->count(), 'the run itself is allowed');
        $this->assertSame(0, ReportExport::query()->count(), 'the artifact must not be produced without reports.export');
        $this->assertSame(
            0,
            AuditLog::query()->where('action', AuditAction::DataExport)->count(),
            'a refused export must not be logged as an export',
        );
    }

    public function test_a_horizon_past_the_desk_ceiling_is_refused_rather_than_run(): void
    {
        $this->operator('finance-manager');

        $page = $this->ask(Livewire::test(FinancialReportsPage::class), [
            'horizon_start' => now()->subDays(500)->toDateString(),
            'horizon_end' => now()->toDateString(),
        ]);

        $page->call('generate');

        $this->assertSame(0, OperationalReportJob::query()->count(), 'an out-of-ceiling ask is refused at the door');
        $this->assertSame(0, ReportExport::query()->count());
    }

    public function test_the_recent_jobs_list_is_scoped_to_the_operator(): void
    {
        $mine = $this->operator('finance-manager');
        $this->ask(Livewire::test(FinancialReportsPage::class))->call('generate');

        // Somebody else's job, with a filter naming a player: it must not appear in my list.
        $other = User::factory()->create(['status' => UserStatus::Active]);
        $other->assignRole('finance-manager');

        OperationalReportJob::query()->create([
            'query_fingerprint' => hash('sha256', 'other-ask'),
            'requester_user_id' => $other->getKey(),
            'report_type' => ReportType::Financial,
            'filters' => ['user_id' => '999999'],
            'status' => \App\Enums\ReportJobStatus::Completed,
            'horizon_start' => now()->subDay(),
            'horizon_end' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $page = Livewire::test(FinancialReportsPage::class)->instance();
        $rows = $page->recentJobs();

        $this->assertCount(1, $rows);
        $this->assertSame(
            1,
            OperationalReportJob::query()->where('requester_user_id', $mine->getKey())->count(),
        );
    }
}
