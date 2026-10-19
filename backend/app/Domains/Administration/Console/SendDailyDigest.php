<?php

declare(strict_types=1);

namespace App\Domains\Administration\Console;

use App\Domains\Administration\Notifications\DailyDigestNotification;
use App\Domains\Administration\Services\DailyDigest;
use App\Domains\Administration\Services\DigestWindow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends each user their daily digest of unread notifications
 * (SRS FR-NOT-008; SDD DD-63).
 *
 * Scheduled at **07:00 Asia/Manila**, covering the **previous school-local
 * calendar day**. The rules — which day, who is eligible, what they get, and
 * whether they have already been told — belong to {@see DailyDigest}; this
 * command is the schedule, the loop and the mail.
 *
 * ── At-most-once, by decision ─────────────────────────────────────────────
 *
 * SMTP cannot take part in a database transaction, so exactly-once delivery is
 * not available at any price: a crash between *sending* and *recording* would
 * duplicate, and a crash between *recording* and *sending* would miss. The
 * Client chose to miss. The claim is therefore committed **before** the mail
 * leaves, and a claimed day is never retried — a missed digest is recoverable
 * tomorrow, a duplicate school-wide mailing is not.
 *
 * The consequence, stated plainly because it is a real cost: **a transport
 * failure loses that user's digest for that day.** It is logged and counted,
 * not retried.
 *
 * ── One recipient's failure is not the run's failure ──────────────────────
 *
 * Each user is isolated in its own try/catch, the same posture DD-59 gives the
 * dispatcher. A single bad address, or a transport blip on one message, must
 * not deny the digest to everybody after it in the loop.
 *
 * ── Nothing to say, nothing sent ──────────────────────────────────────────
 *
 * A user with no eligible items gets **no email and no claim row**. The window
 * is a closed calendar day, so an empty day can never later fill: skipping it
 * is a settled answer rather than a deferral, and leaves the table meaning
 * exactly "digests that were sent".
 */
class SendDailyDigest extends Command
{
    protected $signature = 'notifications:send-digest
                            {--date= : School-local Y-m-d to summarise; defaults to yesterday}';

    protected $description = 'Send each user their daily digest of unread notifications';

    public function handle(DailyDigest $digest): int
    {
        $window = $this->resolveWindow($digest);

        $sent = 0;
        $skippedEmpty = 0;
        $alreadySent = 0;
        $failed = 0;

        foreach ($digest->candidates() as $user) {
            try {
                $items = $digest->itemsFor($user, $window);

                if ($items->isEmpty()) {
                    $skippedEmpty++;

                    continue;
                }

                // Claim before sending. If the day is already held -- by a
                // second scheduler tick, a manual invocation, a worker retry or
                // a redeploy -- the database says so and this user is done.
                if (! $digest->claim($user, $window)) {
                    $alreadySent++;

                    continue;
                }

                $user->notify(new DailyDigestNotification($items, $window));
                $sent++;
            } catch (Throwable $e) {
                $failed++;

                /*
                 * Deliberately not re-thrown and deliberately not retried. If
                 * the claim succeeded and the send then failed, that day is
                 * spent for this user -- that is what at-most-once costs, and
                 * hiding it would be worse than recording it.
                 */
                Log::error('Daily digest failed for one recipient.', [
                    'user_id' => $user->getKey(),
                    'digest_date' => $window->date,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            'Daily digest for %s: %d sent, %d with nothing to report, %d already sent, %d failed.',
            $window->date,
            $sent,
            $skippedEmpty,
            $alreadySent,
            $failed,
        ));

        return self::SUCCESS;
    }

    /**
     * The day to summarise.
     *
     * Defaults to the previous school-local day. `--date` names a day directly,
     * which is what makes a missed run recoverable by hand without waiting
     * twenty-four hours — and it is safe to re-run, because the claim decides.
     */
    private function resolveWindow(DailyDigest $digest): DigestWindow
    {
        $date = $this->option('date');

        if ($date === null) {
            return $digest->windowFor(CarbonImmutable::now());
        }

        // Parse in the school's zone, then hand the service a run-time one day
        // later so it resolves to exactly the requested day.
        $requested = CarbonImmutable::parse((string) $date, DailyDigest::TIMEZONE)->startOfDay();

        return $digest->windowFor($requested->addDay());
    }
}
