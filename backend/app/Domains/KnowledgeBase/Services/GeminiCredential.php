<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use Illuminate\Support\Facades\Log;

/**
 * Where the Gemini API key comes from — the one place that knows (WP-O; SDD DD-74).
 *
 * `laravel/ai` reads its provider key from `config('ai.providers.gemini.key')`,
 * which `config/ai.php` fills from the `GEMINI_API_KEY` environment variable.
 * That is the right shape for a developer's laptop and the wrong one for a
 * production server, where an environment variable is visible to `docker inspect`
 * and to every process in the container. So production supplies the key as a
 * **Docker Compose secret**: a file, mounted read-only at
 * `/run/secrets/gemini_api_key`, whose *path* is the only thing in any
 * configuration (`GEMINI_API_KEY_FILE`).
 *
 * ── Resolution order ───────────────────────────────────────────────────────
 *
 *   1. the secret file, when it exists, is readable and is not empty
 *   2. outside production only: the `GEMINI_API_KEY` environment variable
 *   3. nothing — the provider reports itself unconfigured, every AI feature
 *      degrades to its "unavailable" state, and ticketing carries on (FR-AI-020)
 *
 * In production step 2 is deliberately absent. An inline key there would work,
 * and would quietly defeat the point of having a secret mechanism; ignoring it
 * (with one warning in the log naming the variable, never its value) makes the
 * safe path the only path.
 *
 * ── Why it is applied at runtime, not through `config/ai.php` ──────────────
 *
 * Production runs `config:cache`, which writes every resolved config value into
 * `bootstrap/cache/config.php`. A key read while *building* config would land in
 * that file — a second copy of the secret on disk, inside the container, that
 * nobody thinks to rotate. {@see apply()} runs from the service provider after
 * config has loaded, so the cache only ever holds the null default, and the key
 * exists solely in process memory. It also runs before every queued job, so a
 * rotated secret reaches a long-lived worker without a restart.
 */
class GeminiCredential
{
    /** Logged at most once per process so a busy queue worker does not repeat it. */
    private static bool $warned = false;

    /**
     * Put the resolved key into the `laravel/ai` provider config.
     *
     * Only ever *sets*: when nothing resolves it leaves the config alone rather
     * than writing null over it, so a key a test (or a later provider override)
     * installed deliberately is not clobbered by a per-job re-application. A
     * secret that is *removed* therefore reaches a running worker at its next
     * restart rather than its next job — the safe direction to be stale in.
     */
    public static function apply(): void
    {
        $key = self::resolve();

        if ($key !== null) {
            config(['ai.providers.gemini.key' => $key]);
        }
    }

    /** The key, or null. Never logged, never returned by any HTTP endpoint. */
    public static function resolve(): ?string
    {
        $fromFile = self::fromFile();

        if ($fromFile !== null) {
            return $fromFile;
        }

        if (app()->isProduction()) {
            // `getenv`, not config: the inline key is deliberately absent from
            // production config so it can never reach `config:cache`.
            $leaked = getenv('GEMINI_API_KEY');

            if (is_string($leaked) && trim($leaked) !== '' && ! self::$warned) {
                self::$warned = true;
                Log::warning('GEMINI_API_KEY is set in the environment and is being ignored in production. Provide the key as a Docker secret file (GEMINI_API_KEY_FILE) instead.');
            }

            return null;
        }

        $inline = config('ai.sccit.gemini_inline_key');

        return is_string($inline) && trim($inline) !== '' ? trim($inline) : null;
    }

    /**
     * What is wrong, if anything — for `ai:check` and the admin screen. Reports
     * the state of the file, never any part of its contents.
     *
     * @return array{source: 'file'|'environment'|'none', path: string|null, file_exists: bool, file_readable: bool, file_empty: bool}
     */
    public static function status(): array
    {
        $path = self::path();
        $exists = $path !== null && is_file($path);
        $readable = $exists && is_readable($path);
        $empty = $readable && trim((string) @file_get_contents($path)) === '';

        $key = self::resolve();

        return [
            'source' => $key === null ? 'none' : ($readable && ! $empty ? 'file' : 'environment'),
            'path' => $path,
            'file_exists' => $exists,
            'file_readable' => $readable,
            'file_empty' => $empty,
        ];
    }

    /** The secret file path: `GEMINI_API_KEY_FILE`, or the Compose default. */
    public static function path(): ?string
    {
        $path = config('ai.sccit.gemini_key_file');

        return is_string($path) && $path !== '' ? $path : null;
    }

    public static function forgetWarning(): void
    {
        self::$warned = false;
    }

    private static function fromFile(): ?string
    {
        $path = self::path();

        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $key = trim((string) file_get_contents($path));

        return $key === '' ? null : $key;
    }
}
