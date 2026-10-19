<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Exceptions;

use RuntimeException;

/**
 * The AI feature is not configured, not this call's problem.
 *
 * Thrown when there is no Gemini key set, no active/embedding model chosen in
 * `ai_system_settings`, or the feature toggle for the calling capability is
 * off. Deliberately distinct from {@see AiProviderException} (a configured
 * call that failed at or after the network boundary): callers — and the
 * humans reading logs — need to tell "nobody has turned this on yet" apart
 * from "Gemini is unreachable right now". Never carries provider output or
 * any request content; every message here is a static, safe string written
 * by this codebase.
 */
class AiUnavailableException extends RuntimeException
{
    public static function noApiKey(): self
    {
        return new self('The AI assistant is not configured (no Gemini API key). An administrator must provide the key as a server secret.');
    }

    public static function noActiveModel(): self
    {
        return new self('No active AI model is configured. An administrator must set one in AI system settings.');
    }

    public static function noEmbeddingModel(): self
    {
        return new self('No embedding AI model is configured. An administrator must set one in AI system settings.');
    }

    /** An administrator switched the assistant off (`system_settings.ai.assistant_enabled`). */
    public static function assistantDisabled(): self
    {
        return new self('The AI assistant has been switched off by an administrator.');
    }

    public static function noSystemSettingsRow(): self
    {
        return new self('AI system settings have not been initialized.');
    }

    public static function embeddingModelMisconfigured(): self
    {
        return new self('The configured embedding AI model has no embedding_dimensions set. An administrator must fix the ai_models row.');
    }
}
