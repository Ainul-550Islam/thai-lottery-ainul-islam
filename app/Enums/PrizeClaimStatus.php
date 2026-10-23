<?php

declare(strict_types=1);

namespace App\Enums;

enum PrizeClaimStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Disbursing = 'disbursing';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under Review',
            self::Approved => 'Approved',
            self::Disbursing => 'Disbursing',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Paid, self::Rejected, self::Expired => true,
            self::Submitted, self::UnderReview, self::Approved, self::Disbursing => false,
        };
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::UnderReview, self::Approved, self::Rejected, self::Expired],
            self::UnderReview => [self::Approved, self::Rejected, self::Expired],
            self::Approved => [self::Disbursing, self::Expired],
            self::Disbursing => [self::Paid],
            self::Paid, self::Rejected, self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Submitted => 'blue',
            self::UnderReview => 'yellow',
            self::Approved => 'indigo',
            self::Disbursing => 'cyan',
            self::Paid => 'green',
            self::Rejected => 'red',
            self::Expired => 'gray',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
