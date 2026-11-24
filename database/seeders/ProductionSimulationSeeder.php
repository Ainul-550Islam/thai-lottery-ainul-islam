<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\BlockedIp;
use App\Models\Draw;
use App\Models\FraudAlert;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Config\StrictBoolean;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ProductionSimulationSeeder extends Seeder
{
    public function run(): void
    {
        // Fail closed. This seeder writes a known administrator and a funded wallet, so it must
        // never touch a production database unless an operator opted in on purpose. The guard sits
        // before any database work, and the switch is read strictly: only an exact `true` opens it
        // (see StrictBoolean), never `1`, `yes` or a stray value.
        if (app()->environment('production')
            && ! StrictBoolean::orClosed(env('ALLOW_PRODUCTION_SIMULATION_SEEDER'))) {
            throw new RuntimeException(
                'ProductionSimulationSeeder refused to run in production. '
                .'Set ALLOW_PRODUCTION_SIMULATION_SEEDER=true only for a deliberate simulation environment.'
            );
        }

        DB::transaction(function () {
            // 1. Seed System Chart of Accounts
            $this->call(SystemChartOfAccountsSeeder::class);

            // 2. Seed Multi-Tier Agent Hierarchy
            $this->call(AgentHierarchySeeder::class);

            // 3. Seed Certified GLO Draw
            $draw = Draw::firstOrCreate(
                ['draw_number' => 'GLO-20261016-01'],
                [
                    'id' => (string) Str::uuid(),
                    'draw_date' => '2026-10-16',
                    'status' => 'OPEN',
                    'ticket_price' => '80.0000',
                    'first_prize_payout' => '6000000.0000',
                    'sales_closes_at' => now()->addHours(2),
                ]
            );

            // 4. Seed Active Admin & SecOps Users
            $admin = User::firstOrCreate(
                ['email' => 'admin@thai-lottery.internal'],
                [
                    'id' => (string) Str::uuid(),
                    'name' => 'Ainul Islam (Chief Security Officer)',
                    'password' => bcrypt('SecOpsMasterPass2026!'),
                    'role' => 'admin',
                    'is_admin' => true,
                    'mfa_enabled' => true,
                ]
            );

            // 5. Seed Demonstration Wallets with Initial Balances
            Wallet::firstOrCreate(
                ['user_id' => $admin->id, 'wallet_type' => 'main'],
                [
                    'id' => (string) Str::uuid(),
                    'currency' => 'THB',
                    'balance' => '1000000.0000',
                    'locked_balance' => '0.0000',
                    'version' => 1,
                    'is_active' => true,
                ]
            );

            // 6. Seed Sample Active IP Blocklist Rules
            BlockedIp::firstOrCreate(
                ['ip_or_cidr' => '198.51.100.0/24'],
                [
                    'id' => (string) Str::uuid(),
                    'ip_version' => 4,
                    'is_cidr' => true,
                    'reason_code' => 'THREAT_FEED',
                    'source' => 'AUTOMATED',
                    'is_active' => true,
                    'notes' => 'TOR exit node & automated scraping range',
                ]
            );

            // 7. Seed Sample Open Fraud Alert for Review Queue
            FraudAlert::firstOrCreate(
                ['idempotency_event_key' => 'evt-sample-fraud-01'],
                [
                    'id' => (string) Str::uuid(),
                    'event_type' => 'WITHDRAWAL',
                    'user_id' => $admin->id,
                    'amount' => '250000.0000',
                    'currency' => 'THB',
                    'risk_score' => 85,
                    'decision' => 'HOLD',
                    'reason_codes' => ['RAPID_DEPOSIT_TO_WITHDRAWAL', 'HIGH_VALUE_THRESHOLD'],
                    'status' => 'OPEN',
                    'raw_payload' => ['bank' => 'SCB', 'account' => 'xxx-xxx-1234'],
                ]
            );
        });
    }
}
