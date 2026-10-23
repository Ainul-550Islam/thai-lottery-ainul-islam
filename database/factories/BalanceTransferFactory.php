<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\TransferDirection;
use App\Enums\TransferStatus;
use App\Models\BalanceTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<BalanceTransfer>
 */
class BalanceTransferFactory extends Factory
{
    protected $model = BalanceTransfer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $direction = TransferDirection::CashToWin;

        return [
            'uuid' => (string) Str::uuid(),
            'reference_number' => BalanceTransfer::generateReference(),
            'user_id' => User::factory(),
            'direction' => $direction,
            'currency' => Currency::THB,
            'amount' => '100.00',
            'fee_amount' => '3.00',
            'total_debited' => '103.00',
            'fee_category' => $direction->feeCategory(),
            'fee_rate' => '0.0300',
            'source_balance_before' => '500.00',
            'source_balance_after' => '397.00',
            'destination_balance_before' => '0.00',
            'destination_balance_after' => '100.00',
            'status' => TransferStatus::Completed,
            'completed_at' => Carbon::now(),
        ];
    }

    public function direction(TransferDirection $direction): self
    {
        return $this->state(fn (): array => [
            'direction' => $direction,
            'fee_category' => $direction->feeCategory(),
        ]);
    }

    public function completedSecondsAgo(int $seconds): self
    {
        $when = Carbon::now()->subSeconds(max(0, $seconds));

        return $this->state(fn (): array => [
            'status' => TransferStatus::Completed,
            'completed_at' => $when,
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }
}
