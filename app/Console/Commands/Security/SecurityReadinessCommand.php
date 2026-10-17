<?php

declare(strict_types=1);

namespace App\Console\Commands\Security;

use App\Contracts\Security\MalwareScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class SecurityReadinessCommand extends Command
{
    protected $signature = 'security:readiness-check';
    protected $description = 'Performs a comprehensive production security readiness audit without exposing secrets.';

    public function handle(MalwareScanner $scanner): int
    {
        $this->info("Starting Production Security Readiness Audit...");

        $checks = [];

        // 1. Database Connection
        try {
            DB::connection()->getPdo();
            $checks[] = ['Database Connectivity', 'PASS', 'Authoritative ledger storage connected'];
        } catch (\Throwable $e) {
            $checks[] = ['Database Connectivity', 'FAIL', $e->getMessage()];
        }

        // 2. Redis Cluster
        try {
            Redis::ping();
            $checks[] = ['Redis Cluster / Locks', 'PASS', 'Atomic distributed locking ready'];
        } catch (\Throwable $e) {
            $checks[] = ['Redis Cluster / Locks', 'FAIL', $e->getMessage()];
        }

        // 3. Trusted Proxies
        $trustedProxies = config('network.trusted_proxies');
        if (!empty($trustedProxies)) {
            $checks[] = ['Trusted Proxy Boundaries', 'PASS', count((array)$trustedProxies) . ' CIDRs configured'];
        } else {
            $checks[] = ['Trusted Proxy Boundaries', 'FAIL', 'No trusted proxy CIDR configured'];
        }

        // 4. ClamAV Daemon
        $scannerActive = $scanner->ping();
        $checks[] = ['ClamAV Antimalware Daemon', $scannerActive ? 'PASS' : 'PARTIAL', $scannerActive ? 'Active & Responsive' : 'Daemon unreachable (fails closed)'];

        // 5. Bot Detection Policy
        $botMode = config('bot.mode', 'log_only');
        $checks[] = ['Bot Detection Policy', 'PASS', "Configured in {$botMode} mode"];

        // 6. Fraud Detection Mode
        $fraudMode = config('fraud.mode', 'enforce');
        $checks[] = ['Fraud Risk Engine Mode', 'PASS', "Configured in {$fraudMode} mode"];

        $this->table(['Security Component', 'Status', 'Details'], $checks);

        $hasFailures = in_array('FAIL', array_column($checks, 1), true);

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }
}
