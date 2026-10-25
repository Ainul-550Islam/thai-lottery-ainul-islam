<?php

declare(strict_types=1);

namespace App\Exceptions\Privacy;

use RuntimeException;

/**
 * A privacy-request domain refusal with a stable, machine-readable code.
 *
 * WHY THIS EXISTS. The lane has several honest refusals - the feature is off,
 * the request is not verified, the transition is not allowed, a retention duty
 * blocks an erasure, an export is not ready - and every one of them has to be
 * reported WITHOUT leaking internals. A single exception type with a code lets
 * the HTTP layer map a refusal to a status and a safe sentence, and lets the
 * tests assert the code rather than a message's wording.
 *
 * NOTHING SENSITIVE IS CARRIED. There is no address, no free text from the
 * request and no database detail in the message: only the code and, where it
 * is genuinely useful to an operator, the two states involved in a refused
 * transition. The translatable sentence lives in lang/, resolved by the caller.
 */
final class PrivacyRequestException extends RuntimeException
{
    public const CODE_DISABLED = 'privacy_requests_disabled';

    public const CODE_TYPE_UNSUPPORTED = 'privacy_request_type_unsupported';

    public const CODE_NOT_VERIFIED = 'privacy_request_not_verified';

    public const CODE_NOT_OWNER = 'privacy_request_not_owner';

    public const CODE_NOT_CANCELLABLE = 'privacy_request_not_cancellable';

    public const CODE_INVALID_TRANSITION = 'privacy_request_invalid_transition';

    public const CODE_ACCOUNT_UNRESOLVED = 'privacy_request_account_unresolved';

    public const CODE_RETENTION_BLOCKED = 'privacy_request_retention_blocked';

    /**
     * An erasure or restriction was refused for a reason other than the
     * retention obligation. There is no such lawful reason, so this is a
     * programming error surfaced as a refusal.
     */
    public const CODE_RETENTION_REASON_REQUIRED = 'privacy_request_retention_reason_required';

    public const CODE_EXPORT_NOT_READY = 'privacy_export_not_ready';

    public const CODE_EXPORT_EXPIRED = 'privacy_export_expired';

    public const CODE_EXPORT_LIMIT = 'privacy_export_download_limit_reached';

    public const CODE_EXPORT_MISSING = 'privacy_export_file_missing';

    public const CODE_TOKEN_INVALID = 'privacy_verification_token_invalid';

    public const CODE_TOO_MANY_OPEN = 'privacy_request_too_many_open';

    /**
     * @param  array<string, string|int|null>  $context
     */
    private function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self(self::CODE_DISABLED, 'The privacy-request lane is not enabled for this environment.');
    }

    public static function typeUnsupported(string $type): self
    {
        return new self(
            self::CODE_TYPE_UNSUPPORTED,
            'That request type is not accepted by this deployment.',
            ['type' => $type],
        );
    }

    public static function notVerified(): self
    {
        return new self(self::CODE_NOT_VERIFIED, 'The requester has not verified the address this request names.');
    }

    public static function notOwner(): self
    {
        return new self(self::CODE_NOT_OWNER, 'The request does not belong to the acting principal.');
    }

    public static function notCancellable(string $status): self
    {
        return new self(
            self::CODE_NOT_CANCELLABLE,
            'The request can no longer be cancelled from its current state.',
            ['status' => $status],
        );
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self(
            self::CODE_INVALID_TRANSITION,
            'That state change is not allowed by the request lifecycle.',
            ['from' => $from, 'to' => $to],
        );
    }

    public static function accountUnresolved(int $matches): self
    {
        return new self(
            self::CODE_ACCOUNT_UNRESOLVED,
            'No single account could be resolved for this request.',
            ['matches' => $matches],
        );
    }

    /**
     * @param  list<string>  $reasonCodes  the guard's verdict, by code
     */
    public static function retentionBlocked(array $reasonCodes = []): self
    {
        return new self(
            self::CODE_RETENTION_BLOCKED,
            'Records this platform must retain block this request. Review the retention report, then either reject the request citing the obligation or record why you are completing it anyway.',
            ['reasons' => $reasonCodes],
        );
    }

    public static function retentionReasonRequired(string $reference): self
    {
        return new self(
            self::CODE_RETENTION_REASON_REQUIRED,
            'An erasure or restriction can only be refused for the retention obligation. Choose that reason.',
            ['reference' => $reference],
        );
    }

    public static function exportNotReady(): self
    {
        return new self(self::CODE_EXPORT_NOT_READY, 'No ready personal-data export exists for this request.');
    }

    public static function exportExpired(): self
    {
        return new self(self::CODE_EXPORT_EXPIRED, 'The personal-data export has expired.');
    }

    public static function exportLimitReached(): self
    {
        return new self(self::CODE_EXPORT_LIMIT, 'The personal-data export has reached its download limit.');
    }

    public static function exportFileMissing(): self
    {
        return new self(self::CODE_EXPORT_MISSING, 'The personal-data export file is no longer on the configured disk.');
    }

    public static function tokenInvalid(): self
    {
        return new self(self::CODE_TOKEN_INVALID, 'The verification token is not usable.');
    }

    public static function tooManyOpen(): self
    {
        return new self(self::CODE_TOO_MANY_OPEN, 'The address already holds the maximum number of open requests.');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, string|int|null>
     */
    public function context(): array
    {
        return $this->context;
    }
}
