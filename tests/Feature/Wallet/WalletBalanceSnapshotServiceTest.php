<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletBalanceSnapshot;
use App\Services\Wallet\WalletBalanceSnapshotService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * The snapshot job writes ONE row per wallet per day, and it is idempotent.
 *
 * WHY IDEMPOTENCE IS THE WHOLE TEST
 * The job is schedulable, manually runnable and retryable. If a second run produced a
 * second row for the same day, the trend calculation would compare a day against
 * itself plus noise, and every "your balance is up 5%" figure would be wrong in a way
 * nobody could reproduce. A unique key is the only durable answer, and this class
 * proves it holds at the service level as well.
 */
final class WalletBalanceSnapshotServiceTest extends TestCase
{
    use DatabaseTruncation;

    private WalletBalanceSnapshotService $snapshots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshots = app(WalletBalanceSnapshotService::class);
    }

    private function memberWithWallet(string $balance = '100.00'): array
    {
        $user = User::factory()->create();

        $wallet = new Wallet();
        $wallet->user_id = $user->id;
        $wallet->type = WalletType::Main;
        $wallet->currency = \App\Enums\Currency::THB;
        $wallet->balance = $balance;
        $wallet->locked_balance = '0.00';
        $wallet->save();

        return [$user, $wallet];
    }

    public function test_a_snapshot_records_the_balance_it_found(): void
    {
        [$user, $wallet] = $this->memberWithWallet('250.75');

        $this->snapshots->captureAll();

        $row = WalletBalanceSnapshot::query()->where('member_id', $user->id)->first();

        $this->assertNotNull($row);
        $this->assertSame('250.75', (string) $row->balance);
        $this->assertSame(WalletType::Main, $row->wallet_type);
    }

    public function test_running_twice_in_one_day_produces_one_row(): void
    {
        [$user, $wallet] = $this->memberWithWallet('50.00');

        $this->snapshots->captureAll();
        $this->snapshots->captureAll();
        $this->snapshots->captureAll();

        $this->assertSame(
            1,
            WalletBalanceSnapshot::query()->where('member_id', $user->id)->count(),
            'A second snapshot for the same day would corrupt the trend baseline.',
        );
    }

    public function test_the_snapshot_writes_to_its_own_table_only(): void
    {
        [$user, $wallet] = $this->memberWithWallet('75.00');

        $beforeBalance = (string) $wallet->fresh()->balance;
        $beforeLedger = \App\Models\LedgerEntry::query()->count();

        $this->snapshots->captureAll();

        $this->assertSame($beforeBalance, (string) $wallet->fresh()->balance, 'A snapshot must not touch the wallet.');
        $this->assertSame($beforeLedger, \App\Models\LedgerEntry::query()->count(), 'A snapshot must not write the ledger.');
    }

    public function test_the_job_is_idempotent_too(): void
    {
        [$user, $wallet] = $this->memberWithWallet('10.00');

        \App\Jobs\Wallet\SnapshotWalletBalancesJob::dispatchSync();
        \App\Jobs\Wallet\SnapshotWalletBalancesJob::dispatchSync();

        $this->assertSame(1, WalletBalanceSnapshot::query()->where('member_id', $user->id)->count());
    }

    public function test_the_command_runs_and_reports_success(): void
    {
        $this->memberWithWallet('5.00');

        $this->artisan('wallet:snapshot-balances')->assertSuccessful();
    }

    public function test_a_snapshot_is_stamped_with_its_capture_date(): void
    {
        [$user, $wallet] = $this->memberWithWallet('1.00');

        $this->snapshots->captureAll();

        $row = WalletBalanceSnapshot::query()->where('member_id', $user->id)->firstOrFail();

        $this->assertNotNull($row->snapshot_date);
        $this->assertNotNull($row->captured_at);
        $this->assertSame(now()->toDateString(), $row->snapshot_date->toDateString());
    }

    public function test_a_member_with_no_wallet_is_skipped_without_erroring(): void
    {
        // A member mid-registration has no wallet yet. The sweep must not fail on them,
        // or one incomplete account would stop the nightly job for everybody.
        User::factory()->create();

        $this->artisan('wallet:snapshot-balances')->assertSuccessful();
    }

    public function test_capture_for_one_member_does_not_touch_another(): void
    {
        [$mine] = $this->memberWithWallet('10.00');
        [$theirs] = $this->memberWithWallet('20.00');

        $this->snapshots->captureForMember((int) $mine->id);

        $this->assertSame(1, WalletBalanceSnapshot::query()->where('member_id', $mine->id)->count());
        $this->assertSame(0, WalletBalanceSnapshot::query()->where('member_id', $theirs->id)->count());
    }

    public function test_the_baseline_lookup_returns_a_snapshot_on_or_before_the_horizon(): void
    {
        [$user] = $this->memberWithWallet('42.00');

        $this->snapshots->captureAll(now()->subDays(7));

        $baseline = $this->snapshots->baselineFor(
            (int) $user->id,
            WalletType::Main,
            now(),
        );

        $this->assertNotNull($baseline);
        $this->assertSame('42.00', (string) $baseline->balance);
    }
}
