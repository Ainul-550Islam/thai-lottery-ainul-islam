<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionRejectionReason;
use Throwable;

/**
 * The conversion feature, or one direction of it, is switched off.
 *
 * WHY THIS IS A 503 AND NOT A 422
 * Nothing is wrong with the member's request. The platform has decided, by
 * configuration, not to offer this service right now — exactly the situation
 * the existing profile compatibility controller reports as NOT_CONFIGURED with
 * a 503. Returning 422 here would tell the member to fix their input and would
 * hide the outage from monitoring, which is the opposite of what an operator
 * needs when they have just flipped the switch.
 *
 * The message is deliberately operational: it names which switch is off, so a
 * support agent reading a screenshot knows whether the whole lane is down or
 * only one direction was frozen.
 */
final class ConversionDisabledException extends ConversionException
{
    public const ERROR_CODE_FEATURE = 'feature_disabled';

    public const ERROR_CODE_DIRECTION = 'direction_disabled';

    public function __construct(
        private readonly bool $featureLevel,
        private readonly ?WalletConversionDirection $direction = null,
        ?Throwable $previous = null,
    ) {
        $context = [
            'scope' => $featureLevel ? 'feature' : 'direction',
            'direction' => $direction?->value,
        ];

        parent::__construct(
            $featureLevel
                ? 'Wallet conversions are currently disabled on this platform.'
                : sprintf(
                    'The %s conversion direction is currently disabled.',
                    $direction?->value ?? 'requested',
                ),
            $featureLevel ? self::ERROR_CODE_FEATURE : self::ERROR_CODE_DIRECTION,
            $context,
            $previous,
        );
    }

    /**
     * The whole feature is off (config `conversions.enabled`).
     */
    public static function feature(?Throwable $previous = null): self
    {
        return new self(true, null, $previous);
    }

    /**
     * One direction is off (config `conversions.directions.*.enabled`).
     */
    public static function forDirection(
        WalletConversionDirection $direction,
        ?Throwable $previous = null,
    ): self {
        return new self(false, $direction, $previous);
    }

    public function isFeatureLevel(): bool
    {
        return $this->featureLevel;
    }

    public function direction(): ?WalletConversionDirection
    {
        return $this->direction;
    }

    /**
     * The switch an operator must change to restore the service, named exactly
     * as it appears in config/conversions.php.
     */
    public function configKey(): string
    {
        if ($this->featureLevel) {
            return 'conversions.enabled';
        }

        return 'conversions.directions.'.($this->direction?->value ?? 'unknown').'.enabled';
    }

    public function rejectionReason(): ?WalletConversionRejectionReason
    {
        return $this->featureLevel
            ? WalletConversionRejectionReason::FeatureDisabled
            : WalletConversionRejectionReason::DirectionDisabled;
    }
}
