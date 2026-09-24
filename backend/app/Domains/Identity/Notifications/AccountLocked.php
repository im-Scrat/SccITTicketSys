<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * **T11 — your account was temporarily locked** (SRS FR-AUTH-006, FR-NOT-003).
 *
 * Sent once, when the failed-attempt threshold is crossed.
 *
 * ── Email is forced, and this is the only notification that forces anything ──
 *
 * A locked-out user cannot sign in, so an in-app row is a message left inside a
 * door they have just been shut out of. Email is the only channel that reaches
 * them, which is why {@see forcedChannels()} makes it bypass the preference gate:
 * a security notice a user silenced six months ago is a security notice that
 * does not exist. The in-app row is written as well, subject to the ordinary
 * preference, so the alert is waiting on the notification centre when they get
 * back in.
 *
 * WP-2.7a is what gives this notification its in-app half. It has been mailing
 * since Phase 2.2; registering the project database channel (DD-52) is all that
 * was needed to make it visible in the application too.
 *
 * ── What the message may say ───────────────────────────────────────────────
 *
 * The duration, and nothing else. The matrix is explicit that source IP and
 * attempt details must not be disclosed, and the reason is that the person
 * reading this may not be the person who caused it: an attacker who locks
 * someone's account by guessing at it would otherwise be handed a report of
 * their own reconnaissance, delivered to a mailbox they may already control.
 */
class AccountLocked extends ProjectNotification
{
    public function __construct(public readonly int $seconds)
    {
        parent::__construct();
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::AccountLocked;
    }

    /**
     * @return list<NotificationChannel>
     */
    public function forcedChannels(): array
    {
        return [NotificationChannel::Email];
    }

    public function payload(User $notifiable): array
    {
        return [
            'title' => 'Your account was temporarily locked',
            'message' => sprintf(
                'Sign-in was locked for about %d minute(s) after several failed attempts.',
                $this->minutes(),
            ),
            'data' => [
                'minutes' => $this->minutes(),
                // Deliberately no ip_address, user agent or attempt count.
            ],
            // No action_url: the destination would be the sign-in page they have
            // just been refused, and a link inviting them to retry during a
            // lockout is an invitation to burn the remaining window.
            'action_url' => null,
        ];
    }

    /**
     * The mail body predates this work package and is kept as it was — it is a
     * security notice with wording that has already shipped, and the base class
     * default would replace considered text with a generic line.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Security alert: your SccIT account was temporarily locked')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('We detected several failed sign-in attempts on your account.')
            ->line("As a precaution, sign-in has been temporarily locked. You can try again in about {$this->minutes()} minute(s).")
            ->line('If this was not you, please reset your password and contact your administrator.');
    }

    private function minutes(): int
    {
        return max(1, (int) ceil($this->seconds / 60));
    }
}
