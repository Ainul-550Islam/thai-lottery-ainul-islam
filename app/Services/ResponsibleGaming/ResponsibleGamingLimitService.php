<?php

declare(strict_types=1);

namespace App\Services\ResponsibleGaming;

use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\Enums\AuditAction;
use App\Enums\ResponsibleGamingLimitStatus;
use App\Enums\ResponsibleGamingLimitType;
use App\Enums\RiskLevel;
use App\Enums\SelfExclusionStatus;
use App\Exceptions\ResponsibleGamingLimitException;
use App\Exceptions\SelfExclusionException;
use App\Models\AuditLog;
use App\Models\ResponsibleGamingLimit;
use App\Models\ResponsibleGamingLimitVersion;
use App\Models\SelfExclusion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ResponsibleGamingLimitService — create / replace / activate /
 * expire limit VERSIONS with exact bcmath comparisons,
 * stricter-limit precedence, and writer-owned policy verification.
 */
class ResponsibleGamingLimitService
{
    /** Increases cool off for 24 hours before they may bind. */
    public const COOL_OFF_HOURS = 24;

    private const SCALE = 2;

    /**
     * PRONOUNCE a new version. Deterministic by limit key: re-issuing
     * the same pronunciation replays against the same row. Lowering
     * takes effect NOW; raising waits the cooling-off horizon.
     *
     * @return array{version: ResponsibleGamingLimitVersion, replayed: bool}
     */
    public function pronounce(ResponsibleGamingLimitData $data): array
    {
        return DB::transaction(function () use ($data): array {
            $player = app(ResponsibleGamingPlayerLock::class)->lock($data->userId);
            $ownership = app(ResponsibleGamingLimitOwnership::class);

            $key = $data->limitKey();

            /** @var ResponsibleGamingLimitVersion|null $existing */
            $existing = ResponsibleGamingLimitVersion::query()
                ->where('limit_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof ResponsibleGamingLimitVersion) {
                $ownership->locked($existing, $data);

                return ['version' => $existing, 'replayed' => true];
            }

            if (! $player->isActive()) {
                throw ResponsibleGamingLimitException::malformed('Inactive or restricted player cannot pronounce new limits.');
            }

            return $this->applyDecision($data, $key);
        });
    }

    /**
     * Desired current-state choice (rejects explicit caller requestKey; reuses matching head or creates fresh version).
     *
     * @return array{version: ResponsibleGamingLimitVersion, replayed: bool}
     */
    public function choose(ResponsibleGamingLimitData $data): array
    {
        if ($data->requestKey !== null) {
            throw ResponsibleGamingLimitException::malformed('choose() does not accept an explicit request_key.');
        }

        return DB::transaction(function () use ($data): array {
            $player = app(ResponsibleGamingPlayerLock::class)->lock($data->userId);
            $ownership = app(ResponsibleGamingLimitOwnership::class);

            if (! $player->isActive()) {
                throw ResponsibleGamingLimitException::malformed('Inactive or restricted player cannot choose new limits.');
            }

            $activeHeads = $this->lockedActiveHeads($data->userId, $data->type, $data->currency);
            foreach ($activeHeads as $head) {
                $ownership->locked($head);
            }

            $pendingHeads = $this->lockedPendingHeads($data->userId, $data->type, $data->currency);
            foreach ($pendingHeads as $head) {
                $ownership->locked($head);
            }

            if ($data->effectiveFrom === null && $data->effectiveTo === null) {
                if ($activeHeads->count() === 1 && $pendingHeads->isEmpty()
                    && bccomp((string) $activeHeads->first()->amount, $data->amount, self::SCALE) === 0) {
                    return ['version' => $activeHeads->first(), 'replayed' => true];
                }

                if ($pendingHeads->count() === 1
                    && bccomp((string) $pendingHeads->first()->amount, $data->amount, self::SCALE) === 0) {
                    return ['version' => $pendingHeads->first(), 'replayed' => true];
                }
            }

            $tailId = (int) (ResponsibleGamingLimitVersion::query()
                ->where('user_id', $data->userId)
                ->where('limit_type', $data->type->value)
                ->where('currency', $data->currency)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('id') ?? 0);

            $basisHash = $ownership->basis($activeHeads->concat($pendingHeads));
            $key = hash('sha256', implode('|', [
                'choice',
                (string) $data->userId,
                $data->type->value,
                $data->currency,
                (string) $tailId,
                $basisHash,
                $data->payloadHash(),
            ]));

            return $this->applyDecision($data, $key, $activeHeads, $pendingHeads);
        });
    }

    /**
     * Convenience helper to set or pronounce daily deposit limit.
     */
    public function setDailyDepositLimit(int $userId, string $amount, string $currency = 'THB'): ResponsibleGamingLimitVersion
    {
        $dto = ResponsibleGamingLimitData::fromInput([
            'user_id' => $userId,
            'limit_type' => ResponsibleGamingLimitType::DailyDeposit->value,
            'amount' => $amount,
            'currency' => strtoupper($currency),
        ]);

        return $this->choose($dto)['version'];
    }

    public function updateDailyDepositLimit(int $userId, string $amount, string $currency = 'THB'): ResponsibleGamingLimitVersion
    {
        return $this->setDailyDepositLimit($userId, $amount, $currency);
    }

    public function getActiveDailyDepositLimit(int $userId, string $currency = 'THB'): ?string
    {
        $limit = $this->bindingLimitFor($userId, ResponsibleGamingLimitType::DailyDeposit, strtoupper($currency), DB::transactionLevel() > 0);

        return $limit !== null ? (string) $limit->amount : null;
    }

    public function setSingleBetLimit(int $userId, string $amount, string $currency = 'THB'): ResponsibleGamingLimitVersion
    {
        $dto = ResponsibleGamingLimitData::fromInput([
            'user_id' => $userId,
            'limit_type' => ResponsibleGamingLimitType::SingleBet->value,
            'amount' => $amount,
            'currency' => strtoupper($currency),
        ]);

        return $this->choose($dto)['version'];
    }

    public function getActiveSingleBetLimit(int $userId, string $currency = 'THB'): ?string
    {
        $limit = $this->bindingLimitFor($userId, ResponsibleGamingLimitType::SingleBet, strtoupper($currency), DB::transactionLevel() > 0);

        return $limit !== null ? (string) $limit->amount : null;
    }

    public function setDailyWageringLimit(int $userId, string $amount, string $currency = 'THB'): ResponsibleGamingLimitVersion
    {
        $dto = ResponsibleGamingLimitData::fromInput([
            'user_id' => $userId,
            'limit_type' => ResponsibleGamingLimitType::DailyWagering->value,
            'amount' => $amount,
            'currency' => strtoupper($currency),
        ]);

        return $this->choose($dto)['version'];
    }

    public function getActiveDailyWageringLimit(int $userId, string $currency = 'THB'): ?string
    {
        $limit = $this->bindingLimitFor($userId, ResponsibleGamingLimitType::DailyWagering, strtoupper($currency), DB::transactionLevel() > 0);

        return $limit !== null ? (string) $limit->amount : null;
    }

    /**
     * The sweep: every Pending increase whose cooling-off has run its
     * hour, and which still out-runs the then-current ceiling, seats
     * Active (its ancestor Replaced in the same breath).
     */
    public function activateDuePending(int $limit = 100): int
    {
        $seated = 0;

        ResponsibleGamingLimitVersion::query()
            ->where('limit_status', ResponsibleGamingLimitStatus::Pending->value)
            ->where('effective_from', '<=', now())
            ->orderBy('effective_from')
            ->limit($limit)
            ->get(['id', 'user_id'])
            ->each(function (ResponsibleGamingLimitVersion $pending) use (&$seated): void {
                $versionId = (int) $pending->id;
                $ownerId = (int) $pending->user_id;

                DB::transaction(function () use ($versionId, $ownerId, &$seated): void {
                    $playerLock = app(ResponsibleGamingPlayerLock::class);
                    $ownership = app(ResponsibleGamingLimitOwnership::class);

                    $player = $playerLock->lock($ownerId);

                    /** @var ResponsibleGamingLimitVersion|null $locked */
                    $locked = ResponsibleGamingLimitVersion::query()->lockForUpdate()->find($versionId);

                    if (! $locked instanceof ResponsibleGamingLimitVersion
                        || $locked->limit_status !== ResponsibleGamingLimitStatus::Pending) {
                        return;
                    }

                    $playerLock->assertOwner($ownerId, (int) $locked->user_id);

                    try {
                        $ownerRecord = $ownership->locked($locked);
                    } catch (ResponsibleGamingLimitException) {
                        return;
                    }

                    if ($ownerRecord === null) {
                        return;
                    }

                    $nowEpoch = Carbon::now()->getTimestamp();

                    if ((int) $ownerRecord->activation_epoch > $nowEpoch) {
                        return;
                    }
                    if ($ownerRecord->expiry_epoch !== null && (int) $ownerRecord->expiry_epoch <= $nowEpoch) {
                        $locked->limit_status = ResponsibleGamingLimitStatus::Cancelled;
                        $locked->cancelled_at = now();
                        $locked->save();
                        $this->scribble($locked, 'pending increase cancelled — expired before activation');

                        return;
                    }

                    if (! $player->isActive() || $this->isPlayerProtected($ownerId)) {
                        return;
                    }

                    $activeHeads = $this->lockedActiveHeads($ownerId, $locked->limit_type, $locked->currency);
                    foreach ($activeHeads as $head) {
                        try {
                            $ownership->locked($head);
                        } catch (ResponsibleGamingLimitException) {
                            return;
                        }
                    }

                    $pendingHeads = $this->lockedPendingHeads($ownerId, $locked->limit_type, $locked->currency);
                    if ($pendingHeads->count() !== 1) {
                        return;
                    }

                    if (! hash_equals((string) $ownerRecord->basis_hash, $ownership->basis($activeHeads))) {
                        $locked->limit_status = ResponsibleGamingLimitStatus::Cancelled;
                        $locked->cancelled_at = now();
                        $locked->save();
                        $this->scribble($locked, 'pending increase cancelled — active basis changed during cooling-off');

                        return;
                    }

                    $ceiling = $this->strictestHead($activeHeads);

                    if ($ceiling instanceof ResponsibleGamingLimitVersion
                        && bccomp((string) $locked->amount, (string) $ceiling->amount, self::SCALE) <= 0) {
                        $locked->limit_status = ResponsibleGamingLimitStatus::Cancelled;
                        $locked->cancelled_at = now();
                        $locked->save();
                        $this->scribble($locked, 'pending increase cancelled — no longer looser than the active ceiling');

                        return;
                    }

                    foreach ($activeHeads as $active) {
                        $this->markReplaced($active, $locked);
                    }

                    $locked->limit_status = ResponsibleGamingLimitStatus::Active;
                    $locked->activated_at = now();
                    $locked->save();
                    $this->syncLegacySummaryIfApplicable($ownerId, $locked->limit_type, $locked->currency, (string) $locked->amount);
                    $this->scribble($locked, 'pending increase seated after cooling-off');
                    $seated++;
                });
            });

        return $seated;
    }

    /**
     * Expire versions whose effective horizon physically ended.
     */
    public function expireStale(int $limit = 100): int
    {
        $expired = 0;

        ResponsibleGamingLimitVersion::query()
            ->where('limit_status', ResponsibleGamingLimitStatus::Active->value)
            ->whereNotNull('effective_to')
            ->where('effective_to', '<=', now())
            ->orderBy('effective_to')
            ->limit($limit)
            ->get(['id', 'user_id'])
            ->each(function (ResponsibleGamingLimitVersion $v) use (&$expired): void {
                $versionId = (int) $v->id;
                $ownerId = (int) $v->user_id;

                DB::transaction(function () use ($versionId, $ownerId, &$expired): void {
                    $playerLock = app(ResponsibleGamingPlayerLock::class);
                    $ownership = app(ResponsibleGamingLimitOwnership::class);

                    $playerLock->lock($ownerId);

                    /** @var ResponsibleGamingLimitVersion|null $locked */
                    $locked = ResponsibleGamingLimitVersion::query()->lockForUpdate()->find($versionId);

                    if (! $locked instanceof ResponsibleGamingLimitVersion
                        || $locked->limit_status !== ResponsibleGamingLimitStatus::Active) {
                        return;
                    }

                    $playerLock->assertOwner($ownerId, (int) $locked->user_id);

                    $ownerRecord = $ownership->locked($locked);
                    $nowEpoch = Carbon::now()->getTimestamp();

                    if ($ownerRecord === null || $ownerRecord->expiry_epoch === null || (int) $ownerRecord->expiry_epoch > $nowEpoch) {
                        return;
                    }

                    $locked->limit_status = ResponsibleGamingLimitStatus::Expired;
                    $locked->expired_at = now();
                    $locked->save();
                    $this->scribble($locked, 'limit version expired by server horizon');
                    $expired++;
                });
            });

        return $expired;
    }

    /**
     * The player's binding ceiling per type = the LOWEST amount among
     * Active versions on file — exact bcmath comparison, never float math.
     */
    public function bindingLimitFor(
        int $userId,
        ResponsibleGamingLimitType $type,
        ?string $currency = null,
        bool $forUpdate = false,
    ): ?ResponsibleGamingLimitVersion {
        $now = now();
        $nowEpoch = $now->getTimestamp();
        $ownership = app(ResponsibleGamingLimitOwnership::class);

        $query = ResponsibleGamingLimitVersion::query()
            ->where('user_id', $userId)
            ->where('limit_type', $type->value)
            ->where('limit_status', ResponsibleGamingLimitStatus::Active->value)
            ->where('effective_from', '<=', $now)
            ->where(static function ($q) use ($now): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $now);
            });

        if ($currency !== null && $currency !== '') {
            $query->where('currency', strtoupper(trim($currency)));
        }

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $candidates = $query->orderBy('id')->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $valid = [];
        foreach ($candidates as $row) {
            if ($forUpdate) {
                $ownerRecord = $ownership->locked($row);
                if ($ownerRecord !== null) {
                    if ((int) $ownerRecord->activation_epoch > $nowEpoch) {
                        continue;
                    }
                    if ($ownerRecord->expiry_epoch !== null && (int) $ownerRecord->expiry_epoch <= $nowEpoch) {
                        continue;
                    }
                }
            }
            $valid[] = $row;
        }

        if ($valid === []) {
            return null;
        }

        $strictest = $valid[0];
        foreach ($valid as $row) {
            if (bccomp((string) $row->amount, (string) $strictest->amount, self::SCALE) < 0) {
                $strictest = $row;
            }
        }

        return $strictest;
    }

    /**
     * @param  Collection<int, ResponsibleGamingLimitVersion>|null  $activeHeads
     * @param  Collection<int, ResponsibleGamingLimitVersion>|null  $pendingHeads
     * @return array{version: ResponsibleGamingLimitVersion, replayed: bool}
     */
    private function applyDecision(
        ResponsibleGamingLimitData $data,
        string $key,
        ?Collection $activeHeads = null,
        ?Collection $pendingHeads = null,
    ): array {
        $ownership = app(ResponsibleGamingLimitOwnership::class);

        if ($activeHeads === null) {
            $activeHeads = $this->lockedActiveHeads($data->userId, $data->type, $data->currency);
            foreach ($activeHeads as $head) {
                $ownership->locked($head);
            }
        }

        if ($pendingHeads === null) {
            $pendingHeads = $this->lockedPendingHeads($data->userId, $data->type, $data->currency);
            foreach ($pendingHeads as $head) {
                $ownership->locked($head);
            }
        }

        $active = $this->strictestHead($activeHeads);
        $isStricter = $active === null || bccomp($data->amount, (string) $active->amount, self::SCALE) <= 0;

        if (! $isStricter) {
            if ($pendingHeads->isNotEmpty()) {
                /** @var ResponsibleGamingLimitVersion $firstPending */
                $firstPending = $pendingHeads->first();
                throw ResponsibleGamingLimitException::cooldownViolation(
                    $data->type->value,
                    $firstPending->effective_from?->toIso8601String() ?? 'unset',
                );
            }

            $exclusion = app(SelfExclusionService::class)->currentActiveFor($data->userId, true);
            if ($exclusion instanceof SelfExclusion) {
                throw SelfExclusionException::activeExclusion($data->userId, (string) $exclusion->ends_at->toIso8601String());
            }

            if ($this->isPlayerProtected($data->userId)) {
                throw ResponsibleGamingLimitException::malformed('Protected or cooling-off player cannot loosen limits.');
            }
        }

        $now = Carbon::now();
        $nowEpoch = $now->getTimestamp();
        $requestedStartEpoch = $data->effectiveFrom?->getTimestamp() ?? $nowEpoch;
        $requestedEndEpoch = $data->effectiveTo?->getTimestamp();

        if ($requestedEndEpoch !== null && $requestedEndEpoch <= $nowEpoch) {
            throw ResponsibleGamingLimitException::malformed('effective_to must be in the future.');
        }

        if ($isStricter) {
            $activationEpoch = max($nowEpoch, $requestedStartEpoch);
            if ($requestedEndEpoch !== null && $requestedEndEpoch <= $activationEpoch) {
                throw ResponsibleGamingLimitException::malformed('effective_to must be after effective_from.');
            }

            foreach ($pendingHeads as $pending) {
                $pending->limit_status = ResponsibleGamingLimitStatus::Cancelled;
                $pending->cancelled_at = now();
                $pending->save();
                $this->scribble($pending, sprintf('pending increase cancelled by stricter pronouncement [%s]', substr($key, 0, 12)));
            }

            $status = $activationEpoch > $nowEpoch
                ? ResponsibleGamingLimitStatus::Pending
                : ResponsibleGamingLimitStatus::Active;

            $basisHash = $ownership->basis($activeHeads);

            if ($status === ResponsibleGamingLimitStatus::Active) {
                foreach ($activeHeads as $prior) {
                    $this->markReplaced($prior, $key);
                }
            }

            $version = $this->createOwnedVersion(
                $data,
                $key,
                $status,
                $activationEpoch,
                $requestedEndEpoch,
                $basisHash,
            );

            if ($status === ResponsibleGamingLimitStatus::Active) {
                $this->syncLegacySummaryIfApplicable($data->userId, $data->type, $data->currency, $data->amount);
            }

            $this->scribble($version, sprintf(
                'stricter %s limit pronounced (%s)',
                $data->type->value,
                $data->amount,
            ));

            return ['version' => $version, 'replayed' => false];
        }

        $minCoolOffEpoch = $nowEpoch + (self::COOL_OFF_HOURS * 3600);
        $activationEpoch = max($minCoolOffEpoch, $requestedStartEpoch);

        if ($requestedEndEpoch !== null && $requestedEndEpoch <= $activationEpoch) {
            throw ResponsibleGamingLimitException::malformed('effective_to must be after the 24-hour cooling-off window.');
        }

        $basisHash = $ownership->basis($activeHeads);

        $version = $this->createOwnedVersion(
            $data,
            $key,
            ResponsibleGamingLimitStatus::Pending,
            $activationEpoch,
            $requestedEndEpoch,
            $basisHash,
        );

        $this->scribble($version, sprintf(
            'pending increase %s limit pronounced (%s)',
            $data->type->value,
            $data->amount,
        ));

        return ['version' => $version, 'replayed' => false];
    }

    private function createOwnedVersion(
        ResponsibleGamingLimitData $data,
        string $key,
        ResponsibleGamingLimitStatus $status,
        int $activationEpoch,
        ?int $expiryEpoch,
        string $basisHash,
    ): ResponsibleGamingLimitVersion {
        $zone = (string) config('app.timezone', 'UTC');
        $effectiveFrom = Carbon::createFromTimestamp($activationEpoch, $zone);
        $effectiveTo = $expiryEpoch !== null ? Carbon::createFromTimestamp($expiryEpoch, $zone) : null;

        $version = new ResponsibleGamingLimitVersion;
        $version->fill([
            'user_id' => $data->userId,
            'limit_type' => $data->type,
            'amount' => $data->amount,
            'currency' => $data->currency,
            'limit_key' => $key,
            'limit_status' => $status,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'activated_at' => $status === ResponsibleGamingLimitStatus::Active ? now() : null,
        ]);
        $version->save();

        app(ResponsibleGamingLimitOwnership::class)->record(
            $version,
            $data,
            $basisHash,
            $activationEpoch,
            $expiryEpoch,
        );

        return $version;
    }

    /**
     * @return Collection<int, ResponsibleGamingLimitVersion>
     */
    private function lockedActiveHeads(int $userId, ResponsibleGamingLimitType $type, string $currency): Collection
    {
        $now = now();

        return ResponsibleGamingLimitVersion::query()
            ->where('user_id', $userId)
            ->where('limit_type', $type->value)
            ->where('currency', strtoupper(trim($currency)))
            ->where('limit_status', ResponsibleGamingLimitStatus::Active->value)
            ->where('effective_from', '<=', $now)
            ->where(static function ($q) use ($now): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $now);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @return Collection<int, ResponsibleGamingLimitVersion>
     */
    private function lockedPendingHeads(int $userId, ResponsibleGamingLimitType $type, string $currency): Collection
    {
        return ResponsibleGamingLimitVersion::query()
            ->where('user_id', $userId)
            ->where('limit_type', $type->value)
            ->where('currency', strtoupper(trim($currency)))
            ->where('limit_status', ResponsibleGamingLimitStatus::Pending->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, ResponsibleGamingLimitVersion>  $heads
     */
    private function strictestHead(Collection $heads): ?ResponsibleGamingLimitVersion
    {
        if ($heads->isEmpty()) {
            return null;
        }

        /** @var ResponsibleGamingLimitVersion $strictest */
        $strictest = $heads->first();
        foreach ($heads as $head) {
            if (bccomp((string) $head->amount, (string) $strictest->amount, self::SCALE) < 0) {
                $strictest = $head;
            }
        }

        return $strictest;
    }

    private function isPlayerProtected(int $userId): bool
    {
        $now = now();

        $legacy = ResponsibleGamingLimit::withTrashed()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if ($legacy instanceof ResponsibleGamingLimit) {
            if ($legacy->self_excluded_until !== null && $legacy->self_excluded_until->isFuture()) {
                return true;
            }
            if ($legacy->cool_off_until !== null && $legacy->cool_off_until->isFuture()) {
                return true;
            }
        }

        return app(SelfExclusionService::class)->hasActiveExclusion($userId, true);
    }

    private function syncLegacySummaryIfApplicable(
        int $userId,
        ResponsibleGamingLimitType $type,
        string $currency,
        string $amount,
    ): void {
        $configuredCurrency = strtoupper((string) config('betting.currency', 'THB'));
        if (strtoupper($currency) !== $configuredCurrency) {
            return;
        }

        $column = match ($type) {
            ResponsibleGamingLimitType::DailyDeposit => 'daily_deposit_limit',
            ResponsibleGamingLimitType::SingleBet => 'single_bet_limit',
            ResponsibleGamingLimitType::DailyWagering => 'daily_wagering_limit',
            default => null,
        };

        if ($column === null) {
            return;
        }

        $summary = ResponsibleGamingLimit::query()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if ($summary instanceof ResponsibleGamingLimit) {
            $summary->{$column} = $amount;
            $summary->save();
        }
    }

    private function scribble(ResponsibleGamingLimitVersion $version, string $note): void
    {
        $anchor = 'rgl-audit:'.(string) $version->limit_key.':'.$version->limit_status->value.':'.$note;

        $exists = AuditLog::query()
            ->where('auditable_type', ResponsibleGamingLimitVersion::class)
            ->where('created_at', '>=', now()->subHours(48))
            ->anchoredBy($anchor)
            ->exists();

        if ($exists) {
            return;
        }

        AuditLog::create([
            'user_id' => $version->user_id,
            'action' => AuditAction::Update,
            'auditable_type' => ResponsibleGamingLimitVersion::class,
            'auditable_id' => $version->id,
            'metadata' => [
                'lane' => 'responsible-gaming-limit',
                'risk_rating' => RiskLevel::Medium->value,
                'limit_key' => $version->limit_key,
                'limit_type' => $version->limit_type->value,
                'limit_status' => $version->limit_status->value,
                AuditLog::ANCHOR_KEY => $anchor,
                'note' => $note,
            ],
        ]);
    }

    private function markReplaced(ResponsibleGamingLimitVersion $old, ResponsibleGamingLimitVersion|string $new): void
    {
        $newKey = $new instanceof ResponsibleGamingLimitVersion ? (string) $new->limit_key : $new;
        $old->limit_status = ResponsibleGamingLimitStatus::Replaced;
        $old->replaced_by_key = $newKey;
        $old->save();
        $this->scribble($old, sprintf('superseded by [%s]', substr($newKey, 0, 12)));
    }
}
