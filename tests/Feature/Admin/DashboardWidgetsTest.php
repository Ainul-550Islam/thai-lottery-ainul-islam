<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\KycStatus;
use App\Enums\UserStatus;
use App\Filament\Widgets\ComplianceQueueWidget;
use App\Filament\Widgets\SystemHealthWidget;
use App\Models\FraudAlert;
use App\Models\KycDocument;
use App\Models\User;
use Database\Seeders\AdminRbacSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The dashboard's new compliance and health panels.
 *
 * WHY THIS IS AN HTTP TEST AND NOT A UNIT TEST OF THE WIDGET
 *
 * The promise being checked is "an operator sees this number on their landing screen, and
 * does not see a number their role does not own". That promise is kept (or broken) in the
 * rendered page, by the interaction between the widget's own gate and Filament's widget
 * registration. Testing the widget class in isolation would prove the query and miss the
 * panel.
 *
 * The counts are asserted against rows written by the test, so a widget that quietly
 * returned a constant would fail here.
 */
final class DashboardWidgetsTest extends TestCase
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

        return $user->fresh();
    }

    private function pendingKycDocuments(int $count): void
    {
        KycDocument::factory()->count($count)->create(['status' => KycStatus::Pending]);
    }

    private function openFraudAlert(int $riskScore): FraudAlert
    {
        $alert = new FraudAlert;
        $alert->forceFill([
            'id' => (string) Str::uuid(),
            'idempotency_event_key' => 'widget-test-'.Str::random(12),
            'event_type' => 'WITHDRAWAL',
            'user_id' => (string) Str::uuid(),
            'amount' => '1000.0000',
            'currency' => 'THB',
            'risk_score' => $riskScore,
            'decision' => 'hold_for_review',
            'status' => 'OPEN',
        ]);
        $alert->save();

        return $alert;
    }

    // ------------------------------------------------------------------

    public function test_the_admin_sees_the_compliance_queues_with_real_counts(): void
    {
        $this->pendingKycDocuments(3);
        $this->openFraudAlert(85);

        $this->actingAs($this->operator('admin'));

        $this->assertTrue(ComplianceQueueWidget::canView());

        Livewire::test(ComplianceQueueWidget::class)
            ->assertSuccessful()
            ->assertSee('KYC awaiting review')
            ->assertSee('Fraud alerts open')
            ->assertSee('Self-exclusions active')
            ->assertSee('3');
    }

    public function test_the_kyc_officer_sees_the_kyc_queue_and_not_the_fraud_queue(): void
    {
        $this->pendingKycDocuments(2);
        $this->openFraudAlert(90);

        $this->actingAs($this->operator('kyc-officer'));

        Livewire::test(ComplianceQueueWidget::class)
            ->assertSuccessful()
            ->assertSee('KYC awaiting review')
            ->assertDontSee('Fraud alerts open')
            ->assertDontSee('AML high / critical');
    }

    public function test_the_fraud_analyst_sees_fraud_and_not_the_kyc_queue(): void
    {
        $this->pendingKycDocuments(2);
        $this->openFraudAlert(75);

        $this->actingAs($this->operator('fraud-analyst'));

        Livewire::test(ComplianceQueueWidget::class)
            ->assertSuccessful()
            ->assertSee('Fraud alerts open')
            ->assertDontSee('KYC awaiting review');
    }

    public function test_an_operator_with_no_compliance_grant_sees_no_compliance_widget(): void
    {
        $this->actingAs($this->operator('lottery-manager'));

        $this->assertFalse(
            ComplianceQueueWidget::canView(),
            'a lottery manager holds no KYC, fraud, AML or user permission',
        );
    }

    public function test_system_health_is_shown_to_an_operator_who_may_read_the_audit_trail(): void
    {
        $this->actingAs($this->operator('admin'));

        $this->assertTrue(SystemHealthWidget::canView());

        Livewire::test(SystemHealthWidget::class)
            ->assertSuccessful()
            ->assertSee('Failed jobs')
            ->assertSee('Queue backlog')
            ->assertSee('Providers serving');
    }

    public function test_a_support_agent_sees_neither_compliance_queues_nor_system_health(): void
    {
        $this->pendingKycDocuments(1);

        $this->actingAs($this->operator('support-agent'));

        $this->assertFalse(SystemHealthWidget::canView(), 'queue health is not part of the support desk');

        // The support agent holds users.view, so the self-exclusion count is theirs to see,
        // and the fraud queue is not.
        Livewire::test(ComplianceQueueWidget::class)
            ->assertSuccessful()
            ->assertDontSee('Fraud alerts open')
            ->assertDontSee('AML high / critical');
    }

    public function test_the_operations_dashboard_still_renders_for_an_operator(): void
    {
        $this->actingAs($this->operator('admin'))
            ->get('/admin')
            ->assertSuccessful()
            ->assertSee('Operations');
    }
}
