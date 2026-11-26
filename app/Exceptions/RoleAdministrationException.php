<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Refusals pronounced by the role administration service.
 *
 * - ROLE_ADMIN_PROTECTED        the role is protected by config/admin.php and may
 *                               only be changed by a super-admin.
 * - ROLE_ADMIN_IN_USE           the role is assigned to at least one account, so
 *                               deleting it would strip access without a decision
 *                               about where those accounts should land.
 * - ROLE_ADMIN_SELF_LOCKOUT     the change would remove the actor's own ability to
 *                               manage roles, i.e. lock the desk out of the door.
 * - ROLE_ADMIN_ESCALATION       the actor tried to grant a permission they do not
 *                               hold themselves.
 * - ROLE_ADMIN_UNKNOWN_PERMISSION the name is not in the granular catalogue.
 * - ROLE_ADMIN_MALFORMED        the role name is not a valid slug / already taken.
 */
final class RoleAdministrationException extends Exception
{
    public const CODE_PROTECTED = 'ROLE_ADMIN_PROTECTED';

    public const CODE_IN_USE = 'ROLE_ADMIN_IN_USE';

    public const CODE_SELF_LOCKOUT = 'ROLE_ADMIN_SELF_LOCKOUT';

    public const CODE_ESCALATION = 'ROLE_ADMIN_ESCALATION';

    public const CODE_UNKNOWN_PERMISSION = 'ROLE_ADMIN_UNKNOWN_PERMISSION';

    public const CODE_MALFORMED = 'ROLE_ADMIN_MALFORMED';

    /**
     * The refusal identifier.
     *
     * Exception::$code is an int, and a role refusal is not a number: callers branch on a
     * stable string (a UI maps it, a log groups by it). It therefore lives in its own
     * readonly property and the numeric code stays 0, rather than pretending the string
     * identifier is an errno.
     */
    private readonly string $refusalCode;

    public function __construct(string $message, string $refusalCode)
    {
        parent::__construct($message);

        $this->refusalCode = $refusalCode;
    }

    public function refusalCode(): string
    {
        return $this->refusalCode;
    }

    public static function protectedRole(string $role): self
    {
        return new self(
            sprintf('The [%s] role is protected: only a super-admin may change or delete it.', $role),
            self::CODE_PROTECTED,
        );
    }

    public static function roleInUse(string $role, int $users): self
    {
        return new self(
            sprintf('The [%s] role is assigned to %d account(s). Reassign them before deleting it.', $role, $users),
            self::CODE_IN_USE,
        );
    }

    public static function selfLockout(string $role): self
    {
        return new self(
            sprintf('That change would remove your own ability to administer the [%s] role.', $role),
            self::CODE_SELF_LOCKOUT,
        );
    }

    public static function escalation(string $permission): self
    {
        return new self(
            sprintf('You cannot grant [%s]: you do not hold it yourself.', $permission),
            self::CODE_ESCALATION,
        );
    }

    public static function unknownPermission(string $permission): self
    {
        return new self(
            sprintf('[%s] is not in the granular permission catalogue.', $permission),
            self::CODE_UNKNOWN_PERMISSION,
        );
    }

    public static function malformed(string $reason): self
    {
        return new self($reason, self::CODE_MALFORMED);
    }
}
