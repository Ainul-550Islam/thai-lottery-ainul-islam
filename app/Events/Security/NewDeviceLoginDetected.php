<?php

declare(strict_types=1);

namespace App\Events\Security;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class NewDeviceLoginDetected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $member,
        public readonly LoginEvent $loginEvent,
    ) {}
}
