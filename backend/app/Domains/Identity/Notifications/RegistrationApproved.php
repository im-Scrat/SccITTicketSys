<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to an applicant when an Administrator approves their registration and the
 * account becomes active (SRS FR-AUTH-015).
 */
class RegistrationApproved extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your SccIT account has been approved')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('Good news — your SccIT registration has been approved and your account is now active.')
            ->action('Sign in to SccIT', rtrim((string) config('app.frontend_url'), '/').'/sign-in')
            ->line('You can now sign in with the email address and password you registered with.');
    }
}
