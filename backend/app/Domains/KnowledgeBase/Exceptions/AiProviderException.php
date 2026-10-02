<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Exceptions;

use App\Domains\KnowledgeBase\Services\EmbeddingGenerator;
use App\Domains\KnowledgeBase\Services\SafeAgentInvoker;
use RuntimeException;
use Throwable;

/**
 * A configured, attempted call to the AI provider did not produce a usable
 * answer — raised by {@see SafeAgentInvoker}
 * and {@see EmbeddingGenerator}.
 *
 * WP-H's "raw provider-error suppression" requirement, enforced structurally:
 * every named constructor below takes the real cause only as `$previous` (for
 * `report()`/the log channel to unwrap — never for `getMessage()`), and every
 * `getMessage()` is one of the fixed, safe strings on this class. A Gemini
 * error body can legitimately contain request fragments — the exact thing
 * that must never reach an HTTP response, a database column, or test output.
 * Nothing upstream of this exception is trusted with provider detail; nothing
 * downstream of it can leak provider detail, because there is none to leak.
 */
class AiProviderException extends RuntimeException
{
    /**
     * Whether trying the same call again could plausibly succeed. Set only by the
     * named constructors for a failure that is weather (a timeout, a rate limit,
     * a 5xx) rather than a fact about the model or the configuration — WP-P's
     * queued indexing job retries the former and gives up on the latter, and
     * must not have to infer which from the message text.
     */
    private bool $transient = false;

    /** Phase 2.9 — set with {@see $transient} for "no capacity" failures only. */
    private bool $capacity = false;

    public function isTransient(): bool
    {
        return $this->transient;
    }

    /**
     * Phase 2.9 — the narrower case of a transient failure: there was simply no
     * capacity (this application's governor was full, or the provider said
     * 429). An interactive caller answers "busy, try again shortly" for these,
     * and "unavailable" for every other failure.
     */
    public function isCapacityLimit(): bool
    {
        return $this->capacity;
    }

    private static function capacityLimit(string $message, ?Throwable $previous): self
    {
        $exception = self::transient($message, $previous);
        $exception->capacity = true;

        return $exception;
    }

    private static function transient(string $message, ?Throwable $previous): self
    {
        $exception = new self($message, 0, $previous);
        $exception->transient = true;

        return $exception;
    }

    public static function requestFailed(?Throwable $previous = null): self
    {
        return self::transient('The AI provider could not complete this request. Please try again shortly.', $previous);
    }

    public static function timedOut(?Throwable $previous = null): self
    {
        return self::transient('The AI provider took too long to respond.', $previous);
    }

    public static function rateLimited(?Throwable $previous = null): self
    {
        return self::capacityLimit('The AI provider is temporarily rate-limiting this application. Please try again shortly.', $previous);
    }

    /**
     * Phase 2.9 — this application's own Gemini governor had no capacity: every
     * concurrent slot is in use, or the per-minute budget is spent. Nothing was
     * sent. Transient by definition: a queued job releases itself and tries
     * again later; an interactive caller says "busy, try shortly".
     */
    public static function busy(): self
    {
        return self::capacityLimit('The AI service is busy right now. Please try again shortly.', null);
    }

    public static function malformedOutput(): self
    {
        return new self('The AI provider returned an empty or malformed response.');
    }

    public static function embeddingDimensionMismatch(int $expected, int $actual): self
    {
        // Dimension counts are configuration facts, not provider output — safe to state exactly (OD-4).
        return new self("The AI provider returned a {$actual}-dimension embedding; this application requires {$expected}.");
    }

    public static function inputTooLarge(int $limit): self
    {
        return new self("The input is too large to send to the AI provider (limit: {$limit} characters).");
    }
}
