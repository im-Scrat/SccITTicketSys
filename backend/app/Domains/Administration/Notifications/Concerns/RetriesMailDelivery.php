<?php

declare(strict_types=1);

namespace App\Domains\Administration\Notifications\Concerns;

/**
 * Retry policy for queued notifications that send email.
 *
 * The worker's own `--backoff=5` spent all three attempts inside ten seconds.
 * That is the wrong shape for an external relay: SMTP2GO answers a rate limit
 * or a temporary failure with a 4xx that clears in minutes, not seconds, and
 * its free plan caps unverified senders at 25 messages an hour. Waiting 1, 5
 * and 15 minutes gives a deferral about twenty minutes to clear before the job
 * is recorded in `failed_jobs`.
 *
 * A retry after the relay *accepted* the message would send it twice; that can
 * only happen if the worker dies between the 250 reply and acknowledging the
 * job, and is the accepted at-least-once cost of SMTP. Permanent rejections
 * (5xx) are retried too — three attempts, then failed — because the transport
 * cannot be asked to tell a bad recipient from a misconfigured relay reliably
 * enough to give up after one.
 */
trait RetriesMailDelivery
{
    public int $tries = 3;

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
