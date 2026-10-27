<?php

declare(strict_types=1);

namespace App\Services\ResponsibleGaming;

use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\Enums\AuditAction;
use App\Enums\ResponsibleGamingLimitStatus;
use App\Enums\ResponsibleGamingLimitType;
use App\Enums\RiskLevel;
use App\Enums\SelfExclusionStatus;
use App\Enums\UserStatus;
use App\Exceptions\ResponsibleGamingLimitException;
use App\Exceptions\SelfExclusionException;
use App\Models\AuditLog;
use App\Models\ResponsibleGamingLimit;
use App\Models\ResponsibleGamingLimitOwnership;
use App\Models\ResponsibleGamingLimitVersion;
use App\Models\User;
use App\Models\SelfExclusion;
use Illuminate\Support\Facades\DB;

/**
 * ResponsibleGamingLimitService — create / replace / activate /
 * expire limit VERSIONS with exact bcmath comparisons and
 * stricter-limit precedence:
 *
 * - A DECREASE binds at once (player's choice to be safer).
 * - AN INCREASE, or a brand-new loosening, waits out the reserve
 *   cool-off (24h) as a Pending version; it activates only when the
 *   sweep seats it after the horizon and it still agrees with the
 *   then-current ceiling (fork-checked).
 * - Versions are never edited: Active → Replaced | Expired;
 *   Pending → Active | Cancelled. The desk's audit stamps every
 *   passage, by anchor, exactly once per act.
 */
class ResponsibleGamingLimitService
{
    /** Increases cool off for 24 hours before they may bind. */
    private const COOLING_OFF_HOURS = 24;

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
            $key = $data->limitKey();

            // ── 1. IDENTITY. An explicit request key IS the identity of the
            //    request, so a replay must reproduce the same definition. A key
            //    that comes back carrying a different amount, type, currency or
            //    window is not a replay: it is an attempt to rewrite a decision
            //    the player already made, and it is refused without touching a
            //    single row.
            /** @var ResponsibleGamingLimitVersion|null $existing */
            $existing = ResponsibleGamingLimitVersion::query()->where('limit_key', $key)->first();

            if ($existing instanceof ResponsibleGamingLimitVersion) {
                // An explicit request key replays its row whatever state that
                // row reached: re-submitting a request that was cancelled must
                // not silently resurrect it, and must never fork a second row
                // under the same identity.
                if ($data->requestKey !== null) {
                    $this->assertSameDefinition($existing, $data);

                    return ['version' => $existing, 'replayed' => true];
                }

                // A payload-identified request replays only while the row is
                // still in force. A sealed one is history, and re-choosing a
                // value the player once held is a new decision - so it gets a
                // fresh generation of the key rather than the unique index
                // swallowing it.
                if (! $existing->limit_status->isTerminal()) {
                    return ['version' => $existing, 'replayed' => true];
                }

                [$key, $liveRow] = $this->nextGeneration($key);

                if ($liveRow instanceof ResponsibleGamingLimitVersion) {
                    return ['version' => $liveRow, 'replayed' => true];
                }
            }

            /** @var ResponsibleGamingLimitVersion|null $active */
            $active = ResponsibleGamingLimitVersion::query()
                ->where('user_id', $data->userId)
                ->where('limit_type', $data->type->value)
                ->where('currency', $data->currency)
                ->where('limit_status', ResponsibleGamingLimitStatus::Active->value)
                ->lockForUpdate()
                ->first();

            /** @var ResponsibleGamingLimitVersion|null $pending */
            $pending = ResponsibleGamingLimitVersion::query()
                ->where('user_id', $data->userId)
                ->where('limit_type', $data->type->value)
                ->where('currency', $data->currency)
                ->where('limit_status', ResponsibleGamingLimitStatus::Pending->value)
                ->lockForUpdate()
                ->first();

            // ── 2. A payload that has already been pronounced and is still in
            //    force is a replay, even without an explicit key. A sealed
            //    version (replaced / cancelled / expired) is history: asking for
            //    the same ceiling again is a NEW decision and gets its own row,
            //    otherwise the player could never choose a value they once held.
            if ($pending instanceof ResponsibleGamingLimitVersion
                && bccomp($data->amount, (string) $pending->amount, 2) === 0) {
                return ['version' => $pending, 'replayed' => true];
            }

            $isStricter = $active === null || bccomp($data->amount, (string) $active->amount, 2) <= 0;

            // ── 3. SELF-EXCLUSION IS THE MASTER'S VOICE. No ceiling may be
            //    loosened while an exclusion stands.
            if (! $isStricter) {
                $exclusion = SelfExclusion::query()
                    ->where('user_id', $data->userId)
                    ->where('status', SelfExclusionStatus::Active->value)
                    ->where('ends_at', '>', now())
                    ->first();

                if ($exclusion !== null) {
                    throw SelfExclusionException::activeExclusion($data->userId, (string) $exclusion->ends_at->toIso8601String());
                }
            }

            // ── 4. A second INCREASE while one is still cooling off would fork
            //    the horizon: the player would be choosing between two pending
            //    futures. The existing horizon stands; the caller must wait or
            //    choose something stricter.
            if ($pending instanceof ResponsibleGamingLimitVersion && ! $isStricter) {
                throw ResponsibleGamingLimitException::cooldownViolation(
                    $data->type->value,
                    $pending->effective_from?->toIso8601String() ?? 'unset',
                );
            }

            // ── 5. A stricter choice is the player's to make at any time, and
            //    it cancels the increase they no longer want. That is the whole
            //    point of a cooling-off: protection is immediate, loosening is
            //    not.
            if ($pending instanceof ResponsibleGamingLimitVersion && $isStricter) {
                $this->markCancelled($pending, 'cancelled by a stricter choice made during the cooling-off');
            }

            // Every instant on file was recorded under the timezone this
            // process ran in. If that has changed, a new pronunciation cannot be
            // compared with the ones on record, and the safe answer is to refuse
            // rather than to reinterpret the player's earlier choices.
            $this->assertTimezoneUnchanged($data->userId);

            $activationAt = $isStricter
                ? now()
                : now()->addHours(self::COOLING_OFF_HOURS);

            // A requested start can only ever ADD time to the cool-off. Letting
            // it replace the horizon would let a caller pronounce an increase
            // that takes effect "tomorrow" in a configuration where the horizon
            // is longer than that.
            if (! $isStricter && $data->effectiveFrom !== null
                && $data->effectiveFrom->getTimestamp() > $activationAt->getTimestamp()) {
                $activationAt = now()->setTimestamp($data->effectiveFrom->getTimestamp());
            }

            // A window that closes before the increase could ever become law is
            // not a shorter window, it is a request that cannot be honoured.
            if (! $isStricter && $data->effectiveTo !== null
                && $data->effectiveTo->getTimestamp() <= $activationAt->getTimestamp()) {
                throw ResponsibleGamingLimitException::malformed(
                    'effective_to must stand after the cooling-off horizon',
                );
            }

            $version = new ResponsibleGamingLimitVersion;
            $version->fill([
                'user_id' => $data->userId,
                'limit_type' => $data->type,
                'amount' => $data->amount,
                'currency' => $data->currency,
                'limit_key' => $key,
                'limit_status' => $isStricter ? ResponsibleGamingLimitStatus::Active : ResponsibleGamingLimitStatus::Pending,
                'effective_from' => $activationAt,
                'effective_to' => $data->effectiveTo,
                'activated_at' => $isStricter ? now() : null,
            ]);
            $version->save();

            $this->enroll($version, $data, $active);

            if ($isStricter && $active instanceof ResponsibleGamingLimitVersion) {
                $this->markReplaced($active, $version);
            }

            $this->scribble($version, sprintf(
                '%s %s limit pronounced (%s)',
                $isStricter ? 'stricter' : 'pending increase',
                $data->type->value,
                $data->amount,
            ));

            return ['version' => $version, 'replayed' => false];
        });
    }

    /**
     * Walk the generations of a payload key whose first row has been sealed.
     *
     * Deterministic, so a retry of the same new decision lands on the same row:
     * the first generation that is either free (create here) or still in force
     * (replay it) wins. A sealed generation is skipped - re-choosing a value the
     * player once held must not be answered with the historical row.
     *
     * @return array{0: string, 1: ResponsibleGamingLimitVersion|null}
     */
    private function nextGeneration(string $baseKey): array
    {
        for ($generation = 1; $generation <= 1000; $generation++) {
            $candidate = hash('sha256', $baseKey.'|generation|'.$generation);

            /** @var ResponsibleGamingLimitVersion|null $row */
            $row = ResponsibleGamingLimitVersion::query()->where('limit_key', $candidate)->first();

            if (! $row instanceof ResponsibleGamingLimitVersion) {
                return [$candidate, null];
            }

            if (! $row->limit_status->isTerminal()) {
                return [$candidate, $row];
            }
        }

        throw ResponsibleGamingLimitException::malformed('No free limit-version identity available');
    }

    /**
     * A version replayed under its own identity must describe the same
     * decision as the one on record.
     */
    private function assertSameDefinition(ResponsibleGamingLimitVersion $existing, ResponsibleGamingLimitData $data): void
    {
        // The ownership record holds the payload hash the version was born
        // with. Comparing hashes rather than re-deriving fields keeps the two
        // notions of "the same request" in one place: the DTO decides what the
        // payload is, the record remembers it, and a replay must reproduce it
        // exactly — amount, type, currency AND the requested window.
        $ownership = $this->ownershipFor($existing);

        if ($ownership instanceof ResponsibleGamingLimitOwnership) {
            if (! hash_equals((string) $ownership->payload_hash, $data->payloadHash())) {
                throw ResponsibleGamingLimitException::malformed(
                    'A request key already pronounced a different limit definition',
                );
            }

            return;
        }

        // No ownership record (history from before enrollment): fall back to the
        // stored row, which still pins type, amount and currency.
        if ($existing->limit_type->value !== $data->type->value
            || bccomp((string) $existing->amount, $data->amount, 2) !== 0
            || strtoupper((string) $existing->currency) !== $data->currency) {
            throw ResponsibleGamingLimitException::malformed(
                'A request key already pronounced a different limit definition',
            );
        }
    }

    private function markCancelled(ResponsibleGamingLimitVersion $pending, string $why): void
    {
        $pending->limit_status = ResponsibleGamingLimitStatus::Cancelled;
        $pending->cancelled_at = now();
        $pending->save();
        $this->scribble($pending, $why);
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

        $res = $this->pronounce($dto);
        return $res['version'];
    }

    public function updateDailyDepositLimit(int $userId, string $amount, string $currency = 'THB'): ResponsibleGamingLimitVersion
    {
        return $this->setDailyDepositLimit($userId, $amount, $currency);
    }

    public function getActiveDailyDepositLimit(int $userId, string $currency = 'THB'): ?string
    {
        $limit = $this->bindingLimitFor($userId, ResponsibleGamingLimitType::DailyDeposit, strtoupper($currency));
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

        $res = $this->pronounce($dto);
        return $res['version'];
    }

    public function getActiveSingleBetLimit(int $userId, string $currency = 'THB'): ?string
    {
        $limit = $this->bindingLimitFor($userId, ResponsibleGamingLimitType::SingleBet, strtoupper($currency));
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

        $res = $this->pronounce($dto);
        return $res['version'];
    }

    public function getActiveDailyWageringLimit(int $userId, string $currency = 'THB'): ?string
    {
        $limit = $this->bindingLimitFor($userId, ResponsibleGamingLimitType::DailyWagering, strtoupper($currency));
        return $limit !== null ? (string) $limit->amount : null;
    }

    /**
     * The sweep: every Pending increase whose cooling-off has run its
     * hour, and which still out-runs the then-current ceiling, seats
     * Active (its ancestor Replaced in the same breath). A pending
     * increase that no longer beats the ceiling becomes Cancelled —
     * the purpose of the horizon is exactly this question.
     */
    public function activateDuePending(int $limit = 100): int
    {
        $seated = 0;

        ResponsibleGamingLimitVersion::query()
            ->where('limit_status', ResponsibleGamingLimitStatus::Pending->value)
            ->where('effective_from', '<=', now())
            ->orderBy('effective_from')
            ->limit($limit)
            ->get()
            ->each(function (ResponsibleGamingLimitVersion $pending) use (&$seated): void {
                DB::transaction(function () use ($pending, &$seated): void {
                    /** @var ResponsibleGamingLimitVersion|null $locked */
                    $locked = ResponsibleGamingLimitVersion::query()->lockForUpdate()->find($pending->id);

                    if (! $locked instanceof ResponsibleGamingLimitVersion
                        || $locked->limit_status !== ResponsibleGamingLimitStatus::Pending) {
                        return;
                    }

                    /** @var ResponsibleGamingLimitVersion|null $ceiling */
                    $ceiling = ResponsibleGamingLimitVersion::query()
                        ->where('user_id', $locked->user_id)
                        ->where('limit_type', $locked->limit_type->value)
                        ->where('limit_status', ResponsibleGamingLimitStatus::Active->value)
                        ->lockForUpdate()
                        ->first();

                    if ($ceiling instanceof ResponsibleGamingLimitVersion
                        && bccomp((string) $locked->amount, (string) $ceiling->amount, 2) <= 0) {
                        $locked->limit_status = ResponsibleGamingLimitStatus::Cancelled;
                        $locked->cancelled_at = now();
                        $locked->save();
                        $this->scribble($locked, 'pending increase cancelled — no longer looser than the active ceiling');

                        return;
                    }

                    $refusal = $this->refusalToSeat($locked, $ceiling);

                    if ($refusal !== null) {
                        $locked->limit_status = ResponsibleGamingLimitStatus::Cancelled;
                        $locked->cancelled_at = now();
                        $locked->save();
                        $this->scribble($locked, 'pending increase cancelled — '.$refusal);

                        return;
                    }

                    if ($ceiling instanceof ResponsibleGamingLimitVersion) {
                        $this->markReplaced($ceiling, $locked);
                    }

                    $locked->limit_status = ResponsibleGamingLimitStatus::Active;
                    $locked->activated_at = now();
                    $locked->save();
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
            ->get()
            ->each(function (ResponsibleGamingLimitVersion $v) use (&$expired): void {
                DB::transaction(function () use ($v, &$expired): void {
                    /** @var ResponsibleGamingLimitVersion|null $locked */
                    $locked = ResponsibleGamingLimitVersion::query()->lockForUpdate()->find($v->id);

                    if (! $locked instanceof ResponsibleGamingLimitVersion
                        || $locked->limit_status !== ResponsibleGamingLimitStatus::Active) {
                        return;
                    }

                    // Ownerless history is not evidence. A version whose
                    // ownership record is gone cannot be shown to have been
                    // pronounced by anyone, so it is left exactly as it is
                    // rather than being moved by a sweep.
                    $ownership = $this->ownershipFor($locked);

                    if ($ownership === null
                        || $ownership->expiry_epoch === null
                        || (int) $ownership->expiry_epoch > now()->getTimestamp()) {
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
     * Active versions on file (should never be two, but the safer
     * reads alone) — exact bcmath comparison, never float math.
     */
    public function bindingLimitFor(int $userId, ResponsibleGamingLimitType $type, ?string $currency = null): ?ResponsibleGamingLimitVersion
    {
        $query = ResponsibleGamingLimitVersion::query()
            ->where('user_id', $userId)
            ->where('limit_type', $type->value)
            ->where('limit_status', ResponsibleGamingLimitStatus::Active->value);

        if ($currency !== null && $currency !== '') {
            $query->where('currency', $currency);
        }

        $binding = $query->orderBy('amount')->first();

        if ($binding instanceof ResponsibleGamingLimitVersion) {
            // A ceiling that grants paid permission is only readable while its
            // recorded terms and its recorded timezone still describe it. A row
            // whose amount was edited in place, or whose instants were recorded
            // under a different backend timezone, is not a ceiling - it is an
            // unexplained row, and the gate refuses on it.
            $this->assertOwnedTermsIntact($binding);
        }

        return $binding;
    }

    /**
     * @throws ResponsibleGamingLimitException when a ceiling on file was
     *                                         pronounced under a different backend timezone
     */
    private function assertTimezoneUnchanged(int $userId): void
    {
        $recorded = ResponsibleGamingLimitOwnership::query()
            ->whereIn('version_id', ResponsibleGamingLimitVersion::query()
                ->where('user_id', $userId)
                ->select('id'))
            ->orderByDesc('id')
            ->value('timezone');

        if ($recorded === null) {
            return;
        }

        if ((string) $recorded !== (string) config('app.timezone', 'UTC')) {
            throw ResponsibleGamingLimitException::malformed(
                'The backend timezone changed since the last ceiling was pronounced',
            );
        }
    }

    /**
     * Record WHO pronounced this version and against WHAT.
     *
     * The ownership row is what makes a later sweep auditable: it pins the
     * payload, the terms, the basis the decision was measured against, the
     * backend timezone those instants were recorded under, and the epochs that
     * decide when it may take effect and when it lapses.
     */
    private function enroll(
        ResponsibleGamingLimitVersion $version,
        ResponsibleGamingLimitData $data,
        ?ResponsibleGamingLimitVersion $active,
    ): void {
        $timezone = (string) config('app.timezone', 'UTC');

        ResponsibleGamingLimitOwnership::query()->create([
            'version_id' => $version->id,
            'request_identity' => substr((string) $version->limit_key, 0, 64),
            'payload_hash' => $data->payloadHash(),
            'terms_hash' => $this->termsHash($version),
            'basis_hash' => $this->basisHash($version->user_id, $version->limit_type->value,
                $version->currency, $active),
            'timezone' => $timezone,
            'activation_epoch' => (int) $version->effective_from?->getTimestamp(),
            'expiry_epoch' => $version->effective_to === null
                ? null
                : (int) $version->effective_to->getTimestamp(),
        ]);
    }

    private function ownershipFor(ResponsibleGamingLimitVersion $version): ?ResponsibleGamingLimitOwnership
    {
        /** @var ResponsibleGamingLimitOwnership|null $ownership */
        $ownership = ResponsibleGamingLimitOwnership::query()
            ->where('version_id', $version->id)
            ->first();

        return $ownership;
    }

    /**
     * The terms the player agreed to, hashed from the stored row.
     */
    private function termsHash(ResponsibleGamingLimitVersion $version): string
    {
        return hash('sha256', implode('|', [
            'rgl-terms',
            (string) $version->user_id,
            $version->limit_type->value,
            (string) $version->amount,
            (string) $version->currency,
            (string) $version->effective_from?->getTimestamp(),
            $version->effective_to === null ? 'open' : (string) $version->effective_to->getTimestamp(),
        ]));
    }

    /**
     * The state the decision was measured against: which ceiling stood, how
     * much it allowed, in which currency, and whether the account was in good
     * standing at the time.
     */
    private function basisHash(int $userId, string $type, string $currency, ?ResponsibleGamingLimitVersion $ceiling): string
    {
        return hash('sha256', implode('|', [
            'rgl-basis',
            (string) $userId,
            $type,
            $currency,
            $ceiling instanceof ResponsibleGamingLimitVersion ? (string) $ceiling->limit_key : 'none',
            $ceiling instanceof ResponsibleGamingLimitVersion ? (string) $ceiling->amount : 'none',
            (string) config('app.timezone', 'UTC'),
        ]));
    }

    /**
     * @throws ResponsibleGamingLimitException when a stored ceiling no longer
     *                                         matches the record of how it was pronounced
     */
    private function assertOwnedTermsIntact(ResponsibleGamingLimitVersion $version): void
    {
        $ownership = $this->ownershipFor($version);

        if ($ownership === null) {
            // Ownerless history is readable (it predates enrollment) but grants
            // nothing new; callers that need a decision take the refusal path
            // elsewhere.
            return;
        }

        if ((string) $ownership->timezone !== (string) config('app.timezone', 'UTC')) {
            throw ResponsibleGamingLimitException::malformed(
                'The backend timezone changed since this ceiling was pronounced',
            );
        }

        if (! hash_equals((string) $ownership->terms_hash, $this->termsHash($version))) {
            throw ResponsibleGamingLimitException::malformed(
                'The stored ceiling no longer matches the terms it was pronounced with',
            );
        }
    }

    /**
     * Why a due pending increase must NOT be seated, or null when it may be.
     *
     * Every reason here is a fact about the world changing underneath a decision
     * the player made a day ago. None of them may be resolved in favour of the
     * looser ceiling.
     */
    private function refusalToSeat(
        ResponsibleGamingLimitVersion $pending,
        ?ResponsibleGamingLimitVersion $ceiling,
    ): ?string {
        $ownership = $this->ownershipFor($pending);

        if ($ownership === null) {
            return 'its ownership record is missing';
        }

        if ((string) $ownership->timezone !== (string) config('app.timezone', 'UTC')) {
            return 'the backend timezone changed since it was pronounced';
        }

        if (! hash_equals((string) $ownership->terms_hash, $this->termsHash($pending))) {
            return 'its stored terms no longer match what was pronounced';
        }

        if (! hash_equals((string) $ownership->basis_hash,
            $this->basisHash($pending->user_id, $pending->limit_type->value, $pending->currency, $ceiling))) {
            return 'the ceiling it was measured against has changed';
        }

        if ($pending->effective_to !== null && $pending->effective_to->getTimestamp() <= now()->getTimestamp()) {
            return 'its own window closed before it could take effect';
        }

        /** @var ResponsibleGamingLimitVersion|null $fork */
        $fork = ResponsibleGamingLimitVersion::query()
            ->where('user_id', $pending->user_id)
            ->where('limit_type', $pending->limit_type->value)
            ->where('currency', $pending->currency)
            ->where('limit_status', ResponsibleGamingLimitStatus::Pending->value)
            ->whereKeyNot($pending->id)
            ->first();

        if ($fork instanceof ResponsibleGamingLimitVersion) {
            return 'a second pending increase exists for the same ceiling';
        }

        $user = User::query()->find($pending->user_id);

        if ($user === null || $user->status !== UserStatus::Active) {
            return 'the account is not in good standing';
        }

        $legacy = ResponsibleGamingLimit::query()->where('user_id', $pending->user_id)->first();

        if ($legacy instanceof ResponsibleGamingLimit
            && (($legacy->self_excluded_until !== null && $legacy->self_excluded_until->isFuture())
                || ($legacy->cool_off_until !== null && $legacy->cool_off_until->isFuture()))) {
            return 'a self-exclusion or cool-off still stands';
        }

        $exclusion = SelfExclusion::query()
            ->where('user_id', $pending->user_id)
            ->where('status', SelfExclusionStatus::Active->value)
            ->where('ends_at', '>', now())
            ->first();

        if ($exclusion !== null) {
            return 'a self-exclusion still stands';
        }

        return null;
    }

    /**
     * Choose a ceiling. Same transaction and same refusals as pronounce();
     * named separately because callers think in choices, not in announcements.
     *
     * @return array{version: ResponsibleGamingLimitVersion, replayed: bool}
     */
    public function choose(ResponsibleGamingLimitData $data): array
    {
        return $this->pronounce($data);
    }

    /**
     * THE INLINE SCRIBE: one row per pronouncement passage, anchored
     * against the limit key + note so replays add no rows.
     */
    private function scribble(ResponsibleGamingLimitVersion $version, string $note): void
    {
        $anchor = 'rgl-audit:'.(string) $version->limit_key.':'.$version->limit_status->value.':'.$note;

        $exists = AuditLog::query()
            ->where('auditable_type', ResponsibleGamingLimitVersion::class)
            ->where('created_at', '>=', now()->subHours(48))
            ->whereJsonContains('metadata->audit_anchor', $anchor)
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
                'audit_anchor' => $anchor,
                'note' => $note,
            ],
        ]);
    }

    private function markReplaced(ResponsibleGamingLimitVersion $old, ResponsibleGamingLimitVersion $new): void
    {
        $old->limit_status = ResponsibleGamingLimitStatus::Replaced;
        $old->replaced_by_key = $new->limit_key;
        $old->save();
        $this->scribble($old, sprintf('superseded by [%s]', substr($new->limit_key, 0, 12)));
    }
}
