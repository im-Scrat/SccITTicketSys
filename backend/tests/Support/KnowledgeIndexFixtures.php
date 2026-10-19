<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\KnowledgeBase\Jobs\GenerateEmbeddingJob;
use App\Enums\AiModality;
use App\Models\AiKnowledgeArticle;
use App\Models\AiModel;
use App\Models\AiSystemSetting;
use Closure;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

/**
 * Shared set-up for the WP-P (RAG indexing) tests.
 *
 * The embedder is a deterministic stand-in for the provider: a hashed
 * bag-of-words folded into 768 dimensions and L2-normalised. It is not a
 * language model, and does not pretend to be — but texts that share words really
 * are closer than texts that do not, so similarity search can be asserted on
 * *ranking* (the right article comes first) rather than on a magic number, with
 * no network and no key.
 */
final class KnowledgeIndexFixtures
{
    public const DIMENSIONS = 768;

    /** An embedding model + settings row in force, and a placeholder key so the provider looks configured. */
    public static function configure(int $dimensions = self::DIMENSIONS): AiModel
    {
        $model = AiModel::factory()->embedding()->create([
            'provider' => 'gemini',
            'model_identifier' => 'text-embedding-004',
            'modality' => AiModality::Embedding->value,
            'embedding_dimensions' => $dimensions,
        ]);
        AiSystemSetting::factory()->create(['embedding_model_id' => $model->id]);
        config(['ai.providers.gemini.key' => 'test-key']);

        return $model;
    }

    /**
     * Install the deterministic embedder as the provider. Returns the list of
     * every input it was asked to embed, by reference, so a test can assert what
     * was (or was not) sent to the provider.
     *
     * @param  list<string>  $seen
     */
    public static function fakeProvider(array &$seen = [], int $dimensions = self::DIMENSIONS): void
    {
        Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$seen, $dimensions): array {
            $vectors = [];

            foreach ($prompt->inputs as $input) {
                $seen[] = $input;
                $vectors[] = self::embedding((string) $input, $dimensions);
            }

            return $vectors;
        });
    }

    /**
     * A provider that always fails, with the kind of error a real outage raises.
     * A plain runtime exception, not a connection one, so the generator's own
     * sleep-and-retry does not slow the suite; the *job's* retry is what is under test.
     */
    public static function failingProvider(?Closure $onCall = null): void
    {
        Embeddings::fake(function () use ($onCall): never {
            if ($onCall !== null) {
                $onCall();
            }

            throw new \RuntimeException('upstream exploded: secret-token-abc123 in the request body');
        });
    }

    /** @return list<float> */
    public static function embedding(string $text, int $dimensions = self::DIMENSIONS): array
    {
        $vector = array_fill(0, $dimensions, 0.0);

        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            // crc32 is stable across processes and platforms, unlike hash-map order.
            $vector[crc32($word) % $dimensions] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

        if ($norm === 0.0) {
            $vector[0] = 1.0;

            return $vector;
        }

        return array_map(static fn (float $v): float => $v / $norm, $vector);
    }

    /** A published article about one clearly distinct topic. */
    public static function published(string $title, string $problem, string $cause = 'Unknown.', string $solution = 'Follow the standard steps.', array $attributes = []): AiKnowledgeArticle
    {
        return AiKnowledgeArticle::factory()->published()->create([
            'title' => $title,
            'category' => 'Hardware',
            'problem_signature' => $problem,
            'root_cause' => $cause,
            'verified_solution' => $solution,
            ...$attributes,
        ]);
    }

    /** Run the indexing job for an article synchronously, as a worker would. */
    public static function index(AiKnowledgeArticle|int $article): void
    {
        $id = $article instanceof AiKnowledgeArticle ? (int) $article->getKey() : $article;

        app()->call([new GenerateEmbeddingJob($id), 'handle']);
    }
}
