<?php

declare(strict_types=1);

namespace App\DTOs\ResponsibleGaming;

use App\Enums\ResponsibleGamingLimitType;
use App\Exceptions\ResponsibleGamingLimitException;

/**
 * Exact-decimal limit definition: user, type, amount (bc-exact),
 * currency, the pronunciation's rolling period, effective window and
 * the deterministic LIMIT KEY the identity of the row flows from.
 */
final class ResponsibleGamingLimitData
{
    public readonly ResponsibleGamingLimitType $type;

    public readonly ResponsibleGamingLimitType $limitType;

    public readonly string $amount;

    public readonly string $currency;

    public readonly ?string $requestKey;

    public function __construct(
        public readonly int $userId,
        ResponsibleGamingLimitType|string $type,
        string $amount,
        string $currency = 'THB',
        public readonly ?\DateTimeInterface $effectiveFrom = null,
        public readonly ?\DateTimeInterface $effectiveTo = null,
        ?string $requestKey = null,
    ) {
        if ($userId <= 0) {
            throw ResponsibleGamingLimitException::malformed('user_id must be a positive integer');
        }

        $resolvedType = is_string($type)
            ? ResponsibleGamingLimitType::tryFrom(strtolower(trim($type)))
            : $type;

        if (! $resolvedType instanceof ResponsibleGamingLimitType || ! $resolvedType->isVersionedCeiling()) {
            throw ResponsibleGamingLimitException::malformed('A versionable limit type is required');
        }

        $trimmedAmount = trim($amount);
        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $trimmedAmount) || bccomp($trimmedAmount, '0', 2) <= 0) {
            throw ResponsibleGamingLimitException::invalidAmount($amount);
        }

        $normalizedCurrency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $normalizedCurrency)) {
            throw ResponsibleGamingLimitException::malformed('Currency must be a 3-letter ISO code');
        }

        if ($effectiveFrom !== null && $effectiveTo !== null && $effectiveTo->getTimestamp() <= $effectiveFrom->getTimestamp()) {
            throw ResponsibleGamingLimitException::malformed('effective_to must stand after effective_from');
        }

        $normalizedRequestKey = null;
        if ($requestKey !== null) {
            $trimmedKey = trim($requestKey);
            if ($trimmedKey === '' || strlen($trimmedKey) > 128 || preg_match('/[\x00-\x1F\x7F]/', $trimmedKey) === 1) {
                throw ResponsibleGamingLimitException::malformed('request_key must be 1..128 printable characters');
            }
            $normalizedRequestKey = $trimmedKey;
        }

        $this->type = $resolvedType;
        $this->limitType = $resolvedType;
        $this->amount = bcadd($trimmedAmount, '0', 2);
        $this->currency = $normalizedCurrency;
        $this->requestKey = $normalizedRequestKey;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromInput(array $data): self
    {
        $userIdRaw = $data['user_id'] ?? null;
        if (! is_int($userIdRaw) && (! is_string($userIdRaw) || ! preg_match('/^\d+$/', trim($userIdRaw)))) {
            throw ResponsibleGamingLimitException::malformed('user_id must be a positive integer');
        }
        $userId = (int) $userIdRaw;

        $currencyRaw = $data['currency'] ?? 'THB';
        if (! is_string($currencyRaw)) {
            throw ResponsibleGamingLimitException::malformed('Currency must be a 3-letter ISO code');
        }
        $currency = strtoupper(trim($currencyRaw));

        $amountRaw = $data['amount'] ?? null;
        $amount = is_string($amountRaw) ? trim($amountRaw) : (is_int($amountRaw) ? (string) $amountRaw : null);

        if ($amount === null || ! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount)) {
            throw ResponsibleGamingLimitException::invalidAmount(
                is_scalar($amountRaw) ? (string) $amountRaw : 'n/a',
            );
        }

        $type = $data['limit_type'] ?? null;
        if (! $type instanceof ResponsibleGamingLimitType) {
            $type = is_string($type) ? ResponsibleGamingLimitType::tryFrom(strtolower(trim($type))) : null;
        }
        if (! $type instanceof ResponsibleGamingLimitType || ! $type->isVersionedCeiling()) {
            throw ResponsibleGamingLimitException::malformed('A versionable limit type is required');
        }

        $from = $data['effective_from'] ?? null;
        if ($from !== null && ! $from instanceof \DateTimeInterface) {
            throw ResponsibleGamingLimitException::malformed('effective_from must be a valid DateTimeInterface');
        }

        $to = $data['effective_to'] ?? null;
        if ($to !== null && ! $to instanceof \DateTimeInterface) {
            throw ResponsibleGamingLimitException::malformed('effective_to must be a valid DateTimeInterface');
        }

        $requestKeyRaw = $data['request_key'] ?? null;
        if ($requestKeyRaw !== null && ! is_string($requestKeyRaw)) {
            throw ResponsibleGamingLimitException::malformed('request_key must be a string');
        }

        return new self(
            userId: $userId,
            type: $type,
            amount: $amount,
            currency: $currency,
            effectiveFrom: $from,
            effectiveTo: $to,
            requestKey: $requestKeyRaw,
        );
    }

    /**
     * Same (user, type, amount, window) or explicit request_key = one version, replayed.
     */
    public function limitKey(): string
    {
        if ($this->requestKey !== null) {
            return hash('sha256', implode('|', [
                'glo-rgl-req',
                (string) $this->userId,
                $this->requestKey,
            ]));
        }

        return $this->payloadHash();
    }

    public function payloadHash(): string
    {
        return hash('sha256', implode('|', [
            'glo-rgl',
            (string) $this->userId,
            $this->type->value,
            $this->amount,
            $this->currency,
            $this->effectiveFrom !== null ? (string) $this->effectiveFrom->getTimestamp() : 'now',
            $this->effectiveTo !== null ? (string) $this->effectiveTo->getTimestamp() : 'open',
        ]));
    }
}
