<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Domains\KnowledgeBase\Services\PromptRedactor;
use App\Support\Logging\RedactSecretsProcessor;
use App\Support\Queue\RedactingFailedJobProvider;

/**
 * This deployment's own credentials, by value — the one list every redaction
 * boundary scrubs: AI prompts ({@see PromptRedactor}),
 * log records ({@see RedactSecretsProcessor}) and the
 * exception text stored in `failed_jobs` ({@see RedactingFailedJobProvider}).
 *
 * Values are read at redaction time, not cached, so a key applied late
 * (GeminiCredential sets the provider key from the secret file after boot) or
 * rotated at runtime is still the one being removed.
 *
 * The SMTP *username* is listed although it is not a password: Symfony's
 * authentication failure message embeds it verbatim
 * (`Failed to authenticate on SMTP server with username "…"`), and with the
 * host it identifies the relay account an attacker would target.
 */
final class ConfiguredSecrets
{
    public const CONFIG_KEYS = [
        'app.key',
        'ai.providers.gemini.key',
        'ai.providers.openai.key',
        'ai.providers.anthropic.key',
        'ai.sccit.gemini_inline_key',
        'database.connections.pgsql.password',
        'database.redis.default.password',
        'database.redis.cache.password',
        'mail.mailers.smtp.username',
        'mail.mailers.smtp.password',
        'mail.mailers.smtp.url',
        'broadcasting.connections.reverb.secret',
        'reverb.apps.apps.0.secret',
        'filesystems.disks.s3.key',
        'filesystems.disks.s3.secret',
        'services.postmark.key',
        'services.resend.key',
        'services.ses.secret',
    ];

    /**
     * The configured credential values, longest first so a secret containing
     * another is removed whole. Values shorter than $minLength are skipped: a
     * development password of "secret" would otherwise blank an ordinary word.
     *
     * @return list<string>
     */
    public static function values(int $minLength = 8): array
    {
        $values = [];

        foreach (self::CONFIG_KEYS as $key) {
            $value = config($key);

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if (mb_strlen($value) < $minLength || in_array(strtolower($value), ['null', 'false', 'true'], true)) {
                continue;
            }

            $values[] = $value;

            // APP_KEY is configured as "base64:<key>"; the bare key is the secret too.
            if (str_starts_with($value, 'base64:')) {
                $values[] = substr($value, 7);
            }
        }

        $values = array_values(array_unique($values));
        usort($values, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $values;
    }

    /** Replace every configured secret in $text with $replacement. */
    public static function redact(string $text, string $replacement = '[redacted]', int $minLength = 8): string
    {
        foreach (self::values($minLength) as $secret) {
            $text = str_replace($secret, $replacement, $text);
        }

        return $text;
    }
}
