<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Agents\ConnectionCheckAgent;
use App\Domains\KnowledgeBase\Services\GeminiCredential;
use App\Enums\AiModality;
use App\Models\AiModel;
use App\Models\AiSystemSetting;
use Laravel\Ai\Embeddings;

/**
 * `ai:check` — the operator's real-key verification command. Here it runs
 * against the SDK's fake gateway, which proves the command's own behaviour
 * (what it reports, what it refuses to print) and nothing about Google.
 */
beforeEach(function () {
    $this->secretFile = tempnam(sys_get_temp_dir(), 'gemini-secret-');
    config([
        'ai.sccit.gemini_key_file' => $this->secretFile,
        'ai.sccit.gemini_inline_key' => null,
        'ai.providers.gemini.key' => null,
    ]);
});

afterEach(fn () => @unlink($this->secretFile));

function configureModels(): void
{
    $chat = AiModel::factory()->create(['provider' => 'gemini', 'model_identifier' => 'test-chat-model', 'modality' => AiModality::Text->value]);
    $embedding = AiModel::factory()->embedding()->create(['provider' => 'gemini', 'model_identifier' => 'test-embed-model', 'modality' => AiModality::Embedding->value, 'embedding_dimensions' => 768]);
    AiSystemSetting::factory()->create(['active_model_id' => $chat->id, 'embedding_model_id' => $embedding->id]);
}

it('fails with a clear instruction when no key is configured, and calls nothing', function () {
    configureModels();
    ConnectionCheckAgent::fake();

    $this->artisan('ai:check')
        ->expectsOutputToContain('No Gemini API key is configured')
        ->assertFailed();

    ConnectionCheckAgent::assertNotPrompted(fn () => true);
});

it('reports the secret file state without printing the key', function () {
    file_put_contents($this->secretFile, 'secret-value-under-test');
    GeminiCredential::apply();
    configureModels();
    ConnectionCheckAgent::fake(['ok']);
    Embeddings::fake();

    $this->artisan('ai:check')
        ->expectsOutputToContain('exists:         yes')
        ->expectsOutputToContain('Key source:       file')
        ->expectsOutputToContain('Chat OK')
        ->expectsOutputToContain('Embedding OK  (768 dimensions)')
        ->doesntExpectOutputToContain('secret-value-under-test')
        ->assertSuccessful();
});

it('exits non-zero and says which half failed', function () {
    file_put_contents($this->secretFile, 'secret-value-under-test');
    GeminiCredential::apply();
    configureModels();
    ConnectionCheckAgent::fake(['ok']);
    // A 3-wide vector where 768 is required: the embedding half fails, the chat half still passes.
    Embeddings::fake([[[0.1, 0.2, 0.3]]]);

    $this->artisan('ai:check')
        ->expectsOutputToContain('Chat OK')
        ->expectsOutputToContain('Embedding FAILED')
        ->assertFailed();
});

it('says so plainly when a key exists but no model is selected', function () {
    file_put_contents($this->secretFile, 'secret-value-under-test');
    GeminiCredential::apply();

    $this->artisan('ai:check')
        ->expectsOutputToContain('No model is selected')
        ->assertFailed();
});
