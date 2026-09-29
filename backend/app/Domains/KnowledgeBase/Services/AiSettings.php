<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Models\AiModel;
use App\Models\AiSystemSetting;

/**
 * The single source of truth for "which AI model, and is AI on at all" —
 * every WP-H-and-later caller resolves this here instead of naming a model
 * identifier or a feature toggle itself.
 *
 * Backed by the `ai_models` / `ai_system_settings` tables an earlier phase
 * already built and seeded (see `database/seeders/AiSeeder.php`) — WP-H does
 * not touch that schema, and per its own instruction ("use the existing
 * ai_models and ai_system_settings architecture; do not silently replace it
 * with hard-coded constants") nothing in this class or its callers may
 * hard-code a model name, a provider name, or a dimension count as a
 * fallback. An administrator changes the active model by updating a row;
 * every caller picks it up on its next call, with no deploy and no cache to
 * invalidate — settings are read fresh every time, which a single-row lookup
 * on an already-indexed table can afford.
 */
class AiSettings
{
    /**
     * The one settings row, or null if it has never been seeded/created.
     */
    private function settings(): ?AiSystemSetting
    {
        return AiSystemSetting::query()
            ->with(['activeModel', 'embeddingModel'])
            ->first();
    }

    /**
     * The model to use for text/chat/structured-output calls.
     *
     * @throws AiUnavailableException when nothing is configured
     */
    public function activeModel(): AiModel
    {
        $settings = $this->settings() ?? throw AiUnavailableException::noSystemSettingsRow();

        return $settings->activeModel ?? throw AiUnavailableException::noActiveModel();
    }

    /**
     * The model to use for embedding generation.
     *
     * @throws AiUnavailableException when nothing is configured
     */
    public function embeddingModel(): AiModel
    {
        $settings = $this->settings() ?? throw AiUnavailableException::noSystemSettingsRow();

        return $settings->embeddingModel ?? throw AiUnavailableException::noEmbeddingModel();
    }

    /**
     * The `laravel/ai` provider name (config('ai.providers.*') key) for a resolved model.
     *
     * Reads the model's own `provider` column rather than assuming — a future
     * second provider row (a different Gemini model, or a different vendor
     * entirely) is picked up with no code change here.
     */
    public function providerFor(AiModel $model): string
    {
        return $model->provider;
    }

    /**
     * The exact model identifier to pass to `laravel/ai` (e.g. "gemini-1.5-flash").
     */
    public function modelIdentifierFor(AiModel $model): string
    {
        return $model->model_identifier;
    }

    /**
     * Whether Gemini is configured at all — a key present, independent of
     * whether any row in ai_models/ai_system_settings is set up yet.
     */
    public function hasProviderKey(string $provider): bool
    {
        return filled(config("ai.providers.{$provider}.key"));
    }

    /**
     * Whether predictive-maintenance generation is turned on (OD-2, D13 — Admin-only feature).
     */
    public function predictionsEnabled(): bool
    {
        return $this->settings()?->enable_predictions === true;
    }

    /**
     * Whether the AI learning-from-outcomes loop is turned on.
     */
    public function learningEnabled(): bool
    {
        return $this->settings()?->enable_learning === true;
    }

    /**
     * Whether the system may auto-generate knowledge articles from resolved tickets.
     */
    public function autoGenerateArticlesEnabled(): bool
    {
        return $this->settings()?->auto_generate_articles === true;
    }

    /**
     * The minimum confidence a caller should require before acting on an AI result, if one is set.
     */
    public function confidenceThreshold(): ?float
    {
        $value = $this->settings()?->confidence_threshold;

        return $value !== null ? (float) $value : null;
    }
}
