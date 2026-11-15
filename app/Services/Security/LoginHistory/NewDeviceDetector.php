<?php

declare(strict_types=1);

namespace App\Services\Security\LoginHistory;

use App\Enums\DeviceTrustStatus;
use App\Enums\LoginEventOutcome;
use App\Models\LoginEvent;
use App\Models\TrustedDevice;
use App\Models\User;

final class NewDeviceDetector
{
    public function matchedTrustedDevice(User|int $member, string $deviceFingerprint): ?TrustedDevice
    {
        if ($deviceFingerprint === '') {
            return null;
        }

        $memberId = $member instanceof User ? (int) $member->getKey() : $member;

        /** @var TrustedDevice|null $device */
        $device = TrustedDevice::query()
            ->where('user_id', $memberId)
            ->where('device_fingerprint', $deviceFingerprint)
            ->whereNull('revoked_at')
            ->first();

        return $device;
    }

    public function isNewDevice(User|int $member, string $deviceFingerprint): bool
    {
        if ($deviceFingerprint === '') {
            return false;
        }

        $memberId = $member instanceof User ? (int) $member->getKey() : $member;

        $trustedExists = TrustedDevice::query()
            ->where('user_id', $memberId)
            ->where('device_fingerprint', $deviceFingerprint)
            ->where('trust_status', DeviceTrustStatus::Trusted->value)
            ->whereNull('revoked_at')
            ->exists();

        if ($trustedExists) {
            return false;
        }

        $seenSuccessfulLogin = LoginEvent::query()
            ->where('member_id', $memberId)
            ->where('device_fingerprint', $deviceFingerprint)
            ->where('outcome', LoginEventOutcome::Success->value)
            ->exists();

        return ! $seenSuccessfulLogin;
    }
}
