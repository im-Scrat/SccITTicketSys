<?php

declare(strict_types=1);

namespace App\Support\Queue;

use App\Support\Security\ConfiguredSecrets;
use RuntimeException;
use Throwable;

/**
 * Carries an already-redacted rendering of a job failure for
 * {@see RedactingFailedJobProvider}. The failed-job store converts the
 * exception to a string, so overriding `__toString()` is what keeps the
 * credential out of `failed_jobs.exception`.
 *
 * The original exception is deliberately *not* chained as `previous`: some
 * renderers walk the chain themselves, and the unredacted message would come
 * straight back.
 */
final class RedactedJobException extends RuntimeException
{
    public function __construct(private readonly string $rendered, Throwable $original)
    {
        parent::__construct(ConfiguredSecrets::redact($original->getMessage()), (int) $original->getCode());
    }

    public function __toString(): string
    {
        return $this->rendered;
    }
}
