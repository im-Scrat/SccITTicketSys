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
    public static function requestFailed(?Throwable $previous = null): self
    {
        return new self('The AI provider could not complete this request. Please try again shortly.', 0, $previous);
    }

    public static function timedOut(?Throwable $previous = null): self
    {
        return new self('The AI provider took too long to respond.', 0, $previous);
    }

    public static function rateLimited(?Throwable $previous = null): self
    {
        return new self('The AI provider is temporarily rate-limiting this application. Please try again shortly.', 0, $previous);
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
