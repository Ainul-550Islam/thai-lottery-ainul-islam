<?php

declare(strict_types=1);

namespace App\DTOs\Wallet;

use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionStatus;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The filter set for reading a member's or an operator's conversion history.
 *
 * WHY FILTERS ARE NORMALISED AND BOUNDED HERE
 * Pagination parameters arrive from a URL, which means they arrive from a
 * stranger. If the service passed `per_page` straight into the paginator, a
 * request for one million rows would be a denial-of-service with a friendly
 * name, and a negative page number would produce a query the database must
 * reject. This object is the single place where those inputs are clamped: the
 * page is at least 1, the page size is at least 1 and at most the configured
 * ceiling, and unknown status values are dropped rather than passed through.
 *
 * It also guarantees a user scope. A history filter without an owner would be a
 * query over every player's money movements, so the constructor makes the owner
 * mandatory and the repository has no method that omits it.
 */
final readonly class ConversionHistoryFilterData
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array<int, WalletConversionStatus>  $statuses
     */
    public function __construct(
        public int $userId,
        public ?WalletConversionDirection $direction = null,
        public array $statuses = [],
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public int $page = 1,
        public ?string $search = null,
    ) {}

    /**
     * Build a clamped filter from raw query input.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, int $userId): self
    {
        $direction = $data['direction'] ?? null;

        if (! $direction instanceof WalletConversionDirection) {
            if ($direction !== null && ! is_string($direction)) {
                throw new InvalidArgumentException('A conversion history direction filter must be a single value.');
            }

            $direction = WalletConversionDirection::tryFromString($direction);
        }

        $rawStatuses = $data['statuses'] ?? $data['status'] ?? [];

        if (is_string($rawStatuses) || ! is_array($rawStatuses)) {
            $rawStatuses = [$rawStatuses];
        }

        $statuses = [];

        foreach ($rawStatuses as $candidate) {
            // A filter value that is not a scalar is not a status this platform has
            // ever published — `statuses[0][bad]=value` arrives as an array. Dropping
            // it silently would answer a malformed request with somebody's history and
            // invite the caller to believe the filter was applied; casting it to a
            // string would raise "Array to string conversion" inside a warning handler
            // and surface as a 500. Neither is acceptable, so the malformed value is
            // named and refused.
            if (! $candidate instanceof WalletConversionStatus && ! is_scalar($candidate)) {
                throw new InvalidArgumentException(
                    'A conversion history status filter must be a single published status value.',
                );
            }

            $status = $candidate instanceof WalletConversionStatus
                ? $candidate
                : WalletConversionStatus::tryFrom(strtolower(trim((string) $candidate, " \t\n\r\0\x0B")));

            if ($status instanceof WalletConversionStatus) {
                $statuses[] = $status;
            }
        }

        return new self(
            userId: $userId,
            direction: $direction,
            statuses: $statuses,
            from: self::dateOrNull($data['from'] ?? null),
            to: self::dateOrNull($data['to'] ?? null),
            perPage: self::clampPerPage($data['per_page'] ?? null),
            page: self::pageOrFirst($data['page'] ?? null),
            search: self::stringOrNull($data['search'] ?? null),
        );
    }

    /**
     * The filter an operator dashboard uses by default: everything, newest
     * first, page one.
     */
    public static function forUser(int $userId): self
    {
        return new self(userId: $userId);
    }

    public function hasStatuses(): bool
    {
        return $this->statuses !== [];
    }

    public function hasDateWindow(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    public function hasSearch(): bool
    {
        return $this->search !== null;
    }

    /**
     * @return array<int, string>
     */
    public function statusValues(): array
    {
        return array_map(static fn (WalletConversionStatus $status): string => $status->value, $this->statuses);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'direction' => $this->direction?->value,
            'statuses' => $this->statusValues(),
            'from' => $this->from?->format(DATE_ATOM),
            'to' => $this->to?->format(DATE_ATOM),
            'per_page' => $this->perPage,
            'page' => $this->page,
            'search' => $this->search,
        ];
    }

    /**
     * A page number below one, or one that is not a single scalar, is page one:
     * the member asked for history and history starts at the beginning.
     */
    private static function pageOrFirst(mixed $value): int
    {
        if ($value === null) {
            return 1;
        }

        if (! is_scalar($value)) {
            throw new InvalidArgumentException('A conversion history page number must be a single value.');
        }

        return max(1, (int) $value);
    }

    private static function clampPerPage(mixed $value): int
    {
        if ($value !== null && ! is_scalar($value)) {
            throw new InvalidArgumentException('A conversion history page size must be a single value.');
        }

        $perPage = (int) ($value ?? self::DEFAULT_PER_PAGE);

        if ($perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    private static function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        $string = self::stringOrNull($value);

        if ($string === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($string);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new InvalidArgumentException(
                'A conversion history filter value must be a single scalar value.',
            );
        }

        $string = trim((string) $value, " \t\n\r\0\x0B");

        return $string === '' ? null : $string;
    }
}
