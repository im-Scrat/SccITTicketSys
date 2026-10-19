<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

/**
 * What the outgoing-mail setup is, and whether it works — for
 * `php artisan mail:check` and the administrator's mail panel, which exists
 * because Render's free instances have no shell (render.com/docs/free).
 *
 * It reports configuration without credentials (a username or password is
 * only ever "set" or "not set"), can open a connection to the relay — connect,
 * EHLO, STARTTLS, authenticate — **without sending anything**, and can send
 * one fixed message. Every failure is reduced to a {@see MailFailure} name and
 * the SMTP code; the server's own text is never returned.
 */
final class MailDiagnostics
{
    public const REDIRECT_NONE = 'none';

    public const REDIRECT_ACTIVE = 'active';

    public const REDIRECT_IGNORED = 'ignored_in_production';

    /**
     * Whether MAIL_TEST_REDIRECT_TO is in force. Production ignores it — see
     * config/mail.php — so a value left behind cannot divert real users' mail.
     */
    public static function redirectState(): string
    {
        $to = config('mail.test_redirect_to');

        if (! is_string($to) || trim($to) === '') {
            return self::REDIRECT_NONE;
        }

        return app()->environment('production') ? self::REDIRECT_IGNORED : self::REDIRECT_ACTIVE;
    }

    /**
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        $mailer = (string) config('mail.default');
        /** @var array<string, mixed> $settings */
        $settings = (array) config("mail.mailers.{$mailer}", []);
        $transport = (string) ($settings['transport'] ?? $mailer);
        $host = isset($settings['host']) ? (string) $settings['host'] : null;
        $redirect = self::redirectState();

        return [
            'mailer' => $mailer,
            'transport' => $transport,
            'host' => $host,
            'port' => isset($settings['port']) ? (int) $settings['port'] : null,
            'scheme' => $this->filledString($settings['scheme'] ?? null),
            'require_tls' => (bool) ($settings['require_tls'] ?? false),
            'timeout_seconds' => isset($settings['timeout']) ? (float) $settings['timeout'] : null,
            'ehlo_domain' => $this->filledString($settings['local_domain'] ?? null),
            'username_set' => $this->filledString($settings['username'] ?? null) !== null,
            'password_set' => $this->filledString($settings['password'] ?? null) !== null,
            'from_address' => (string) config('mail.from.address'),
            'from_name' => (string) config('mail.from.name'),
            'redirect' => $redirect,
            'redirect_to' => $redirect === self::REDIRECT_NONE ? null : $this->mask((string) config('mail.test_redirect_to')),
            'frontend_url' => (string) config('app.frontend_url'),
            'reports_deliver_externally' => (bool) config('reports.email.delivers_externally', false),
            'warnings' => $this->warnings($transport, $host, $settings, $redirect),
        ];
    }

    /**
     * Connect, greet, negotiate TLS and authenticate — then disconnect. No
     * message is sent.
     *
     * @return array{ok: bool, outcome: string, smtp_code: int|null}
     */
    public function probe(): array
    {
        try {
            $mailer = Mail::mailer();
            $transport = $mailer instanceof Mailer ? $mailer->getSymfonyTransport() : null;
        } catch (Throwable $e) {
            return ['ok' => false, 'outcome' => MailFailure::classify($e), 'smtp_code' => null];
        }

        if (! $transport instanceof SmtpTransport) {
            return ['ok' => false, 'outcome' => 'not_smtp', 'smtp_code' => null];
        }

        try {
            $transport->start();
            $transport->stop();

            return ['ok' => true, 'outcome' => 'ok', 'smtp_code' => null];
        } catch (Throwable $e) {
            try {
                $transport->stop();
            } catch (Throwable) {
                // Already closed.
            }

            return ['ok' => false, 'outcome' => MailFailure::classify($e), 'smtp_code' => MailFailure::smtpCode($e)];
        }
    }

    /**
     * Send the fixed test message to one address, synchronously.
     *
     * @return array{ok: bool, outcome: string, smtp_code: int|null, message_id: string|null}
     */
    public function sendTest(string $to, string $requestedBy): array
    {
        $body = implode("\n\n", [
            'This is a test message from '.config('app.name', 'SccIT').'.',
            "It was sent by {$requestedBy} to confirm that outgoing email works. No action is needed.",
            'Sent '.now()->toIso8601String().'.',
        ]);

        try {
            $sent = Mail::raw($body, function ($message) use ($to): void {
                $message->to($to)->subject(config('app.name', 'SccIT').' — mail delivery test');
            });

            return ['ok' => true, 'outcome' => 'accepted', 'smtp_code' => null, 'message_id' => $sent?->getMessageId()];
        } catch (Throwable $e) {
            return ['ok' => false, 'outcome' => MailFailure::classify($e), 'smtp_code' => MailFailure::smtpCode($e), 'message_id' => null];
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function warnings(string $transport, ?string $host, array $settings, string $redirect): array
    {
        $warnings = [];
        $catcher = $host !== null && (str_starts_with($host, 'mailpit') || in_array($host, ['localhost', '127.0.0.1'], true));
        $realRelay = $transport === 'smtp' && ! $catcher;

        if ($redirect === self::REDIRECT_IGNORED) {
            $warnings[] = 'MAIL_TEST_REDIRECT_TO is set but ignored in production; remove it.';
        }

        if ($realRelay && ! (bool) ($settings['require_tls'] ?? false) && ($settings['scheme'] ?? null) !== 'smtps') {
            $warnings[] = 'A real relay is configured without MAIL_REQUIRE_TLS=true: credentials could be sent unencrypted.';
        }

        if ($realRelay && ($this->filledString($settings['username'] ?? null) === null || $this->filledString($settings['password'] ?? null) === null)) {
            $warnings[] = 'A real relay is configured without a username or password.';
        }

        $frontend = (string) parse_url((string) config('app.frontend_url'), PHP_URL_HOST);
        if ($realRelay && in_array($frontend, ['localhost', '127.0.0.1', ''], true)) {
            $warnings[] = 'FRONTEND_URL points at localhost: links in emails (password reset, sign-in) will not work for recipients.';
        }

        $fromDomain = Str::after((string) config('mail.from.address'), '@');
        if ($realRelay && (str_ends_with($fromDomain, '.local') || in_array($fromDomain, ['example.com', 'example.org', 'school.test'], true))) {
            $warnings[] = 'MAIL_FROM_ADDRESS is not a real, verified sender address.';
        }

        if ($catcher && (bool) config('reports.email.delivers_externally', false)) {
            $warnings[] = 'REPORT_EMAIL_DELIVERS_EXTERNALLY is true while mail goes to a local catcher.';
        }

        return $warnings;
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || strtolower($value) === 'null' ? null : $value;
    }

    /** "secretcodes@gmail.com" → "s********@gmail.com" */
    private function mask(string $address): string
    {
        $address = trim($address);
        $at = strrpos($address, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($address, 0, 1).str_repeat('*', max($at - 1, 1)).mb_substr($address, $at);
    }
}
