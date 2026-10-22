<?php

declare(strict_types=1);

namespace App\Services\ResponsibleGaming;

use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\Exceptions\ResponsibleGamingLimitException;
use App\Models\ResponsibleGamingLimitVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** New writer-owned policy facts only. No historical enrollment or repair. */
final class ResponsibleGamingLimitOwnership
{
    public function terms(ResponsibleGamingLimitVersion $version): string
    {
        return hash('sha256', json_encode([$version->id, $version->user_id, $version->limit_type->value,
            bcadd((string) $version->amount, '0', 2), $version->currency, $version->limit_key,
            $version->effective_from?->toDateTimeString(), $version->effective_to?->toDateTimeString()], JSON_THROW_ON_ERROR));
    }

    public function basis(Collection $versions): string
    {
        return hash('sha256', json_encode($versions->sortBy('id')->map(fn ($v): array => [(int) $v->id, $this->terms($v)])->values()->all(), JSON_THROW_ON_ERROR));
    }

    public function record(ResponsibleGamingLimitVersion $version, ResponsibleGamingLimitData $data, string $basis, int $activationEpoch, ?int $expiryEpoch): void
    {
        if (! $version->wasRecentlyCreated || DB::transactionLevel() < 1) {
            throw ResponsibleGamingLimitException::malformed('Only a freshly written policy version may acquire ownership');
        }
        DB::table('responsible_gaming_limit_ownerships')->insert([
            'version_id' => $version->id, 'request_identity' => $version->limit_key,
            'payload_hash' => $data->payloadHash(), 'terms_hash' => $this->terms($version), 'basis_hash' => $basis,
            'timezone' => config('app.timezone'), 'activation_epoch' => $activationEpoch, 'expiry_epoch' => $expiryEpoch,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function locked(ResponsibleGamingLimitVersion $version, ?ResponsibleGamingLimitData $data = null): ?object
    {
        $owner = DB::table('responsible_gaming_limit_ownerships')->where('version_id', $version->id)->lockForUpdate()->first();
        if ($owner) {
            $this->assertClock($owner);
        }
        if ($owner && (! hash_equals($owner->request_identity, $version->limit_key)
            || ! hash_equals($owner->terms_hash, $this->terms($version))
            || ($data !== null && ! hash_equals($owner->payload_hash, $data->payloadHash())))) {
            throw ResponsibleGamingLimitException::malformed('The original policy request or immutable terms do not match');
        }
        if (! $owner && $data?->requestKey !== null) {
            throw ResponsibleGamingLimitException::malformed('An explicit request has no original policy ownership');
        }

        return $owner;
    }

    private function assertClock(object $owner): void
    {
        if ($owner->timezone !== config('app.timezone')) {
            throw ResponsibleGamingLimitException::malformed('The policy backend timezone changed without original instant migration');
        }
    }
}
