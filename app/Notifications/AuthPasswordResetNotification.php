<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/*
 * PROMPT 3 — the password-reset notification.
 *
 * SEMANTICS: the recovery capability is the broker token — generated
 * server-side, stored HASHED at rest (password_reset_tokens.token),
 * expiring (config/auth.php expire) and deleted on first successful
 * use (single-use). This notification only DELIVERS that capability.
 *
 * WHAT IT NEVER CONTAINS: the plaintext or new password (none exists
 * yet at request time), any internal id, any storage path. The token
 * appears solely inside the action link — the intended mechanism —
 * and is never logged (MailMessage rendering writes no logs).
 *
 * LOCALIZED: subject/body via the existing en/th translation
 * architecture; the wording is deliberately generic account-recovery
 * copy (no mention of whether anything was found — the email itself
 * is only ever sent for existing accounts, and the HTTP surface
 * answers identically for every identifier).
 */
class AuthPasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $token  the raw broker token (hashed at rest by the framework)
     */
    public function __construct(
        public readonly string $token,
    ) {}

    /**
     * @param  User  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $expireMinutes = max(1, (int) config('auth_security.password_reset.expiry_minutes', 60));

        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject(__('public_pages.reset_mail_subject'))
            ->line(__('public_pages.reset_mail_line1'))
            ->action(__('public_pages.reset_mail_action'), $url)
            ->line(__('public_pages.reset_mail_line2', ['minutes' => $expireMinutes]))
            ->line(__('public_pages.reset_mail_line3'));
    }
}
