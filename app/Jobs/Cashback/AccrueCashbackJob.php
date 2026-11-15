<?php

declare(strict_types=1);

namespace App\Jobs\Cashback;

use App\Enums\BetStatus;
use App\Models\Bet;
use App\Models\User;
use App\Services\Cashback\CashbackAccrualService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

final class AccrueCashbackJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $period = 'daily',
        public readonly ?string $periodStartIso = null,
        public readonly ?string $periodEndIso = null,
        public readonly int $afterMemberId = 0,
        public readonly ?int $chunkSize = null,
    ) {}

    public function uniqueId(): string
    {
        return sprintf(
            'accrue-cashback:%s:%s:%s:cursor:%d',
            $this->period,
            $this->periodStartIso ?? 'auto',
            $this->periodEndIso ?? 'auto',
            $this->afterMemberId,
        );
    }

    public function handle(CashbackAccrualService $accrualService): int
    {
        [$start, $end] = $this->resolveWindow();
        $resolvedChunk = max(1, $this->chunkSize ?? (int) config('rewards.cashback.accrual_chunk_size', 200));

        /** @var list<int> $memberIds */
        $memberIds = Bet::query()
            ->whereIn('status', [BetStatus::Won->value, BetStatus::Lost->value])
            ->where('user_id', '>', $this->afterMemberId)
            ->where(function ($query) use ($start, $end): void {
                $query
                    ->whereBetween('won_at', [$start, $end])
                    ->orWhereBetween('updated_at', [$start, $end]);
            })
            ->select('user_id')
            ->distinct()
            ->orderBy('user_id')
            ->limit($resolvedChunk)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($memberIds === []) {
            return 0;
        }

        $membersById = User::query()
            ->whereIn('id', $memberIds)
            ->get()
            ->keyBy('id');

        $created = 0;
        $lastMemberId = $this->afterMemberId;

        foreach ($memberIds as $memberId) {
            $lastMemberId = $memberId;
            $member = $membersById->get($memberId);
            if (! $member instanceof User) {
                continue;
            }

            $accrual = $accrualService->accrueForMember($member, $start, $end, $this->period);
            if ($accrual !== null) {
                $created++;
            }
        }

        if (count($memberIds) === $resolvedChunk) {
            self::dispatch(
                period: $this->period,
                periodStartIso: $start->toIso8601String(),
                periodEndIso: $end->toIso8601String(),
                afterMemberId: $lastMemberId,
                chunkSize: $resolvedChunk,
            );
        }

        return $created;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveWindow(): array
    {
        if ($this->periodStartIso !== null && $this->periodEndIso !== null) {
            return [Carbon::parse($this->periodStartIso), Carbon::parse($this->periodEndIso)];
        }

        if ($this->period === 'weekly') {
            $lastWeek = Carbon::now()->subWeek();

            return [$lastWeek->copy()->startOfWeek(), $lastWeek->copy()->endOfWeek()];
        }

        $yesterday = Carbon::now()->subDay();

        return [$yesterday->copy()->startOfDay(), $yesterday->copy()->endOfDay()];
    }
}
