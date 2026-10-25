<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * PrivacyRequestType — the closed vocabulary of data-subject requests.
 *
 * WHY AN ENUM AND NOT A STRING. A request type decides what the platform
 * owes the requester: an access or portability request is owed a COPY of the
 * data, an erasure or restriction request is owed a REVIEWED DECISION that may
 * lawfully be refused, and a withdrawal of consent changes a preference. Those
 * are different obligations, so they are different cases with different
 * capabilities rather than one free-text field somebody interprets later.
 *
 * NOTHING HERE PROMISES AN OUTCOME. requiresExport() says a copy is the shape
 * of the answer, not that every row can be produced; requiresLegalReview()
 * says a human must decide, not that the decision will be in the requester's
 * favour. The retention guard exists precisely because some data must be kept.
 */
enum PrivacyRequestType: string
{
    case Access = 'access';
    case Portability = 'portability';
    case Rectification = 'rectification';
    case Erasure = 'erasure';
    case Restriction = 'restriction';
    case Objection = 'objection';
    case WithdrawConsent = 'withdraw_consent';

    /**
     * The translation key for this type's human label.
     *
     * A key, never a sentence: the label is rendered in the requester's (and
     * the operator's) locale, and a hard-coded English sentence here would leak
     * into every language.
     */
    public function labelKey(): string
    {
        return 'privacy_requests.types.'.$this->value;
    }

    public function label(): string
    {
        return (string) trans($this->labelKey());
    }

    /**
     * Does answering this request mean producing a machine-readable copy of the
     * requester's own data?
     */
    public function requiresExport(): bool
    {
        return in_array($this, [self::Access, self::Portability], true);
    }

    /**
     * Must a human reviewer decide before anything is done?
     *
     * Erasure, restriction, objection and consent withdrawal all collide with a
     * retention duty, so none of them may be executed by a job. They are
     * reviewed, and the reviewer is told exactly what must be kept and why.
     */
    public function requiresLegalReview(): bool
    {
        return in_array($this, [
            self::Erasure,
            self::Restriction,
            self::Objection,
            self::WithdrawConsent,
        ], true);
    }

    /**
     * Does granting this request have the potential to destroy or restrict
     * access to records the platform is obliged to keep?
     */
    public function touchesRetention(): bool
    {
        return in_array($this, [self::Erasure, self::Restriction], true);
    }

    /**
     * Every case as a plain value list, for validation rules.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            self::cases(),
        ));
    }

    /**
     * The types this deployment publishes as accepted intake values.
     *
     * Read from configuration so an operator can withdraw a type (say,
     * portability) without a code change. A value in this list that is not a
     * case is ignored rather than trusted.
     *
     * @return list<self>
     */
    public static function accepted(): array
    {
        $configured = (array) config('privacy_requests.request_types', []);

        $accepted = [];

        foreach ($configured as $value) {
            $case = is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;

            if ($case instanceof self && ! in_array($case, $accepted, true)) {
                $accepted[] = $case;
            }
        }

        return $accepted;
    }

    /**
     * @return list<string>
     */
    public static function acceptedValues(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            self::accepted(),
        ));
    }
}
