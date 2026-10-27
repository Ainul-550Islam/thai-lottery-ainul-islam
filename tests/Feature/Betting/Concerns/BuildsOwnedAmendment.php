<?php

declare(strict_types=1);

namespace Tests\Feature\Betting\Concerns;

use App\DTOs\Betting\BetAmendmentData;
use App\Enums\Currency;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Agent\Concerns\BuildsPaidCommissionCancellation;

trait BuildsOwnedAmendment
{
    use BuildsOwnedBetPurchase;
    use BuildsPaidCommissionCancellation;

    protected function amendmentFixture(Currency $currency = Currency::THB, bool $paid = false): array
    {
        $f = $paid ? $this->paidFixture($currency) : $this->commissionFixture($currency);
        // The commission fixtures build a bare factory user; the amend endpoint is gated
        // by `adult` and `legal.current`, so the owner has to be onboarded to reach it.
        $this->attestPlayer($f['user']);
        $this->provisionBetLimitForTest($f['draw'], '3d_direct', '008');

        return $f;
    }

    protected function amendmentCommand(array $f, string $key = 'owned-amendment-request-one', string $number = '008', string $stake = '12.00'): BetAmendmentData
    {
        return new BetAmendmentData($f['user']->id, (string) $f['bet']->id, $number, $stake, $key);
    }

    protected function amendmentState(): array
    {
        $out = $this->snapshot();
        foreach (['bet_amendments', 'bet_amendment_ownerships'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $out;
    }
}
