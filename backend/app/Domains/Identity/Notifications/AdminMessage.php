<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A free-form message an administrator sends to selected users from the bulk
 * "Send notification emails" action (SRS v1.2 FR-USER bulk operations). The body
 * is plain text supplied by the administrator; no templating or links are
 * injected beyond the standard mail shell.
 */
class AdminMessage extends Notification
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly array $lines,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subjectLine)
            ->greeting("Hello {$notifiable->first_name},");

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail;
    }
}
