<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to an applicant when an Administrator rejects their registration request
 * (SRS FR-AUTH-015). Includes the rejection reason when one was provided.
 */
class RegistrationRejected extends Notification
{
    public function __construct(public readonly ?string $reason = null) {}

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
            ->subject('Update on your SccIT registration')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('Thank you for your interest in SccIT.')
            ->line('After review, your registration request was not approved at this time.')
            ->when(
                filled($this->reason),
                fn (MailMessage $message): MailMessage => $message->line("Reason: {$this->reason}"),
            )
            ->line('If you believe this was a mistake, please contact your administrator.');
    }
}
