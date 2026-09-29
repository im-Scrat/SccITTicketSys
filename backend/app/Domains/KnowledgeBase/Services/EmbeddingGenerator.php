<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Throwable;

/**
 * The one path every RAG-facing feature (WP-P and later) generates an
 * embedding vector through.
 *
 * OD-4 fixes this application's embedding column at `vector(768)` /
 * `embedding_dimensions = 768`; this class is where that fact is actually
 * enforced, not assumed. It requests exactly the configured model's
 * dimensionality from the provider and, per the WP-H mandate ("validate
 * generated embedding dimensionality before persistence"), refuses to return
 * a vector of any other length rather than letting a silently-wrong-shaped
 * value reach the `ai_embeddings.embedding` column an `INSERT` would then
 * reject anyway — the check happens here, with a safe message, instead of
 * surfacing as a raw Postgres error somewhere downstream.
 *
 * Same retryable-transient-failure and raw-error-suppression posture as
 * {@see SafeAgentInvoker}; see that class's docblock for the reasoning. Kept
 * as its own small class rather than sharing a generic retry helper with it —
 * the two response shapes (EmbeddingsResponse vs AgentResponse) and success
 * checks (dimension count vs structured-output presence) differ enough that
 * a shared abstraction would cost more indirection than the ~15 duplicated
 * lines it would save.
 */
class EmbeddingGenerator
{
    private const MAX_ATTEMPTS = 3;

    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    public function __construct(private readonly AiSettings $settings) {}

    /**
     * Generate the embedding vector for a single piece of text.
     *
     * @return array<float> exactly `$model->embedding_dimensions` values long
     *
     * @throws AiUnavailableException
     * @throws AiProviderException
     */
    public function embed(string $text): array
    {
        PromptGuard::assertWithinLimit($text);

        $model = $this->settings->embeddingModel();
        $provider = $this->settings->providerFor($model);

        if (! $this->settings->hasProviderKey($provider)) {
            throw AiUnavailableException::noApiKey();
        }

        $dimensions = $model->embedding_dimensions
            ?? throw AiUnavailableException::embeddingModelMisconfigured();

        $identifier = $this->settings->modelIdentifierFor($model);
        $timeout = (int) config('ai.sccit.agent_timeout_seconds', 30);

        $response = $this->generateWithRetry($text, $provider, $identifier, $dimensions, $timeout);

        $vector = $response->first();

        if (count($vector) !== $dimensions) {
            throw AiProviderException::embeddingDimensionMismatch($dimensions, count($vector));
        }

        Log::info('ai.embedding_generated', [
            'provider' => $provider,
            'model' => $identifier,
            'dimensions' => count($vector),
            // Deliberately no source text and no vector values.
        ]);

        return $vector;
    }

    /**
     * @throws AiProviderException
     */
    private function generateWithRetry(string $text, string $provider, string $model, int $dimensions, int $timeout): EmbeddingsResponse
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $isLastAttempt = $attempt === self::MAX_ATTEMPTS;

            try {
                return Embeddings::for([$text])
                    ->dimensions($dimensions)
                    ->timeout($timeout)
                    ->generate(provider: $provider, model: $model);
            } catch (ConnectionException $e) {
                if ($isLastAttempt) {
                    $this->logFailure($e, $attempt);

                    throw AiProviderException::timedOut($e);
                }

                $this->logFailure($e, $attempt, willRetry: true);
                $this->backoff($attempt);
            } catch (RequestException $e) {
                $status = $e->response->status();
                $retryable = in_array($status, self::RETRYABLE_STATUSES, true);

                if (! $retryable || $isLastAttempt) {
                    $this->logFailure($e, $attempt);

                    throw $status === 429 ? AiProviderException::rateLimited($e) : AiProviderException::requestFailed($e);
                }

                $this->logFailure($e, $attempt, willRetry: true);
                $this->backoff($attempt);
            } catch (Throwable $e) {
                $this->logFailure($e, $attempt);

                throw AiProviderException::requestFailed($e);
            }
        }

        // Unreachable: every branch above either returns or throws by the final attempt.
        throw AiProviderException::requestFailed();
    }

    private function backoff(int $attempt): void
    {
        usleep(200_000 * (2 ** ($attempt - 1)));
    }

    private function logFailure(Throwable $e, int $attempt, bool $willRetry = false): void
    {
        Log::warning('ai.embedding_failed', [
            'exception' => $e::class,
            'attempt' => $attempt,
            'will_retry' => $willRetry,
        ]);
        report($e);
    }
}
