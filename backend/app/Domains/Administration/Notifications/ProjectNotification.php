<?php

declare(strict_types=1);

namespace App\Domains\Administration\Notifications;

use App\Domains\Administration\Notifications\Channels\DatabaseChannel;
use App\Domains\Administration\Services\NotificationPreferences;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * **The base every WP-2.7a notification extends** (SRS FR-NOT-001..006).
 *
 * It fixes four things that must not be decided per-notification, because each
 * of them is a place a single careless subclass could break a guarantee the
 * whole layer claims.
 *
 * ── 1. Channels come from the user's preferences, never from the class ─────
 *
 * {@see via()} is final. A subclass says *which topic* it is; the preference
 * gate says *where it goes* (FR-NOT-002). A notification class that could name
 * its own channels would be a notification the user cannot turn off, and there
 * would be no single place to prove that preferences are honoured.
 *
 * There are two deliberate exceptions, and they pull in opposite directions.
 * {@see forcedChannels()} lets a channel *bypass* the gate: exactly one
 * notification uses it — the account lockout email, because a locked-out user
 * cannot sign in to read an in-app message, and a security notice a user has
 * accidentally silenced is a security notice that does not exist (matrix T11).
 * {@see excludedChannels()} does the reverse and *refuses* a channel outright,
 * for a broadcast that must never become mail — announcements, under the
 * Client's D5 (SRS §22.1), by the mechanism WP-2.7c D1 approved.
 *
 * **Exclusion outranks both the gate and the forced list.** Where the two
 * exceptions could contradict, the reading that sends less wins.
 *
 * Both exceptions apply only to channels that deliver *at dispatch time*.
 * `NotificationChannel::Digest` does not: it aggregates rows that already
 * exist, so `via()` skips it entirely and the daily digest command reads the
 * preference later (SRS FR-NOT-008, SDD DD-63).
 *
 * ── 2. Everything is queued, and queued *after commit* ─────────────────────
 *
 * `ShouldQueue` is on the base, not on subclasses, so no notification can
 * accidentally be sent inline on the request thread. `$afterCommit` is the
 * second half: if a notification is ever dispatched from inside a database
 * transaction, the job is pushed only once that transaction commits — so a
 * rolled-back business action can never leave a worker holding a job about a
 * row that no longer exists.
 *
 * ── 3. Email carries no borrowed words ─────────────────────────────────────
 *
 * {@see toMail()} is built from {@see mailLines()}, which defaults to a
 * factual summary and a link. Free text authored by *another* person — a
 * comment body, a decline reason, a technician's explanation — is deliberately
 * kept out of mail bodies and left to the in-app row, which is behind
 * authentication. FR-NOT-006 asks for content "safe for external delivery"; the
 * cheapest way to be sure of that is for the unsafe material never to reach the
 * mailer at all.
 *
 * ── 4. Retries do not duplicate ────────────────────────────────────────────
 *
 * {@see dedupeKey()} defaults to a per-dispatch identifier generated **in the
 * constructor**, which means it is serialized into the queued job and survives
 * every retry of that job. Two genuinely separate dispatches therefore produce
 * two rows (correct — two things happened), while three attempts at one
 * dispatch produce one row (correct — one thing happened). Scheduled detectors
 * override it with a semantic key, because "run the sweep again tomorrow" must
 * not mean "tell them again tomorrow".
 */
abstract class ProjectNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Stable for the life of this object, and therefore for the life of the
     * queued job that carries it — which is the whole point.
     *
     * Nullable rather than an uninitialized typed property, so that a subclass
     * which defines its own constructor and forgets to chain degrades to
     * {@see stamp()} filling it in rather than to a fatal on first access.
     */
    protected ?string $dispatchId = null;

    public function __construct()
    {
        $this->stamp();

        /*
         * Push the queued job only once the surrounding transaction commits.
         *
         * Set through the trait's own setter rather than by redeclaring the
         * property: `Queueable` already declares `$afterCommit` untyped, and
         * redeclaring it with a type is a fatal composition error.
         *
         * Defence in depth rather than the primary guard — `NotificationDispatcher`
         * already defers through `DB::afterCommit()`, and that is the one that
         * also keeps the failure inside a try/catch. This flag is what protects a
         * notification sent by some future caller that bypasses the dispatcher.
         */
        $this->afterCommit();
    }

    /**
     * Ensure the dispatch identifier exists before the notification is queued.
     *
     * Called by the constructor, by {@see dedupeKey()}, and by
     * `NotificationDispatcher` — the last of which is what makes the identifier
     * fixed at *dispatch* time rather than at delivery time. That distinction is
     * the whole of the retry guarantee: a value generated in the worker would be
     * new on every attempt and would deduplicate nothing.
     */
    public function stamp(): static
    {
        $this->dispatchId ??= (string) Str::uuid();

        return $this;
    }

    /** Which FR-NOT-003 trigger this is. */
    abstract public function topic(): NotificationTopic;

    /**
     * The in-app row this notification writes.
     *
     * `action_url` must be a **relative** SPA path. FR-QR-011 established the
     * rule for the QR workflow and it holds everywhere: a destination the
     * system builds is safe, a destination something else supplied is an open
     * redirect waiting to be found.
     *
     * @return array{title: string, message: string|null, data: array<string, mixed>, action_url: string|null}
     */
    abstract public function payload(User $notifiable): array;

    /**
     * Channels that ignore the preference gate.
     *
     * @return list<NotificationChannel>
     */
    public function forcedChannels(): array
    {
        return [];
    }

    /**
     * Channels this notification must never use, whatever anyone prefers.
     *
     * The mirror image of {@see forcedChannels()}, and the stronger of the two:
     * exclusion beats both the preference gate *and* a forced channel, so the
     * two lists can never contradict each other into an undefined result.
     *
     * ── Why a notification may name a channel it must not use ──────────────
     *
     * The rule above — the class says *what*, the gate says *where* — holds for
     * every notification that is addressed to somebody about their own work. It
     * breaks for a **broadcast**. Announcements go to an entire audience at once
     * (FR-NOT-010), and the Client's D5 (SRS §22.1) is that publishing one
     * creates an in-app notification and sends **no email**. WP-2.7c D1
     * approved this mechanism as the way to enforce it.
     *
     * The preference gate cannot deliver that guarantee. Preferences are
     * opt-out: an absent row means *enabled*, so on a fresh system every
     * recipient would be mailed. Seeding the email preference off for everyone
     * was considered and rejected — a user can turn it back on, and every new
     * user would need seeding forever, which makes a system guarantee depend on
     * a row per channel and type staying correct in perpetuity.
     *
     * So the guarantee lives here, in the dispatch path, where it is one line
     * and provable. A channel named here is refused before the gate is even
     * consulted; the user's preference for that type still governs the channels
     * that remain.
     *
     * @return list<NotificationChannel>
     */
    public function excludedChannels(): array
    {
        return [];
    }

    /**
     * The idempotency key, scoped per recipient.
     *
     * @see DatabaseChannel
     */
    public function dedupeKey(): ?string
    {
        return $this->topic()->value.':'.$this->stamp()->dispatchId;
    }

    /**
     * Where this notification goes for this recipient (FR-NOT-002).
     *
     * @return list<string>
     */
    final public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $gate = app(NotificationPreferences::class);
        $forced = $this->forcedChannels();
        $excluded = $this->excludedChannels();
        $channels = [];

        foreach (NotificationChannel::cases() as $channel) {
            // A channel that does not deliver at dispatch time is not this
            // method's business. `Digest` is the only one today: it aggregates
            // rows that already exist, so the daily command reads the user's
            // preference later (SRS FR-NOT-008, SDD DD-63).
            //
            // This skip is load-bearing. The gate is opt-out, so without it an
            // absent preference row would report `Digest` as allowed for every
            // user, and the dispatch would fail for everyone rather than for
            // anyone who had opted in.
            $driver = $channel->driver();

            if ($driver === null) {
                continue;
            }

            // Exclusion is checked first among the dispatchable channels and
            // wins outright. It has to outrank the forced list as well as the
            // gate: if the two ever disagreed, the safe reading of "this must
            // not be emailed" is the one that sends no email.
            if (in_array($channel, $excluded, true)) {
                continue;
            }

            $allowed = in_array($channel, $forced, true)
                || $gate->allows($notifiable, $channel, $this->topic()->type());

            if (! $allowed) {
                continue;
            }

            $channels[] = $driver;
        }

        return $channels;
    }

    /**
     * The email form (FR-NOT-006).
     *
     * Subject and greeting are uniform; the body is whatever
     * {@see mailLines()} judges safe to send outside the application.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $payload = $this->payload($notifiable);

        $mail = (new MailMessage)
            ->subject('SccIT: '.$payload['title'])
            ->greeting("Hello {$notifiable->first_name},");

        foreach ($this->mailLines($payload) as $line) {
            $mail->line($line);
        }

        return $mail->line('Sign in to SccIT to see the details.');
    }

    /**
     * The lines an email may carry.
     *
     * The default is the notification's own title and nothing else — no message
     * body, because `payload()['message']` is where quoted human text lives and
     * that is exactly what must not be mailed. A subclass may add lines it has
     * *composed itself* from structural facts (a ticket number, a status, a
     * date); it must not add text a user typed.
     *
     * @param  array{title: string, message: string|null, data: array<string, mixed>, action_url: string|null}  $payload
     * @return list<string>
     */
    protected function mailLines(array $payload): array
    {
        return [$payload['title'].'.'];
    }
}
