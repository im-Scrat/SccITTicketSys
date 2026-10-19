<?php

declare(strict_types=1);

namespace App\Support\Queue;

use App\Support\Logging\RedactSecretsProcessor;
use App\Support\Security\ConfiguredSecrets;
use DateTimeInterface;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;
use RuntimeException;

/**
 * Decorates the configured failed-job store so the exception text written to
 * `failed_jobs.exception` never contains this deployment's credentials.
 *
 * The worker stores `(string) $exception` — message, trace and every previous
 * exception — straight into the database, outside any log handler, so the log
 * processor ({@see RedactSecretsProcessor}) never sees it.
 * A queued mail job that fails SMTP authentication would otherwise persist the
 * relay username indefinitely, visible to anyone who can read the table or a
 * backup of it.
 *
 * Every other operation is delegated unchanged, including counting and pruning.
 */
final class RedactingFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
{
    public function __construct(private readonly FailedJobProviderInterface $inner) {}

    /**
     * @param  string  $connection
     * @param  string  $queue
     * @param  string  $payload
     * @return string|int|null
     */
    public function log($connection, $queue, $payload, $exception)
    {
        $text = (string) $exception;
        $redacted = ConfiguredSecrets::redact($text);

        if ($redacted !== $text) {
            $exception = new RedactedJobException($redacted, $exception);
        }

        return $this->inner->log($connection, $queue, $payload, $exception);
    }

    /** @return list<int|string> */
    public function ids($queue = null)
    {
        return $this->inner->ids($queue);
    }

    /** @return array<int, object> */
    public function all()
    {
        return $this->inner->all();
    }

    /** @return object|null */
    public function find($id)
    {
        return $this->inner->find($id);
    }

    /** @return bool */
    public function forget($id)
    {
        return $this->inner->forget($id);
    }

    /** @return void */
    public function flush($hours = null)
    {
        $this->inner->flush($hours);
    }

    /** @return int */
    public function prune(DateTimeInterface $before)
    {
        if (! $this->inner instanceof PrunableFailedJobProvider) {
            throw new RuntimeException('The configured failed-job store does not support pruning.');
        }

        return $this->inner->prune($before);
    }

    /** @return int */
    public function count($connection = null, $queue = null)
    {
        if (! $this->inner instanceof CountableFailedJobProvider) {
            throw new RuntimeException('The configured failed-job store does not support counting.');
        }

        return $this->inner->count($connection, $queue);
    }
}
