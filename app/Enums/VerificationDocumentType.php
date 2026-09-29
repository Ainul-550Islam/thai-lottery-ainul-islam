<?php

declare(strict_types=1);

namespace App\Enums;

/*
 * PROMPT 3 — identity-document categories for the member verification
 * surface.
 *
 * "Use only values supported by current domain/configuration": every
 * case maps 1:1 onto the canonical KycDocumentType (the existing
 * vocabulary). This enum adds the submission-surface policy on top —
 * which of those types the member surface ACCEPTS is decided by
 * config/account_verification.php (document_types), never by guesswork.
 */
enum VerificationDocumentType: string
{
    case NationalId = 'national_id';

    case Passport = 'passport';

    case DrivingLicense = 'driving_license';

    case ProofOfAddress = 'proof_of_address';

    case Selfie = 'selfie';

    case Other = 'other';

    /**
     * The canonical KYC-domain case for this category.
     */
    public function toKycDocumentType(): KycDocumentType
    {
        return KycDocumentType::from($this->value);
    }

    /**
     * Lift a canonical KYC case into the submission vocabulary.
     */
    public static function fromKycDocumentType(KycDocumentType $type): self
    {
        return self::from($type->value);
    }

    /**
     * Only the types configured for the member submission surface.
     * Unknown-to-config values are simply not offered — an unknown
     * type in a request fails validation with 422 upstream.
     *
     * @return list<self>
     */
    public static function configured(): array
    {
        $allowed = (array) config('account_verification.document_types', []);

        $cases = [];

        foreach ($allowed as $value) {
            $case = self::tryFrom((string) $value);
            if ($case instanceof self) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /**
     * Comma-joined configured values, ready for Laravel's `in:` rule.
     */
    public static function configuredValuesForValidation(): string
    {
        return implode(',', array_map(
            static fn (self $case): string => $case->value,
            self::configured(),
        ));
    }
}
