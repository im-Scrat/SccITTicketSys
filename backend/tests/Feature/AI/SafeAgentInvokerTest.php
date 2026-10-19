<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\DTOs\AiInvocationResult;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Domains\KnowledgeBase\Services\SafeAgentInvoker;
use App\Enums\AiModality;
use App\Models\AiModel;
use App\Models\AiSystemSetting;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeStructuredTestAgent;
use Tests\Support\FakeTestAgent;

/**
 * WP-H — the one path every agent call goes through. These tests exercise it
 * against `laravel/ai`'s own fake gateway (never a real provider — no test
 * here makes a network call), and specifically prove the security-relevant
 * claims: a missing key never reaches the provider layer at all, a malformed
 * structured response is caught rather than silently returning `[]`, and no
 * exception's message ever carries provider-shaped text.
 */
function seedActiveGeminiModel(): AiModel
{
    $model = AiModel::factory()->create([
        'provider' => 'gemini',
        'model_identifier' => 'gemini-1.5-flash',
        'modality' => AiModality::Text->value,
    ]);
    AiSystemSetting::factory()->create(['active_model_id' => $model->id]);

    return $model;
}

it('resolves the model from AiSettings, prompts the agent, and returns usage and latency', function (): void {
    seedActiveGeminiModel();
    config(['ai.providers.gemini.key' => 'test-key']);
    FakeTestAgent::fake(['A helpful answer.']);

    $result = app(SafeAgentInvoker::class)->invoke(new FakeTestAgent, 'What is wrong with this PC?');

    expect($result)->toBeInstanceOf(AiInvocationResult::class)
        ->and($result->text)->toBe('A helpful answer.')
        ->and($result->structured)->toBeNull()
        ->and($result->provider)->toBe('gemini')
        ->and($result->model)->toBe('gemini-1.5-flash')
        ->and($result->latencyMs)->toBeGreaterThanOrEqual(0);

    FakeTestAgent::assertPromptedTimes(1);
});

it('never reaches the provider when no Gemini key is configured', function (): void {
    seedActiveGeminiModel();
    config(['ai.providers.gemini.key' => null]);
    FakeTestAgent::fake(['should never be returned']);

    expect(fn () => app(SafeAgentInvoker::class)->invoke(new FakeTestAgent, 'prompt'))
        ->toThrow(AiUnavailableException::class, 'not configured');

    FakeTestAgent::assertNeverPrompted();
});

it('refuses an oversized prompt before ever calling the agent', function (): void {
    seedActiveGeminiModel();
    config(['ai.providers.gemini.key' => 'test-key', 'ai.sccit.max_input_chars' => 10]);
    FakeTestAgent::fake(['irrelevant']);

    expect(fn () => app(SafeAgentInvoker::class)->invoke(new FakeTestAgent, str_repeat('x', 11)))
        ->toThrow(AiProviderException::class, 'too large');

    FakeTestAgent::assertNeverPrompted();
});

it('treats an empty text answer as malformed output rather than returning it', function (): void {
    seedActiveGeminiModel();
    config(['ai.providers.gemini.key' => 'test-key']);
    FakeTestAgent::fake(['']);

    expect(fn () => app(SafeAgentInvoker::class)->invoke(new FakeTestAgent, 'prompt'))
        ->toThrow(AiProviderException::class, 'empty or malformed');
});

it('returns the structured payload for an agent that declares a schema', function (): void {
    seedActiveGeminiModel();
    config(['ai.providers.gemini.key' => 'test-key']);
    FakeStructuredTestAgent::fake([['category' => 'hardware']]);

    $result = app(SafeAgentInvoker::class)->invoke(new FakeStructuredTestAgent, 'Classify this ticket.');

    expect($result->structured)->toBe(['category' => 'hardware']);
});

it('treats an empty structured payload as malformed output, the one case the SDK itself stays silent on', function (): void {
    seedActiveGeminiModel();
    config(['ai.providers.gemini.key' => 'test-key']);
    // The SDK's own DecodesStructuredOutput returns [] for anything it could not
    // parse as JSON — silently, by design. This is exactly that shape, and this
    // is the layer that must not let it pass as a successful result.
    FakeStructuredTestAgent::fake([[]]);

    expect(fn () => app(SafeAgentInvoker::class)->invoke(new FakeStructuredTestAgent, 'Classify this ticket.'))
        ->toThrow(AiProviderException::class, 'empty or malformed');
});

it('never exposes the real Gemini API key in an exception message, even on failure', function (): void {
    seedActiveGeminiModel();
    $secret = 'sk-fake-test-secret-should-never-appear-anywhere';
    config(['ai.providers.gemini.key' => $secret]);
    FakeTestAgent::fake(['']);

    try {
        app(SafeAgentInvoker::class)->invoke(new FakeTestAgent, 'prompt');
        $this->fail('Expected AiProviderException was not thrown.');
    } catch (AiProviderException $e) {
        expect($e->getMessage())->not->toContain($secret);
    }
});

it('logs invocation metadata without the prompt or the answer', function (): void {
    seedActiveGeminiModel();
    config(['ai.providers.gemini.key' => 'test-key']);
    FakeTestAgent::fake(['the actual answer text']);
    Log::spy();

    app(SafeAgentInvoker::class)->invoke(new FakeTestAgent, 'the actual prompt text');

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode($context);

            return $message === 'ai.invocation'
                && ! str_contains((string) $encoded, 'the actual answer text')
                && ! str_contains((string) $encoded, 'the actual prompt text')
                && array_key_exists('prompt_tokens', $context)
                && array_key_exists('latency_ms', $context);
        })
        ->once();
});
