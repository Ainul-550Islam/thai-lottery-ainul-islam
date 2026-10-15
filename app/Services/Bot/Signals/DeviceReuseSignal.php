<?php

declare(strict_types=1);

namespace App\Services\Bot\Signals;

use App\Services\Bot\Contracts\BotSignal;
use App\Services\Bot\DeviceFingerprintService;
use App\Services\Bot\DTOs\SignalResult;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/** Count distinct accounts with successful/trusted evidence, never guessed login IDs. */
final class DeviceReuseSignal implements BotSignal
{
    public function __construct(
        private readonly Repository $config,
        private readonly ConnectionInterface $database,
        private readonly DeviceFingerprintService $fingerprints,
    ) {}

    public function score(Request $request): SignalResult
    {
        $maximum = $this->config->get('bot.device_reuse.max_accounts', 3);
        $days = $this->config->get('bot.device_reuse.lookback_days', 30);
        if (! is_int($maximum) || $maximum < 1 || $maximum > 100 || ! is_int($days) || $days < 1 || $days > 365) {
            throw new InvalidArgumentException('Invalid device-reuse configuration.');
        }
        // Derive server-side; never use an X-Device-Fingerprint/client form value.
        $fingerprint = $this->fingerprints->fingerprint($request);
        $now = Carbon::now();
        $attempts = $this->database->table('authentication_attempts')
            ->select('user_id')
            ->where('device_fingerprint', $fingerprint)
            ->whereNotNull('user_id')
            ->where('outcome', 'succeeded')
            ->whereBetween('attempted_at', [$now->copy()->subDays($days), $now]);
        $trusted = $this->database->table('trusted_devices')
            ->select('user_id')
            ->where('device_fingerprint', $fingerprint)
            ->whereNotNull('user_id')
            ->where('trust_status', 'trusted')
            ->whereNull('revoked_at')
            ->where('registered_at', '<=', $now);
        // UNION (not UNION ALL) deduplicates the same account across both tables.
        $count = $attempts->union($trusted)->limit($maximum + 1)->get()->count();
        $breached = $count > $maximum;

        return new SignalResult('device_reuse', $breached ? 100 : 0, $breached ? ['DEVICE_SHARED_ACCOUNTS'] : []);
    }
}
