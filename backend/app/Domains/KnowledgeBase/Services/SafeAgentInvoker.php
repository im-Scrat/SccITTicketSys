<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\DTOs\AiInvocationResult;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Models\AiModel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * The one path every WP-H-and-later feature calls an agent through.
 *
 * Wraps a bare `Promptable::prompt()` call with everything the WP-H mandate
 * requires and the SDK does not provide on its own (confirmed by reading
 * {@see CreatesClient} — no retry/backoff is
 * configured on the HTTP client the Gemini gateway builds):
 *
 * - data-driven model selection via {@see AiSettings} (never a literal model
 *   name in a call site);
 * - a clean {@see AiUnavailableException} instead of a confusing transport
 *   error when no key is configured;
 * - an input-size guard ({@see PromptGuard});
 * - a bounded retry with backoff for transient failures only (connection
 *   errors, HTTP 429/5xx) — never for a 4xx request error or a content-policy
 *   refusal, both of which a retry cannot fix;
 * - malformed/empty-output detection, including the specific case the SDK
 *   itself stays silent on: {@see DecodesStructuredOutput}
 *   returns `[]` for output it could not parse as JSON, rather than raising
 *   anything — treated here as a failure for any agent that declared
 *   {@see HasStructuredOutput};
 * - token/latency logging with no request or response content in it;
 * - raw provider-error suppression: every exception this method can throw is
 *   {@see AiProviderException} or {@see AiUnavailableException}, both of
 *   which only ever carry this codebase's own fixed strings (see their own
 *   docblocks) — the real cause is available to `report()`/the log channel
 *   as `$previous`, never as `getMessage()`.
 */
class SafeAgentInvoker
{
    private const MAX_ATTEMPTS = 3;

    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    public function __construct(
        private readonly AiSettings $settings,
        private readonly PromptRedactor $redactor,
    ) {}

    /**
     * Invoke an agent with a text prompt, resolving the model to use from
     * `ai_system_settings` unless the caller supplies one explicitly (tests,
     * or a feature that intentionally targets a non-default model).
     *
     * @param  list<string>  $names  personal names known to appear in the prompt (a reporter,
     *                               a user), removed along with every email address and
     *                               phone number — SRS FR-AI-030
     *
     * @throws AiUnavailableException
     * @throws AiProviderException
     */
    public function invoke(Agent $agent, string $prompt, ?AiModel $model = null, array $names = []): AiInvocationResult
    {
        // The data-minimisation boundary (FR-AI-030): whatever a caller
        // assembled, nothing personal leaves for the provider. Applied here, at
        // the one path every agent call takes, rather than trusted to each
        // prompt builder — so a builder added later cannot forget it.
        $prompt = $this->redactor->redact($prompt, $names);

        PromptGuard::assertWithinLimit($prompt);

        $model ??= $this->settings->activeModel();
        $provider = $this->settings->providerFor($model);

        if (! $this->settings->hasProviderKey($provider)) {
            throw AiUnavailableException::noApiKey();
        }

        $identifier = $this->settings->modelIdentifierFor($model);
        $timeout = (int) config('ai.sccit.agent_timeout_seconds', 30);

        $startedAt = microtime(true);
        $response = $this->promptWithRetry($agent, $prompt, $provider, $identifier, $timeout);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $structured = $this->extractStructuredOutput($agent, $response);

        Log::info('ai.invocation', [
            'provider' => $provider,
            'model' => $identifier,
            'agent' => $agent::class,
            'prompt_tokens' => $response->usage->inputTokens,
            'completion_tokens' => $response->usage->outputTokens,
            'latency_ms' => $latencyMs,
            // Deliberately no prompt text, no response text, no structured payload.
        ]);

        return new AiInvocationResult(
            text: $response->text,
            structured: $structured,
            provider: $provider,
            model: $identifier,
            promptTokens: $response->usage->inputTokens,
            completionTokens: $response->usage->outputTokens,
            latencyMs: $latencyMs,
        );
    }

    /**
     * @throws AiProviderException
     */
    private function promptWithRetry(Agent $agent, string $prompt, string $provider, string $model, int $timeout): AgentResponse
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $isLastAttempt = $attempt === self::MAX_ATTEMPTS;

            try {
                return $agent->prompt($prompt, provider: $provider, model: $model, timeout: $timeout);
            } catch (ConnectionException $e) {
                // Network-level timeout/DNS/refused-connection — always transient.
                if ($isLastAttempt) {
                    $this->logFailure($e, $agent, $attempt);

                    throw AiProviderException::timedOut($e);
                }

                $this->logFailure($e, $agent, $attempt, willRetry: true);
                $this->backoff($attempt);
            } catch (RequestException $e) {
                $status = $e->response->status();
                $retryable = in_array($status, self::RETRYABLE_STATUSES, true);

                if (! $retryable || $isLastAttempt) {
                    $this->logFailure($e, $agent, $attempt);

                    throw $status === 429 ? AiProviderException::rateLimited($e) : AiProviderException::requestFailed($e);
                }

                $this->logFailure($e, $agent, $attempt, willRetry: true);
                $this->backoff($attempt);
            } catch (Throwable $e) {
                // Covers Laravel\Ai\Exceptions\AiException (Gemini-shaped errors: safety
                // block, malformed response body, provider outage reported in-body) and
                // anything else unanticipated. Not retried — a retry cannot fix a
                // content-policy refusal or a code-level bug, and retrying blindly would
                // just multiply an unknown failure by 3.
                $this->logFailure($e, $agent, $attempt);

                throw AiProviderException::requestFailed($e);
            }
        }

        // Unreachable: every branch above either returns or throws by the final attempt.
        throw AiProviderException::requestFailed();
    }

    /**
     * Exponential backoff: 200ms, 400ms. Short on purpose — this runs inside an
     * HTTP request or queue job, not a background batch.
     */
    private function backoff(int $attempt): void
    {
        usleep(200_000 * (2 ** ($attempt - 1)));
    }

    private function logFailure(Throwable $e, Agent $agent, int $attempt, bool $willRetry = false): void
    {
        // The exception itself may carry raw provider text (AiException's message is
        // built from the Gemini response body) — that is fine here: this is the
        // server-side log, never a response, a persisted record, or test output.
        Log::warning('ai.invocation_failed', [
            'agent' => $agent::class,
            'exception' => $e::class,
            'attempt' => $attempt,
            'will_retry' => $willRetry,
        ]);
        report($e);
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws AiProviderException when the agent expected structured output and none decoded, or the answer is empty
     */
    private function extractStructuredOutput(Agent $agent, AgentResponse $response): ?array
    {
        $expectsStructured = $agent instanceof HasStructuredOutput;

        if ($expectsStructured) {
            if (! $response instanceof StructuredAgentResponse || $response->structured === []) {
                throw AiProviderException::malformedOutput();
            }

            return $response->structured;
        }

        if (trim($response->text) === '' && $response->toolCalls->isEmpty()) {
            throw AiProviderException::malformedOutput();
        }

        return null;
    }
}
