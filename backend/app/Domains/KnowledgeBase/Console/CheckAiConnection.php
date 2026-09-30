<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Console;

use App\Domains\KnowledgeBase\Agents\ConnectionCheckAgent;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Domains\KnowledgeBase\Services\AiSettings;
use App\Domains\KnowledgeBase\Services\EmbeddingGenerator;
use App\Domains\KnowledgeBase\Services\GeminiCredential;
use App\Domains\KnowledgeBase\Services\SafeAgentInvoker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * `php artisan ai:check` — prove the Gemini integration works **with the real
 * key, on the machine it will run on** (WP-O; SDD DD-74).
 *
 * This is the only honest verification of the provider. The test suite runs
 * against `laravel/ai`'s fake gateway and proves the application's behaviour —
 * persistence, authorization, redaction, degradation — never Google's. A release
 * that has not run this command against a real key has **not** been verified
 * against Gemini, and the release notes say exactly that.
 *
 * It makes one tiny chat call and one tiny embedding call through the same
 * invoker and generator production uses. Output is safe to paste into a support
 * thread: the key is never printed, only whether the secret file is present,
 * readable and non-empty; failures carry this codebase's own static messages,
 * never provider response bodies.
 *
 * `--models` additionally lists the models the key can use, which fixes the most
 * likely misconfiguration — a model identifier Google has since retired — because
 * the registry is data an Administrator can change with no deploy (FR-AI-014/015).
 */
class CheckAiConnection extends Command
{
    protected $signature = 'ai:check {--models : List the models this key can use}';

    protected $description = 'Verify the Gemini integration end to end with the configured key (one chat call, one embedding call)';

    public function handle(AiSettings $settings, SafeAgentInvoker $invoker, EmbeddingGenerator $embedder): int
    {
        $credential = GeminiCredential::status();

        $this->line('Secret file:      '.($credential['path'] ?? '(none configured)'));
        $this->line('  exists:         '.($credential['file_exists'] ? 'yes' : 'no'));
        $this->line('  readable:       '.($credential['file_readable'] ? 'yes' : 'no'));
        $this->line('  empty:          '.($credential['file_empty'] ? 'YES (no key inside)' : 'no'));
        $this->line('Key source:       '.$credential['source']);

        if (! $settings->hasProviderKey('gemini')) {
            $this->newLine();
            $this->error('No Gemini API key is configured. Put the key in the secret file (see docs/OPERATIONS.md) and restart app, queue and scheduler.');

            return self::FAILURE;
        }

        $failed = false;

        try {
            $chat = $settings->activeModel();
            $this->line('Chat model:       '.$chat->model_identifier);
        } catch (AiUnavailableException $exception) {
            $this->line('Chat model:       (none selected — AI is off)');
            $chat = null;
        }

        try {
            $this->line('Embedding model:  '.$settings->embeddingModel()->model_identifier);
            $embeddingSelected = true;
        } catch (AiUnavailableException $exception) {
            $this->line('Embedding model:  (none selected)');
            $embeddingSelected = false;
        }

        if ($this->option('models') && ! $this->listModels()) {
            $failed = true;
        }

        $this->newLine();

        if ($chat !== null) {
            try {
                $reply = $invoker->invoke(new ConnectionCheckAgent, 'ping', $chat);
                $this->info(sprintf('Chat OK       (%s, %d ms, %d tokens in / %d out)', $reply->model, $reply->latencyMs, $reply->promptTokens, $reply->completionTokens));
            } catch (AiUnavailableException|AiProviderException $exception) {
                $this->error('Chat FAILED:   '.$exception->getMessage());
                $failed = true;
            }
        }

        if ($embeddingSelected) {
            try {
                $vector = $embedder->embed('connection check');
                $this->info(sprintf('Embedding OK  (%d dimensions)', count($vector)));
            } catch (AiUnavailableException|AiProviderException $exception) {
                $this->error('Embedding FAILED: '.$exception->getMessage());
                $failed = true;
            }
        }

        if ($chat === null && ! $embeddingSelected) {
            $this->warn('No model is selected, so nothing was called. Choose models in the administrator AI settings, then run this again.');

            return self::FAILURE;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * One authenticated ListModels call. The key travels in a header, never in
     * the URL, and only model names are printed.
     */
    private function listModels(): bool
    {
        $key = GeminiCredential::resolve();
        $base = rtrim((string) config('ai.providers.gemini.url'), '/');

        try {
            $response = Http::withHeaders(['x-goog-api-key' => (string) $key])
                ->timeout(20)
                ->get($base.'/models', ['pageSize' => 100]);
        } catch (Throwable) {
            $this->error('Could not list models: the provider could not be reached.');

            return false;
        }

        if ($response->failed()) {
            $this->error('Could not list models: HTTP '.$response->status().'.');

            return false;
        }

        $this->newLine();
        $this->info('Models available to this key:');

        foreach ((array) $response->json('models', []) as $model) {
            if (is_array($model) && isset($model['name'])) {
                $this->line(sprintf('  %-44s %s', preg_replace('#^models/#', '', (string) $model['name']), implode(', ', (array) ($model['supportedGenerationMethods'] ?? []))));
            }
        }

        return true;
    }
}
