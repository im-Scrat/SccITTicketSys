<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Throwable;

/**
 * Names *why* an outgoing message failed, from what the mail transport raised —
 * one vocabulary for report-delivery rows, the diagnostics panel and
 * `mail:check`.
 *
 * Symfony Mailer passes the SMTP reply code as the exception code: the
 * `UnexpectedResponseException` for a refused command, and the authentication
 * `TransportException` (535). A failure before any reply — DNS, TCP, TLS — has
 * code 0; two of those are recognised by Symfony's own fixed wording, never by
 * the remote server's text.
 *
 * Only the class name leaves this method. Server replies can quote addresses
 * and the relay account name, so they are never stored or displayed.
 */
final class MailFailure
{
    public const AUTH_FAILED = 'smtp_auth_failed';

    public const REJECTED = 'smtp_rejected';

    public const DEFERRED = 'smtp_deferred';

    public const TLS_UNAVAILABLE = 'tls_unavailable';

    public const CONNECTION_FAILED = 'connection_failed';

    public const TRANSPORT_ERROR = 'transport_error';

    public static function classify(mixed $exception): string
    {
        if (! $exception instanceof Throwable) {
            return self::TRANSPORT_ERROR;
        }

        $code = (int) $exception->getCode();
        $message = $exception->getMessage();

        return match (true) {
            $code === 535 => self::AUTH_FAILED,
            $code >= 500 && $code < 600 => self::REJECTED,
            $code >= 400 && $code < 500 => self::DEFERRED,
            str_contains($message, 'TLS required') || str_contains($message, 'Unable to connect with STARTTLS') => self::TLS_UNAVAILABLE,
            str_starts_with($message, 'Connection could not be established') => self::CONNECTION_FAILED,
            default => self::TRANSPORT_ERROR,
        };
    }

    /** The SMTP reply code, when the failure carried one. */
    public static function smtpCode(mixed $exception): ?int
    {
        $code = $exception instanceof Throwable ? (int) $exception->getCode() : 0;

        return $code >= 200 && $code < 600 ? $code : null;
    }
}
