<?php

declare(strict_types=1);

namespace App\Enums;

enum LoginEventOutcome: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case MfaRequired = 'mfa_required';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::Failed => 'Failed',
            self::Blocked => 'Blocked',
            self::MfaRequired => 'MFA Required',
        };
    }
}
