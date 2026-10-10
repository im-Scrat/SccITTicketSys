<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

/**
 * Strips personal data from text **before** it leaves for the provider or is
 * written into the embedding store (SRS FR-AI-030, NFR-SEC-013).
 *
 * The rule is data minimisation, not perfect anonymisation: the model needs to
 * know *what is wrong with the machine*, never *who* reported it. So the
 * patterns below target what a person types into a fault description without
 * thinking — an email address, a phone number — plus the names the caller
 * already knows are in the surrounding record and passes in explicitly.
 *
 * Redaction is applied to the text *after* it is assembled, as a last line of
 * defence, rather than trusting every prompt builder to have left personal
 * fields out. A builder that forgets still cannot leak an address.
 *
 * It is deliberately conservative about what it removes: numbers that look like
 * asset tags, ticket references or IP addresses are technical data the model
 * genuinely needs, so the phone pattern requires the shape of a real phone
 * number rather than "any long digit run".
 *
 * ── Credentials (pre-commit fix pass, Phase 2.9) ───────────────────────────
 *
 * Prompt builders never read a password, hash, token or key — the assistant's
 * context is an explicit allowlist ({@see AssistantContext}). But a user can
 * *type* one: "my password is …", a pasted `Authorization: Bearer …`, an API
 * key copied from a settings screen, a cookie value from DevTools. Those reach
 * the prompt as the question and then again as conversation history, so they
 * are removed here, at the one boundary every agent call passes through
 * ({@see SafeAgentInvoker}). Two layers:
 *
 *  1. **Shapes** — a credential announced by a label (`password: x`), bearer
 *     tokens, JWTs and Laravel-encrypted payloads (`eyJ…`), Sanctum `id|token`,
 *     Google API keys (`AIza…`), `base64:` app keys, bcrypt/argon hashes, and
 *     any unbroken 40+ character run mixing letters and digits. UUIDs (36 with
 *     hyphens), ticket references and asset tags fall well short of that.
 *  2. **This deployment's own secrets, by value** — whatever the shape, the
 *     configured app key, AI provider keys and infrastructure passwords are
 *     never sent, even if someone pastes one verbatim.
 */
class PromptRedactor
{
    public const EMAIL = '[email]';

    public const PHONE = '[phone]';

    public const NAME = '[person]';

    public const SECRET = '[secret]';

    /**
     * Configuration entries whose values are credentials of this deployment.
     * Read at redaction time, so a key rotated at runtime (GeminiCredential
     * applies the secret file late) is still the one being scrubbed.
     */
    private const SECRET_CONFIG_KEYS = [
        'app.key',
        'ai.providers.gemini.key',
        'ai.providers.openai.key',
        'ai.providers.anthropic.key',
        'ai.sccit.gemini_inline_key',
        'database.connections.pgsql.password',
        'database.redis.default.password',
        'database.redis.cache.password',
        'mail.mailers.smtp.password',
        'broadcasting.connections.reverb.secret',
        'reverb.apps.apps.0.secret',
        'services.postmark.token',
        'services.resend.key',
        'services.ses.secret',
    ];

    /**
     * Credential shapes, applied in order. The labelled form runs first so the
     * label survives ("password: [secret]") and the model still understands
     * what the user was talking about.
     *
     * @var array<string, string>
     */
    private const SECRET_PATTERNS = [
        // "password: hunter2", "my password is hunter2", "pwd=…". Only password
        // words take the "is" form — "the token is expired" or "a pin is bent"
        // is a fault description, not a credential.
        '/\b((?:pass(?:word|wd|code|phrase)|pwd)\s*(?:is\s+|=|:)\s*)("[^"]+"|\'[^\']+\'|\S+)/iu' => '$1'.self::SECRET,
        // "api key: …", "token=…", "session_id: …", "cookie=…", "secret: …"
        '/\b((?:secret|api[ _\-]?key|access[ _\-]?key|token|session(?:[ _\-]?id)?|cookie|otp|pin)\s*[=:]\s*)("[^"]+"|\'[^\']+\'|\S+)/iu' => '$1'.self::SECRET,
        // Authorization headers
        '/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]{8,}=*/i' => '$1 '.self::SECRET,
        // JWTs and Laravel-encrypted cookies/payloads (base64 JSON starts "eyJ")
        '/\beyJ[A-Za-z0-9_\-+\/=]{16,}(?:\.[A-Za-z0-9_\-+\/=]+){0,2}/' => self::SECRET,
        // Sanctum personal access tokens: "<id>|<40 chars>"
        '/\b\d+\|[A-Za-z0-9]{32,}\b/' => self::SECRET,
        // Google API keys
        '/\bAIza[0-9A-Za-z\-_]{30,}/' => self::SECRET,
        // Laravel APP_KEY
        '/\bbase64:[A-Za-z0-9+\/]{20,}={0,2}/' => self::SECRET,
        // bcrypt and argon2 password hashes
        '/\$2[abxy]\$\d{2}\$[.\/A-Za-z0-9]{53}/' => self::SECRET,
        '/\$argon2i?d?\$\S+/' => self::SECRET,
        // Any long unbroken run mixing letters and digits: keys, hashes, tokens.
        // Hyphens and slashes break a run, so URL paths and hyphenated slugs
        // survive; the shaped patterns above already cover keys that use them.
        '/(?<![A-Za-z0-9_+=])(?=[A-Za-z0-9_+=]*\d)(?=[A-Za-z0-9_+=]*[A-Za-z])[A-Za-z0-9_+=]{40,}/' => self::SECRET,
    ];

    /**
     * @param  list<string>  $names  names known to appear in the record (reporter, technician, …)
     */
    public function redact(string $text, array $names = []): string
    {
        // Secrets before everything else: an email-shaped or name-bearing
        // fragment inside a token must go with the token, not leave half of it.
        $text = $this->redactSecrets($text);

        // Emails first: a name inside an address would otherwise be replaced
        // piecemeal and leave a half-redacted fragment behind.
        $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', self::EMAIL, $text);

        // International (+63 917 123 4567) and local (0917-123-4567,
        // (02) 8123 4567) shapes. Requires 9+ digits overall and a separator
        // pattern or a leading +, so a bare serial number survives. A number
        // glued to a preceding letter-dash (TKT-2026-000123) is a reference,
        // not a phone, so the match may not start after a hyphen.
        $text = (string) preg_replace_callback(
            '/(?<![\w.\-])(?:(?:\+\d{1,3}[\s.\-]?)?(?:\(\d{1,4}\)[\s.\-]?)?\d{2,4}[\s.\-]\d{3,4}[\s.\-]?\d{3,4}|\(\d{1,4}\)[\s.\-]?\d{3,4}[\s.\-]\d{3,4}|\+?\d{10,13})(?!\w|[.\-]\d)/',
            static fn (array $match): string => strlen((string) preg_replace('/\D/', '', $match[0])) >= 9
                ? self::PHONE
                : $match[0],
            $text,
        );

        foreach ($this->nameTokens($names) as $token) {
            $text = (string) preg_replace('/\b'.preg_quote($token, '/').'\b/iu', self::NAME, $text);
        }

        return $text;
    }

    /** Remove credentials — this deployment's own by value, then any by shape. */
    public function redactSecrets(string $text): string
    {
        foreach ($this->configuredSecrets() as $secret) {
            $text = str_replace($secret, self::SECRET, $text);
        }

        foreach (self::SECRET_PATTERNS as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * The configured credential values, longest first so one secret that
     * contains another is removed whole. Short values are skipped: a dev
     * database password of "secret" would otherwise blank an ordinary word.
     *
     * @return list<string>
     */
    private function configuredSecrets(): array
    {
        $values = [];

        foreach (self::SECRET_CONFIG_KEYS as $key) {
            $value = config($key);

            if (is_string($value) && mb_strlen(trim($value)) >= 12) {
                $values[] = trim($value);

                // APP_KEY is configured as "base64:<key>"; the bare key is the secret too.
                if (str_starts_with($value, 'base64:')) {
                    $values[] = substr(trim($value), 7);
                }
            }
        }

        $values = array_values(array_unique($values));
        usort($values, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $values;
    }

    /**
     * Words that identify nobody even when they are someone's registered name.
     * Demo and service accounts are routinely named after their role ("System
     * Administrator", "technician"), and removing those words everywhere turns
     * "open tickets per technician" into "open tickets per [person]". A role or
     * generic account word carries no personal information, so it is left alone
     * as a *part*; the whole name ("System Administrator") is still removed.
     */
    private const NAME_PART_STOPLIST = [
        'system', 'administrator', 'admin', 'technician', 'teacher', 'staff', 'user',
        'account', 'demo', 'sample', 'test', 'default', 'support', 'helpdesk',
    ];

    /**
     * Whole names and their parts, longest first so "Maria Santos" is removed
     * as a unit before "Maria" alone is considered. Parts shorter than three
     * characters are skipped — replacing "Jo" or "Li" everywhere would shred
     * ordinary words — and so are role/generic words (see the stoplist).
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function nameTokens(array $names): array
    {
        $tokens = [];

        foreach ($names as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $tokens[] = $name;

            foreach (preg_split('/\s+/', $name) ?: [] as $part) {
                if (mb_strlen($part) >= 3 && ! in_array(mb_strtolower($part), self::NAME_PART_STOPLIST, true)) {
                    $tokens[] = $part;
                }
            }
        }

        $tokens = array_values(array_unique($tokens));
        usort($tokens, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $tokens;
    }
}
