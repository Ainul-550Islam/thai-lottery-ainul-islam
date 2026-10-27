<?php

declare(strict_types=1);

namespace Tests\Unit\Transfer;

use App\Enums\TransferDirection;
use App\Enums\WalletType;
use App\Exceptions\BalanceTransferException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TransferDirection contract.
 *
 * The point of this suite is the COUPLING, not the enum in isolation. Three
 * things must stay true together or the feature silently breaks:
 *
 *   1. each direction's fee category is a row that actually exists in
 *      config/fees.php, so the lane can always be priced;
 *   2. the two directions are exact inverses of each other on wallet types, so
 *      "the other direction" always means what the player thinks it means;
 *   3. every wallet type reachable as a pocket is a transfer pocket, so the
 *      resolver can never be handed a bonus or commission wallet.
 */
final class TransferDirectionTest extends TestCase
{
    #[Test]
    public function there_are_exactly_two_directions(): void
    {
        $this->assertSame(['cash_to_win', 'win_to_cash'], TransferDirection::values());
    }

    #[Test]
    public function cash_to_win_moves_from_primary_to_win(): void
    {
        $this->assertSame(WalletType::Primary, TransferDirection::CashToWin->sourceWalletType());
        $this->assertSame(WalletType::Win, TransferDirection::CashToWin->destinationWalletType());
    }

    #[Test]
    public function win_to_cash_moves_from_win_to_primary(): void
    {
        $this->assertSame(WalletType::Win, TransferDirection::WinToCash->sourceWalletType());
        $this->assertSame(WalletType::Primary, TransferDirection::WinToCash->destinationWalletType());
    }

    #[Test]
    public function each_direction_is_the_exact_inverse_of_the_other(): void
    {
        foreach (TransferDirection::cases() as $direction) {
            $opposite = $direction->opposite();

            $this->assertNotSame($direction, $opposite);
            $this->assertSame($direction->sourceWalletType(), $opposite->destinationWalletType());
            $this->assertSame($direction->destinationWalletType(), $opposite->sourceWalletType());
            $this->assertSame($direction, $opposite->opposite());
        }
    }

    #[Test]
    public function pocket_names_agree_with_the_wallet_types(): void
    {
        foreach (TransferDirection::cases() as $direction) {
            $names = $direction->pocketNames();

            $this->assertSame(
                $direction->sourceWalletType()->transferPocketName(),
                $names['from'],
            );
            $this->assertSame(
                $direction->destinationWalletType()->transferPocketName(),
                $names['to'],
            );
            $this->assertNotSame($names['from'], $names['to']);
        }
    }

    /**
     * THE LOAD-BEARING ASSERTION IN THIS SUITE.
     *
     * TransferFeeCalculator prices a lane by calling FeesPageService on
     * `$direction->feeCategory()`. If that key ever stops matching a row in
     * config/fees.php, the lane cannot be priced and every transfer refuses with
     * a 503 — a total outage caused by a rename. This test turns that outage into
     * a red build.
     */
    #[Test]
    public function every_direction_fee_category_exists_in_the_public_fee_schedule(): void
    {
        foreach (TransferDirection::cases() as $direction) {
            $row = config('fees.categories.'.$direction->feeCategory());

            $this->assertIsArray(
                $row,
                sprintf(
                    'config/fees.php has no "%s" row, so %s cannot be priced.',
                    $direction->feeCategory(),
                    $direction->value,
                ),
            );

            $this->assertTrue(
                (bool) ($row['enabled'] ?? false),
                sprintf('The "%s" fee row is disabled.', $direction->feeCategory()),
            );

            $this->assertSame(
                'percentage',
                (string) ($row['calculation'] ?? ''),
                sprintf('The "%s" fee row is not a percentage fee.', $direction->feeCategory()),
            );
        }
    }

    #[Test]
    public function every_direction_is_reachable_through_the_public_category_order(): void
    {
        $order = app(\App\Services\PublicPages\FeesPageService::class)->categoryOrder();

        foreach (TransferDirection::cases() as $direction) {
            $this->assertContains(
                $direction->feeCategory(),
                $order,
                'A lane whose fee is not published on /fees charges a price the player never saw.',
            );
        }
    }

    #[Test]
    public function between_wallet_types_resolves_both_lanes(): void
    {
        $this->assertSame(
            TransferDirection::CashToWin,
            TransferDirection::betweenWalletTypes(WalletType::Primary, WalletType::Win),
        );

        $this->assertSame(
            TransferDirection::WinToCash,
            TransferDirection::betweenWalletTypes(WalletType::Win, WalletType::Primary),
        );
    }

    #[Test]
    public function between_wallet_types_refuses_a_lane_that_does_not_exist(): void
    {
        // Same type both ways: a transfer from a wallet to itself.
        $this->expectException(BalanceTransferException::class);

        TransferDirection::betweenWalletTypes(WalletType::Primary, WalletType::Primary);
    }

    #[Test]
    public function between_wallet_types_refuses_a_non_pocket_wallet(): void
    {
        $this->expectException(BalanceTransferException::class);

        TransferDirection::betweenWalletTypes(WalletType::Bonus, WalletType::Primary);
    }

    #[Test]
    public function every_pocket_wallet_type_is_a_transfer_pocket(): void
    {
        foreach (TransferDirection::cases() as $direction) {
            $this->assertTrue($direction->sourceWalletType()->isTransferPocket());
            $this->assertTrue($direction->destinationWalletType()->isTransferPocket());
        }
    }

    #[Test]
    public function bonus_and_commission_wallets_are_not_transfer_pockets(): void
    {
        // A bonus balance must not be convertible into withdrawable cash through
        // this lane; that would defeat whatever condition the bonus was issued
        // under.
        $this->assertFalse(WalletType::Bonus->isTransferPocket());
        $this->assertFalse(WalletType::Commission->isTransferPocket());
        $this->assertNull(WalletType::Bonus->transferPocketName());
        $this->assertNull(WalletType::Commission->transferPocketName());
    }

    #[Test]
    public function try_from_input_normalises_case_and_whitespace(): void
    {
        $this->assertSame(TransferDirection::CashToWin, TransferDirection::tryFromInput('cash_to_win'));
        $this->assertSame(TransferDirection::CashToWin, TransferDirection::tryFromInput('  CASH_TO_WIN '));
        $this->assertSame(TransferDirection::WinToCash, TransferDirection::tryFromInput('Win_To_Cash'));
    }

    #[Test]
    public function try_from_input_returns_null_rather_than_throwing(): void
    {
        $this->assertNull(TransferDirection::tryFromInput(''));
        $this->assertNull(TransferDirection::tryFromInput('   '));
        $this->assertNull(TransferDirection::tryFromInput('cash-to-win'));
        $this->assertNull(TransferDirection::tryFromInput('primary_to_bonus'));
        $this->assertNull(TransferDirection::tryFromInput(null));
        $this->assertNull(TransferDirection::tryFromInput(42));
        $this->assertNull(TransferDirection::tryFromInput(['cash_to_win']));
    }

    #[Test]
    public function labels_are_non_empty_and_distinct(): void
    {
        $labels = [];

        foreach (TransferDirection::cases() as $direction) {
            $this->assertNotSame('', trim($direction->label()));
            $labels[] = $direction->label();
        }

        $this->assertSame(2, count(array_unique($labels)));
    }
}
