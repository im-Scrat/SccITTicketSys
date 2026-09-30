<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Domains\KnowledgeBase\Services\EmbeddingGenerator;
use App\Enums\AiModality;
use App\Models\AiModel;
use App\Models\AiSystemSetting;
use Laravel\Ai\Embeddings;

/**
 * WP-H / OD-4 — this application's embedding column is fixed at
 * `vector(768)`. These tests prove {@see EmbeddingGenerator} actually
 * enforces that (not merely documents it) before a vector could ever reach
 * the `ai_embeddings` table.
 */
function seedGeminiEmbeddingModel(int $dimensions = 768): AiModel
{
    $model = AiModel::factory()->embedding()->create([
        'provider' => 'gemini',
        'model_identifier' => 'text-embedding-004',
        'modality' => AiModality::Embedding->value,
        'embedding_dimensions' => $dimensions,
    ]);
    AiSystemSetting::factory()->create(['embedding_model_id' => $model->id]);

    return $model;
}

it('generates a 768-dimension vector, matching the configured embedding model', function (): void {
    seedGeminiEmbeddingModel(768);
    config(['ai.providers.gemini.key' => 'test-key']);
    Embeddings::fake(); // auto-generates a fake vector matching whatever dimension count is requested

    $vector = app(EmbeddingGenerator::class)->embed('The projector in Lab 2 will not power on.');

    expect($vector)->toHaveCount(768)
        ->and($vector[0])->toBeFloat();
});

it('rejects a vector the provider returned in the wrong dimensionality, before it could be persisted', function (): void {
    seedGeminiEmbeddingModel(768);
    config(['ai.providers.gemini.key' => 'test-key']);
    // A provider bug / model mismatch: 3 dimensions instead of the configured 768.
    Embeddings::fake([[[0.1, 0.2, 0.3]]]);

    expect(fn () => app(EmbeddingGenerator::class)->embed('text'))
        ->toThrow(AiProviderException::class, 'requires 768');
});

it('refuses to call the provider at all when the embedding model has no dimension count configured', function (): void {
    $model = AiModel::factory()->embedding()->create([
        'provider' => 'gemini',
        'embedding_dimensions' => null,
    ]);
    AiSystemSetting::factory()->create(['embedding_model_id' => $model->id]);
    config(['ai.providers.gemini.key' => 'test-key']);
    Embeddings::fake(['should never be called']);

    expect(fn () => app(EmbeddingGenerator::class)->embed('text'))
        ->toThrow(AiUnavailableException::class, 'embedding_dimensions');

    Embeddings::assertNothingGenerated();
});

it('never reaches the provider when no Gemini key is configured', function (): void {
    seedGeminiEmbeddingModel();
    config(['ai.providers.gemini.key' => null]);
    Embeddings::fake(['should never be returned']);

    expect(fn () => app(EmbeddingGenerator::class)->embed('text'))
        ->toThrow(AiUnavailableException::class, 'not configured');

    Embeddings::assertNothingGenerated();
});

it('refuses an oversized input before ever calling the provider', function (): void {
    seedGeminiEmbeddingModel();
    config(['ai.providers.gemini.key' => 'test-key', 'ai.sccit.max_input_chars' => 10]);
    Embeddings::fake();

    expect(fn () => app(EmbeddingGenerator::class)->embed(str_repeat('x', 11)))
        ->toThrow(AiProviderException::class, 'too large');

    Embeddings::assertNothingGenerated();
});

it('removes emails and phone numbers from the text sent to be embedded (FR-AI-030)', function (): void {
    seedGeminiEmbeddingModel(768);
    config(['ai.providers.gemini.key' => 'test-key']);

    $seen = [];
    Embeddings::fake(function ($prompt) use (&$seen): array {
        foreach ($prompt->inputs as $input) {
            $seen[] = (string) $input;
        }

        return [array_fill(0, 768, 0.01)];
    });

    app(EmbeddingGenerator::class)->embed('Email help@school.test or call 0917 123 4567 about the projector.');

    expect($seen)->toHaveCount(1)
        ->and($seen[0])->toContain('projector')
        ->and($seen[0])->not->toContain('help@school.test')
        ->and($seen[0])->not->toContain('0917')
        ->and($seen[0])->toContain('[email]')->toContain('[phone]');
});
