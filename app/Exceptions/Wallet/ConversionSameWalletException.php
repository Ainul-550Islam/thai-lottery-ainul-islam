<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use App\Enums\WalletType;
use Throwable;

/**
 * A direction was built from two sides that name the same wallet.
 *
 * WHY THIS IS ITS OWN TYPE RATHER THAN A GENERIC VALIDATION ERROR
 * This is never a member's mistake — a member chooses "Cash to Win" or "Win to
 * Cash" from a control that cannot produce a self-conversion. Reaching this
 * exception means a CALLER constructed the direction from raw wallet types,
 * and the only way that happens is a programming error in an integration, an
 * admin tool, or a future payment lane that reused the conversion service to
 * "move money into the same wallet". Naming it separately makes the defect
 * obvious in logs and lets a test assert that the guard exists at all.
 *
 * It carries no valid rejection reason on purpose: it is not a refusal a
 * member can act on, so `rejectionReason()` is null and the HTTP layer will
 * report it as a 422 with a generic code rather than a member-facing message.
 * On a healthy platform, this exception never fires in production.
 */
final class ConversionSameWalletException extends ConversionException
{
    public const ERROR_CODE = 'conversion_same_wallet';

    public function __construct(
        private readonly WalletType $walletType,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'A conversion cannot move money from the %s wallet into the same %s wallet. Supported pairs are [primary -> win] and [win -> primary].',
                $walletType->value,
                $walletType->value,
            ),
            self::ERROR_CODE,
            [
                'wallet_type' => $walletType->value,
                'supported_pairs' => ['primary->win', 'win->primary'],
            ],
            $previous,
        );
    }

    public static function forType(WalletType $walletType, ?Throwable $previous = null): self
    {
        return new self($walletType, $previous);
    }

    public function walletType(): WalletType
    {
        return $this->walletType;
    }
}
