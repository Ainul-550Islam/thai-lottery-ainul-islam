<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * PrivacyRequestStatus — the lifecycle of one data-subject request.
 *
 * THE CLOCK STARTS AT RECEIVED, NOT AT SUBMITTED. A public submission proves
 * nothing about who sent it; it sits in PendingVerification until the address
 * it names opens a single-use link. Treating an unverified submission as
 * "received" would start a statutory clock on a request the platform cannot
 * lawfully answer and cannot attribute.
 *
 * EVERY TRANSITION IS LISTED. allowedTransitions() is the only authority on
 * what may follow what, and PrivacyRequestService refuses anything absent from
 * it. That is what makes "the request went backwards" or "it was completed
 * without review" impossible rather than merely unlikely: there is no code
 * path that can write an arbitrary status.
 *
 * TERMINAL MEANS TERMINAL. Completed, Rejected, Expired and Cancelled have no
 * outgoing edges. A reopened request is a NEW request with its own reference
 * and its own clock, because merging the two would make the first one's
 * timeline a lie.
 */
enum PrivacyRequestStatus: string
{
    case PendingVerification = 'pending_verification';
    case Received = 'received';
    case InReview = 'in_review';
    case NeedsInformation = 'needs_information';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function labelKey(): string
    {
        return 'privacy_requests.statuses.'.$this->value;
    }

    public function label(): string
    {
        return (string) trans($this->labelKey());
    }

    /**
     * A state nothing may follow.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Rejected,
            self::Expired,
            self::Cancelled,
        ], true);
    }

    /**
     * Has the request been received in the legal sense — that is, may its SLA
     * clock have started?
     */
    public function countsAsReceived(): bool
    {
        return ! in_array($this, [self::PendingVerification, self::Expired], true);
    }

    /**
     * Is this request still occupying a slot against max_open_per_email?
     */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * States in which staff may still add information or act.
     */
    public function isActionable(): bool
    {
        return in_array($this, [
            self::Received,
            self::InReview,
            self::NeedsInformation,
            self::InProgress,
        ], true);
    }

    /**
     * The complete transition table.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Only the requester's own verification (or the clock) may leave
            // this state. Staff cannot shortcut it: an unverified request is
            // not attributable, so acting on it would be acting on nobody.
            self::PendingVerification => [self::Received, self::Expired, self::Cancelled],

            self::Received => [
                self::InReview,
                self::NeedsInformation,
                self::Rejected,
                self::Expired,
                self::Cancelled,
            ],

            self::InReview => [
                self::NeedsInformation,
                self::InProgress,
                self::Completed,
                self::Rejected,
                self::Cancelled,
            ],

            self::NeedsInformation => [self::InReview, self::Expired, self::Cancelled],

            self::InProgress => [
                self::NeedsInformation,
                self::Completed,
                self::Rejected,
                self::Cancelled,
            ],

            self::Completed, self::Rejected, self::Expired, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return $target !== $this && in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        ));
    }

    /**
     * Every value a request can still reach from here, for validation rules.
     *
     * @return list<string>
     */
    public function transitionValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            $this->allowedTransitions(),
        ));
    }
}
