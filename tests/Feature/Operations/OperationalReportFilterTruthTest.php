<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\DTOs\Operations\OperationalReportData;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\GloPrizeTier;
use App\Enums\KycStatus;
use App\Enums\LedgerEntryType;
use App\Enums\ReportJobStatus;
use App\Enums\ReportType;
use App\Enums\RiskLevel;
use App\Enums\WalletType;
use App\Exceptions\OperationalReportException;
use App\Models\AuditLog;
use App\Models\ComplianceCase;
use App\Models\Draw;
use App\Models\LedgerEntry;
use App\Models\OperationalReportJob;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Operations\OperationalReportService;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * Cluster #50: an operational report filter has to narrow rows, or it may not be offered.
 *
 * The desk advertises a filter whitelist per family, validates that a request only carries
 * advertised keys, and then - this is the part that was missing - the reader for that family did
 * not consult 14 of the 29 keys at all. `financial.currency` was accepted, sealed into the
 * job's fingerprint, and dropped on the floor: measured on a seed with one THB and one USD entry,
 * filtering THB returned 2 rows and filtering USD returned 2 - the unfiltered total. An
 * operator asking for one currency was handed every currency, with a row_count that looked like an
 * answer. One key was worse than ignored: `financial.user_id` compiled to `wallet_id LIKE '%%'`,
 * true of every row with a wallet, so that player's filter returned 2 rows when the player
 * owned 1. The prize family's reader guarded on a table no migration creates, so it returned
 * nothing for every horizon while the job seated completed with row_count 0 and no
 * failure_reason: "no claims this horizon" and "this family is not built" were the same answer.
 *
 * These tests hold four things:
 *  - every advertised key changes the row set, proven on data where both outcomes exist;
 *  - every key a reader consults is advertised, so nothing reaches a query unvalidated;
 *  - a key whose data no writer stamps is refused at the door rather than silently ignored;
 *  - a family with no projection says so out loud.
 */
final class OperationalReportFilterTruthTest extends TestCase
{
    use RefreshDatabase;

    private OperationalReportService $reports;

    /** Families projected by this service: [table, reader method]. prize has no projection. */
    private const FAMILY_READERS = [
        'financial' => ['ledger_entries', 'financialRows'],
        'payment' => ['payments', 'paymentRows'],
        'draw' => ['draws', 'drawRows'],
        'prize' => [null, 'prizeRows'],
        'compliance' => ['compliance_cases', 'complianceRows'],
        'player' => ['users', 'playerRows'],
        'operational' => ['audit_logs', 'operationalRows'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite(); // asserts page content; public/build/manifest.json is a build artefact

        $this->reports = app(OperationalReportService::class);
    }

    // ------------------------------------------------------------------ the narrowing proofs

    #[Test]
    public function the_financial_currency_filter_returns_only_the_named_currency(): void
    {
        $this->entryFor(Currency::THB);
        $this->entryFor(Currency::USD);

        $this->assertSame(2, $this->rows(ReportType::Financial)->count(), 'the horizon holds both entries');

        $filtered = $this->rows(ReportType::Financial, ['currency' => Currency::THB->value]);

        $this->assertSame(1, $filtered->count());
        $printed = $filtered->first()['currency'];
        $this->assertSame(Currency::THB->value, $printed instanceof \BackedEnum ? $printed->value : (string) $printed);
    }

    #[Test]
    public function the_financial_user_filter_shows_one_player_rather_than_every_player(): void
    {
        $one = $this->entryFor(Currency::THB);
        $other = $this->entryFor(Currency::THB);

        $rows = $this->rows(ReportType::Financial, ['user_id' => (string) $one->wallet->user_id]);

        $this->assertCount(1, $rows, 'a user filter must not match the wallet of another player');
        $this->assertSame($one->id, $rows->first()['id']);
        $this->assertNotSame($other->id, $rows->first()['id']);
    }

    #[Test]
    public function the_financial_wallet_kind_filter_travels_through_the_wallet(): void
    {
        $this->entryFor(Currency::THB, WalletType::Primary);
        $bonus = $this->entryFor(Currency::THB, WalletType::Bonus);

        $rows = $this->rows(ReportType::Financial, ['wallet_kind' => WalletType::Bonus->value]);

        $this->assertCount(1, $rows);
        $this->assertSame($bonus->id, $rows->first()['id']);
    }

    #[Test]
    public function the_draw_lifecycle_state_filter_maps_to_the_stored_status(): void
    {
        $this->draw(DrawStatus::Open);
        $settled = $this->draw(DrawStatus::Completed);

        $this->assertSame(2, $this->rows(ReportType::Draw)->count());

        $rows = $this->rows(ReportType::Draw, ['lifecycle_state' => 'settled']);

        $this->assertCount(1, $rows);
        $this->assertSame($settled->draw_number, $rows->first()['draw_number']);
    }

    #[Test]
    public function the_player_kyc_status_and_registration_age_filters_narrow(): void
    {
        $verified = User::factory()->create(['kyc_status' => KycStatus::Verified]);
        $pending = User::factory()->create(['kyc_status' => KycStatus::Pending]);
        $pending->forceFill(['created_at' => now()->subDays(90)])->save();

        $all = array_column($this->rows(ReportType::Player)->all(), 'id');
        $this->assertContains($verified->id, $all);
        $this->assertContains($pending->id, $all);

        $rows = $this->rows(ReportType::Player, ['kyc_status' => KycStatus::Verified->value]);
        $this->assertSame([$verified->id], array_column($rows->all(), 'id'));

        $recent = array_column($this->rows(ReportType::Player, ['registered_within' => '7'])->all(), 'id');
        $this->assertContains($verified->id, $recent);
        $this->assertNotContains($pending->id, $recent, 'a 90-day-old account is not registered within 7 days');
    }

    #[Test]
    public function the_operational_lane_and_risk_rating_filters_read_the_metadata_lanes(): void
    {
        $high = AuditLog::factory()->create([
            'metadata' => ['lane' => 'withdrawal-kyc', 'risk_rating' => RiskLevel::High->value],
        ]);
        AuditLog::factory()->create([
            'metadata' => ['lane' => 'provider-operation', 'risk_rating' => RiskLevel::Low->value],
        ]);

        $this->assertSame(2, $this->rows(ReportType::Operational)->count());

        $lanes = $this->rows(ReportType::Operational, ['lane' => 'withdrawal-kyc']);
        $this->assertSame([$high->id], array_column($lanes->all(), 'id'));

        $rated = $this->rows(ReportType::Operational, ['risk_rating' => RiskLevel::High->value]);
        $this->assertSame([$high->id], array_column($rated->all(), 'id'));

        $both = $this->rows(ReportType::Operational, ['lane' => 'withdrawal-kyc', 'risk_rating' => RiskLevel::High->value]);
        $this->assertCount(1, $both);

        $none = $this->rows(ReportType::Operational, ['lane' => 'withdrawal-kyc', 'risk_rating' => RiskLevel::Low->value]);
        $this->assertCount(0, $none, 'the two clauses must combine, not widen');
    }

    // ------------------------------------------------------------------ phantoms are refused

    #[Test]
    public function filters_whose_data_no_writer_stamps_are_refused_at_the_door(): void
    {
        $phantoms = [
            'financial.entry_purpose' => [ReportType::Financial, 'entry_purpose'],
            'payment.channel' => [ReportType::Payment, 'channel'],
            'draw.winner_only' => [ReportType::Draw, 'winner_only'],
            'player.risk_level' => [ReportType::Player, 'risk_level'],
            'prize.tier' => [ReportType::Prize, 'tier'],
        ];

        foreach ($phantoms as $label => [$type, $key]) {
            try {
                OperationalReportData::fromInput([
                    'requester_user_id' => $this->requester()->id,
                    'report_type' => $type,
                    'filters' => [$key => GloPrizeTier::First->value],
                ]);
                $this->fail($label.' must be refused rather than accepted and ignored');
            } catch (OperationalReportException $e) {
                $this->assertStringContainsString('filter', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_filter_value_outside_its_enum_vocabulary_is_refused(): void
    {
        $this->expectException(OperationalReportException::class);

        OperationalReportData::fromInput([
            'requester_user_id' => $this->requester()->id,
            'report_type' => ReportType::Financial,
            'filters' => ['currency' => 'thb'],
        ]);
    }

    #[Test]
    public function a_non_numeric_registration_window_is_refused(): void
    {
        $this->expectException(OperationalReportException::class);

        OperationalReportData::fromInput([
            'requester_user_id' => $this->requester()->id,
            'report_type' => ReportType::Player,
            'filters' => ['registered_within' => 'last week'],
        ]);
    }

    #[Test]
    public function the_prize_family_refuses_rather_than_reporting_an_empty_horizon(): void
    {
        $ask = $this->reports->ask(OperationalReportData::fromInput([
            'requester_user_id' => $this->requester()->id,
            'report_type' => ReportType::Prize,
        ]));

        $job = $ask['job'];
        $this->assertInstanceOf(OperationalReportJob::class, $job);

        try {
            $this->reports->run($job->query_fingerprint);
            $this->fail('the prize family must not seat Completed with zero rows');
        } catch (OperationalReportException $e) {
            $this->assertStringContainsString('no projection', $e->getMessage());
        }

        $failed = OperationalReportJob::query()->findOrFail($job->id);
        $this->assertSame(ReportJobStatus::Failed, $failed->status);
        $this->assertStringContainsString('no projection', (string) $failed->failure_reason);
    }

    // ------------------------------------------------------------------ the invariants

    #[Test]
    public function every_advertised_filter_is_applied_by_the_reader_that_receives_it(): void
    {
        $service = file_get_contents(app_path('Services/Operations/OperationalReportService.php'));
        $advertised = (new ReflectionClass(OperationalReportData::class))->getConstant('ALLOWED_FILTERS');

        $checked = 0;

        foreach ($advertised as $family => $keys) {
            [$table, $method] = self::FAMILY_READERS[$family];
            $body = $this->readerBody($service, $method);

            foreach ($keys as $key) {
                $this->assertStringContainsString(
                    "\$filters['".$key."']",
                    $body,
                    "the $family family advertises '$key' but ".$method.'() never consults it'
                );

                if ($table !== null) {
                    $this->assertFilterTargetsRealData($body, $key, $table, $family);
                }

                $checked++;
            }
        }

        $this->assertSame(21, $checked, 'the advertised-filter total is pinned: changing a whitelist must be measured');
    }

    #[Test]
    public function no_reader_consults_a_filter_that_is_not_advertised(): void
    {
        $service = file_get_contents(app_path('Services/Operations/OperationalReportService.php'));
        $advertised = (new ReflectionClass(OperationalReportData::class))->getConstant('ALLOWED_FILTERS');

        foreach ($advertised as $family => $keys) {
            $body = $this->readerBody($service, self::FAMILY_READERS[$family][1]);
            preg_match_all("/\\\$filters\\['([a-z_]+)'\\]/", $body, $m);

            foreach (array_unique($m[1]) as $used) {
                $this->assertContains(
                    $used,
                    $keys,
                    "$family reads '$used' without the DTO validating it - an unvalidated key reaches SQL"
                );
            }
        }
    }

    #[Test]
    public function no_filter_clause_can_match_every_row(): void
    {
        $service = file_get_contents(app_path('Services/Operations/OperationalReportService.php'));

        $this->assertSame(
            0,
            preg_match_all("/where\(\s*'[a-z_]+'\s*,\s*'like'\s*,\s*'%+'?\s*\)/", $service),
            "a LIKE '%%' predicate is true of every row: it is a filter in shape only"
        );
    }

    #[Test]
    public function the_whitelist_no_longer_carries_the_keys_that_could_never_be_satisfied(): void
    {
        $advertised = (new ReflectionClass(OperationalReportData::class))->getConstant('ALLOWED_FILTERS');

        $gone = [
            ['financial', 'entry_purpose'],
            ['payment', 'channel'],
            ['draw', 'winner_only'],
            ['player', 'risk_level'],
            ['prize', 'draw_id'],
            ['prize', 'tier'],
            ['prize', 'claim_status'],
            ['prize', 'winning_status'],
        ];

        foreach ($gone as [$family, $key]) {
            $this->assertNotContains(
                $key,
                $advertised[$family],
                "$family must not advertise '$key': no writer or no projection backs it"
            );
        }

        $this->assertSame([], $advertised['prize'], 'the prize family has no projection, so it may accept no filter');
    }

    // ------------------------------------------------------------------ helpers

    private ?User $asker = null;

    /** Memoised per test: RefreshDatabase rolls back between tests, so a static would go stale. */
    private function requester(): User
    {
        return $this->asker ??= User::factory()->create();
    }

    private function entryFor(Currency $currency, WalletType $kind = WalletType::Primary): LedgerEntry
    {
        $wallet = Wallet::factory()->create(['type' => $kind, 'currency' => $currency]);

        return LedgerEntry::factory()->create([
            'wallet_id' => $wallet->id,
            'currency' => $currency,
            'type' => LedgerEntryType::Debit,
            'posted_at' => now(),
        ]);
    }

    private function draw(DrawStatus $status): Draw
    {
        return Draw::factory()->create([
            'status' => $status,
            'scheduled_at' => now()->subHour(),
        ]);
    }

    /**
     * Ask for a projection and read its rows. The horizon is widened by a day so rows created
     * "now" are inside it regardless of clock drift across the assertions.
     *
     * @param  array<string, string|int>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(ReportType $type, array $filters = []): Collection
    {
        $data = OperationalReportData::fromInput([
            'requester_user_id' => $this->requester()->id,
            'report_type' => $type,
            'filters' => $filters,
            'horizon_start' => now()->subDays(120),
            'horizon_end' => now()->addDay(),
        ]);

        ['job' => $job] = $this->reports->ask($data);

        return $this->reports->rows($job);
    }

    private function readerBody(string $service, string $method): string
    {
        $start = strpos($service, "private function $method(");
        $this->assertIsInt($start, "the service has no $method() reader");

        $depth = 0;
        $i = $j = strpos($service, '{', $start);
        $end = null;

        while (isset($service[$i])) {
            if ($service[$i] === '{') {
                $depth++;
            } elseif ($service[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
            $i++;
        }

        $this->assertNotNull($end, "unbalanced braces reading $method()");
        unset($j);

        return substr($service, $start, $end - $start);
    }

    /**
     * The clause behind a key must target a column the table has, a relation the model has, or a
     * metadata lane that some writer in app/ stamps. All three are read off the reader's own text.
     */
    private function assertFilterTargetsRealData(string $body, string $key, string $table, string $family): void
    {
        $line = '';

        foreach (explode("\n", $body) as $candidate) {
            if (str_contains($candidate, "\$filters['".$key."']")) {
                $line = $candidate;
                break;
            }
        }

        $this->assertNotSame('', $line, "$family.$key: no clause line found");

        if (preg_match("/whereHas\('(\w+)'/", $line, $m) === 1) {
            $relation = $m[1];
            $model = $this->modelFor($table);
            $this->assertTrue(
                method_exists($model, $relation),
                "$family.$key: ".$model.' has no '.$relation.'() relation'
            );

            return;
        }

        if (preg_match("/where\(\s*'metadata->([a-z_]+)'/", $line, $m) === 1) {
            $lane = $m[1];
            $writers = 0;

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php'
                    && str_contains((string) file_get_contents($file->getPathname()), "'".$lane."' =>")) {
                    $writers++;
                }
            }

            $this->assertGreaterThan(
                0,
                $writers,
                "$family.$key: filters metadata lane '$lane', which no writer in app/ stamps - the filter can only return nothing"
            );

            return;
        }

        if (preg_match("/where\(\s*'([a-z_]+)'/", $line, $m) === 1) {
            $this->assertTrue(
                Schema::hasColumn($table, $m[1]),
                "$family.$key: filters $table.{$m[1]}, a column this schema does not have"
            );

            return;
        }

        $this->fail("$family.$key: the clause is not a column, relation, or metadata-lane filter; extend this check rather than leaving it unproven");
    }

    private function modelFor(string $table): string
    {
        return match ($table) {
            'ledger_entries' => LedgerEntry::class,
            'payments' => Payment::class,
            'draws' => Draw::class,
            'users' => User::class,
            'audit_logs' => AuditLog::class,
            'compliance_cases' => ComplianceCase::class,
            default => $this->fail("no model mapped for table $table"),
        };
    }
}
