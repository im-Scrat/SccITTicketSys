<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a user when their account is temporarily locked after too many failed
 * sign-in attempts (SRS FR-AUTH-006). Sent once, when the threshold is crossed.
 */
class AccountLocked extends Notification
{
    public function __construct(public readonly int $seconds) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = max(1, (int) ceil($this->seconds / 60));

        return (new MailMessage)
            ->subject('Security alert: your SccIT account was temporarily locked')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('We detected several failed sign-in attempts on your account.')
            ->line("As a precaution, sign-in has been temporarily locked. You can try again in about {$minutes} minute(s).")
            ->line('If this was not you, please reset your password and contact your administrator.');
    }
}
