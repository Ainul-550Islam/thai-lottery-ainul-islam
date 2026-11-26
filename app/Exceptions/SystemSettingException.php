<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * Fail-closed refusals from the settings store.
 *
 * Every refusal names the key it was about, because the operator typed one and deserves to know
 * which. None of them is a validation nicety: an unknown key would be an arbitrary config write,
 * an out-of-bounds value would be an unbounded money rule, and an incoherent pair would let a
 * minimum exceed its maximum.
 */
final class SystemSettingException extends DomainException
{
    public const CODE_UNKNOWN_KEY = 'setting_unknown_key';

    public const CODE_SECRET_KEY = 'setting_secret_key';

    public const CODE_MALFORMED = 'setting_malformed';

    public const CODE_OUT_OF_BOUNDS = 'setting_out_of_bounds';

    public const CODE_PAIR_INCOHERENT = 'setting_pair_incoherent';

    private string $refusalCode = self::CODE_MALFORMED;

    public static function unknownKey(string $key): self
    {
        $exception = new self(sprintf('Setting [%s] is not in the override catalogue, so it cannot be written.', $key));
        $exception->refusalCode = self::CODE_UNKNOWN_KEY;

        return $exception;
    }

    public static function secretKey(string $key): self
    {
        $exception = new self(sprintf('Setting [%s] is a secret and may only be set in the environment.', $key));
        $exception->refusalCode = self::CODE_SECRET_KEY;

        return $exception;
    }

    public static function malformed(string $key, string $why): self
    {
        $exception = new self(sprintf('Setting [%s] was refused: %s.', $key, $why));
        $exception->refusalCode = self::CODE_MALFORMED;

        return $exception;
    }

    public static function outOfBounds(string $key, string $value, ?string $min, ?string $max): self
    {
        $exception = new self(sprintf(
            'Setting [%s] value [%s] is outside the permitted range %s – %s.',
            $key,
            $value,
            $min ?? 'unbounded',
            $max ?? 'unbounded',
        ));
        $exception->refusalCode = self::CODE_OUT_OF_BOUNDS;

        return $exception;
    }

    public static function pairIncoherent(string $key, string $value, string $pairKey, string $pairValue): self
    {
        $exception = new self(sprintf(
            'Setting [%s] cannot be [%s]: its pair [%s] is [%s], and a minimum may not exceed its maximum.',
            $key,
            $value,
            $pairKey,
            $pairValue,
        ));
        $exception->refusalCode = self::CODE_PAIR_INCOHERENT;

        return $exception;
    }

    public function refusalCode(): string
    {
        return $this->refusalCode;
    }
}
