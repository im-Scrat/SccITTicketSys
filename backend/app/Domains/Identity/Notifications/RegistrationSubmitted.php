<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to an applicant when a registration request is received (SRS FR-AUTH-013).
 * Sent synchronously for reliable local delivery via Mailpit (SDD §10.3).
 */
class RegistrationSubmitted extends Notification
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
            ->subject('We received your SccIT registration request')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('Thank you for registering for SccIT. Your request has been received and is now awaiting administrator approval.')
            ->line('You will not be able to sign in until an administrator approves your account.')
            ->line('We will email you as soon as your request has been reviewed.');
    }
}
