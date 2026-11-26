<?php

declare(strict_types=1);

namespace App\Listeners\Security;

use App\Enums\LoginEventOutcome;
use App\Events\MfaChallengeVerified;
use App\Models\User;
use App\Services\Security\LoginHistory\LoginEventRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;

final class RecordLoginEvent
{
    /** @var \WeakMap<object, true>|null */
    private static ?\WeakMap $handled = null;

    public function __construct(
        private readonly LoginEventRecorder $recorder,
    ) {}

    public function handle(Login|Failed|Lockout|MfaChallengeVerified $event): void
    {
        // Guard against duplicate registration (discovery + explicit wiring): the
        // very same event object is recorded at most once, so failed-attempt
        // counts used for brute-force limits can never be doubled.
        self::$handled ??= new \WeakMap;
        if (isset(self::$handled[$event])) {
            return;
        }
        self::$handled[$event] = true;

        match (true) {
            $event instanceof Login => $this->recordLogin($event),
            $event instanceof Failed => $this->recordFailed($event),
            $event instanceof Lockout => $this->recordLockout($event),
            $event instanceof MfaChallengeVerified => $this->recordMfaVerified($event),
        };
    }

    private function recordLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->recorder->recordFromRequest(
            request: request(),
            member: $event->user,
            identifier: (string) ($event->user->email ?: $event->user->username),
            outcome: LoginEventOutcome::Success,
        );
    }

    private function recordFailed(Failed $event): void
    {
        $member = $event->user instanceof User ? $event->user : null;
        $identifier = (string) ($event->credentials['email'] ?? $event->credentials['username'] ?? $event->credentials['login'] ?? ($member?->email ?? 'unknown'));

        $this->recorder->recordFromRequest(
            request: request(),
            member: $member,
            identifier: $identifier,
            outcome: LoginEventOutcome::Failed,
        );
    }

    private function recordLockout(Lockout $event): void
    {
        $identifier = (string) ($event->request->input('email') ?? $event->request->input('username') ?? $event->request->input('login') ?? 'unknown');
        /** @var User|null $member */
        $member = User::query()
            ->where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        $this->recorder->recordFromRequest(
            request: $event->request,
            member: $member,
            identifier: $identifier,
            outcome: LoginEventOutcome::Blocked,
        );
    }

    private function recordMfaVerified(MfaChallengeVerified $event): void
    {
        /** @var User|null $member */
        $member = User::query()->find($event->challenge->user_id);
        if (! $member instanceof User) {
            return;
        }

        $this->recorder->recordFromRequest(
            request: request(),
            member: $member,
            identifier: (string) ($member->email ?: $member->username),
            outcome: LoginEventOutcome::Success,
        );
    }
}
