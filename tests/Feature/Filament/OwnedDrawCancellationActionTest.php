<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\BetStatus;
use App\Enums\DrawStatus;
use App\Filament\Resources\DrawResource\Pages\ListDraws;
use App\Filament\Resources\DrawResource\Pages\ViewDraw;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Betting\Concerns\BuildsOwnedBetPurchase;
use Tests\Feature\Finance\FinancialMasterTestCase;

/** Real server-side Livewire actions, not a button-string or mocked coordinator test. */
final class OwnedDrawCancellationActionTest extends FinancialMasterTestCase
{
    use BuildsOwnedBetPurchase;

    private function operator(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create();
        $actor->assignRole('admin');

        return $actor;
    }

    public static function surfaces(): array
    {
        return [['view'], ['table']];
    }

    #[DataProvider('surfaces')]
    public function test_both_existing_operator_actions_execute_the_original_owned_refund_before_cancelling(string $surface): void
    {
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        if ($surface === 'view') {
            Livewire::actingAs($actor)->test(ViewDraw::class, ['record' => $f['draw']->id])
                ->callAction('cancelDraw', data: ['reason' => 'Synthetic local action refund'])->assertHasNoActionErrors();
        } else {
            Livewire::actingAs($actor)->test(ListDraws::class, ['activeTab' => 'all'])
                ->callTableAction('cancelDraw', record: $f['draw'], data: ['reason' => 'Synthetic local action refund'])->assertHasNoTableActionErrors();
        }
        $this->assertSame(DrawStatus::Cancelled, $f['draw']->fresh()->status);
        $this->assertSame(BetStatus::Cancelled, $f['bet']->fresh()->status);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $owner = DB::table('draw_cancellation_ownerships')->sole();
        $this->assertSame('completed', $owner->state);
        $this->assertSame($actor->id, (int) $owner->actor_user_id);
        $this->assertSame('Synthetic local action refund', $owner->reason);
        $refund = DB::table('financial_transactions')->where('id', json_decode($owner->outcomes, true, 512, JSON_THROW_ON_ERROR)[0]['refund_transaction_id'])->sole();
        $this->assertSame((int) $owner->id, json_decode($refund->metadata, true, 512, JSON_THROW_ON_ERROR)['draw_cancellation_id']);
    }

    public function test_the_operator_action_does_not_fall_back_to_a_status_flip_after_refund_proof_refuses(): void
    {
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        DB::table('bet_purchase_ownerships')->where('bet_id', $f['bet']->id)->update(['original_hash' => str_repeat('0', 64)]);
        $transactions = DB::table('financial_transactions')->count();
        Livewire::actingAs($actor)->test(ViewDraw::class, ['record' => $f['draw']->id])
            ->callAction('cancelDraw', data: ['reason' => 'Synthetic local refused refund'])->assertHasNoActionErrors();
        $this->assertSame(DrawStatus::Open, $f['draw']->fresh()->status);
        $this->assertSame(BetStatus::Active, $f['bet']->fresh()->status);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame(0, DB::table('draw_cancellation_ownerships')->count());
        $this->assertSame($transactions, DB::table('financial_transactions')->count());
    }
}
