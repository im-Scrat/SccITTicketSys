<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Domains\KnowledgeBase\Services\AiSettings;
use App\Enums\AiModality;
use App\Models\AiModel;
use App\Models\AiSystemSetting;
use App\Models\SystemSetting;

/**
 * WP-H — AiSettings is the one place model selection is resolved from the
 * `ai_models` / `ai_system_settings` tables. These tests exist to prove a
 * later caller can never end up with a hard-coded model: every fact this
 * class returns must trace back to a row a test seeded, never a literal
 * baked into the service.
 */
it('resolves the active and embedding models from the configured settings row', function (): void {
    $chat = AiModel::factory()->create([
        'provider' => 'gemini',
        'model_identifier' => 'gemini-1.5-flash',
        'modality' => AiModality::Text->value,
    ]);
    $embedding = AiModel::factory()->embedding()->create([
        'provider' => 'gemini',
        'model_identifier' => 'text-embedding-004',
        'embedding_dimensions' => 768,
    ]);
    AiSystemSetting::factory()->create([
        'active_model_id' => $chat->id,
        'embedding_model_id' => $embedding->id,
    ]);

    $settings = app(AiSettings::class);

    expect($settings->activeModel()->is($chat))->toBeTrue()
        ->and($settings->embeddingModel()->is($embedding))->toBeTrue()
        ->and($settings->providerFor($chat))->toBe('gemini')
        ->and($settings->modelIdentifierFor($chat))->toBe('gemini-1.5-flash');
});

it('throws a clean, safe exception when no settings row exists at all', function (): void {
    expect(fn () => app(AiSettings::class)->activeModel())
        ->toThrow(AiUnavailableException::class, 'AI system settings have not been initialized.');
});

it('throws a clean, safe exception when the settings row names no active model', function (): void {
    AiSystemSetting::factory()->create(['active_model_id' => null]);

    expect(fn () => app(AiSettings::class)->activeModel())
        ->toThrow(AiUnavailableException::class, 'No active AI model is configured');
});

it('throws a clean, safe exception when the settings row names no embedding model', function (): void {
    AiSystemSetting::factory()->create(['embedding_model_id' => null]);

    expect(fn () => app(AiSettings::class)->embeddingModel())
        ->toThrow(AiUnavailableException::class, 'No embedding AI model is configured');
});

it('reports whether the resolved provider has a key configured, without ever exposing the key itself', function (): void {
    config(['ai.providers.gemini.key' => null]);
    expect(app(AiSettings::class)->hasProviderKey('gemini'))->toBeFalse();

    config(['ai.providers.gemini.key' => 'test-key-value']);
    expect(app(AiSettings::class)->hasProviderKey('gemini'))->toBeTrue();
});

it('reads the feature toggles and confidence threshold straight from the settings row', function (): void {
    AiSystemSetting::factory()->create([
        'enable_predictions' => true,
        'enable_learning' => false,
        'auto_generate_articles' => true,
        'confidence_threshold' => 0.8123,
    ]);

    $settings = app(AiSettings::class);

    expect($settings->predictionsEnabled())->toBeTrue()
        ->and($settings->learningEnabled())->toBeFalse()
        ->and($settings->autoGenerateArticlesEnabled())->toBeTrue()
        ->and($settings->confidenceThreshold())->toBe(0.8123);
});

it('reads every toggle as off and the threshold as null when there is no settings row', function (): void {
    $settings = app(AiSettings::class);

    expect($settings->predictionsEnabled())->toBeFalse()
        ->and($settings->learningEnabled())->toBeFalse()
        ->and($settings->autoGenerateArticlesEnabled())->toBeFalse()
        ->and($settings->confidenceThreshold())->toBeNull();
});

/**
 * WP-P — the switch an administrator uses to withdraw the assistant. Absent
 * means *on*: the setting exists to remove the feature, and a database that has
 * never had it seeded must not behave as if someone had.
 */
it('treats the assistant as on unless an administrator has switched it off', function (mixed $stored, bool $expected): void {
    if ($stored !== 'absent') {
        SystemSetting::factory()->create(['group' => 'ai', 'key' => 'ai.assistant_enabled', 'value' => $stored, 'type' => 'boolean']);
    }

    expect(app(AiSettings::class)->assistantEnabled())->toBe($expected);
})->with([
    'no row' => ['absent', true],
    'true' => [true, true],
    'false' => [false, false],
    'the string "false"' => ['false', false],
    'the string "true"' => ['true', true],
    'null value' => [null, true],
]);
