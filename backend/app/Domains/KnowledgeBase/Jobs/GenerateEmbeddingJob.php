<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Jobs;

use App\Domains\KnowledgeBase\DTOs\KnowledgeChunk;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Domains\KnowledgeBase\Services\AiSettings;
use App\Domains\KnowledgeBase\Services\EmbeddingGenerator;
use App\Domains\KnowledgeBase\Services\KnowledgeChunker;
use App\Domains\KnowledgeBase\Services\KnowledgeIndexer;
use App\Domains\KnowledgeBase\Services\VectorLiteral;
use App\Enums\EmbeddingStatus;
use App\Models\AiEmbeddingSource;
use App\Models\AiKnowledgeArticle;
use App\Models\AiModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * **Embed one published knowledge article into the vector index** (WP-P;
 * SRS FR-AI-006, FR-AI-021/022).
 *
 * Asynchronous by construction — no request ever waits on a provider — and safe
 * to run more than once for the same article: the outcome depends on the
 * article's content hash, not on how many times this ran.
 *
 * ── What one run does ──────────────────────────────────────────────────────
 *
 *  1. Resolve the article. Gone → purge its vectors. No longer published →
 *     retire it. (An unpublish that races the job wins: nothing draft is ever
 *     indexed.)
 *  2. Skip when the index already matches the content hash (idempotent).
 *  3. Mark the source `processing`, chunk the article, embed each chunk through
 *     {@see EmbeddingGenerator} (which retries transient provider errors and
 *     validates each vector's width), and validate every vector *again* at the
 *     storage boundary.
 *  4. **Re-read the article before writing.** If it was edited or withdrawn
 *     while the provider was being called, the vectors just produced describe a
 *     version that no longer exists: they are discarded, the source is marked
 *     `stale`, and a fresh run is queued. Writing them would leave an index that
 *     claims `indexed` for content it does not contain.
 *  5. Replace the article's chunks and mark it `indexed` in **one transaction**,
 *     so a failure part-way never leaves half an article in the index.
 *
 * ── Failure, precisely ─────────────────────────────────────────────────────
 *
 * **A provider failure retries.** {@see AiProviderException} is transient by
 * nature (timeout, rate limit, a 5xx): the source goes back to `pending` with a
 * safe reason and the exception propagates so the queue backs off and tries
 * again. After the last attempt {@see failed()} marks it `failed`.
 *
 * **A configuration problem does not retry.** No key, or an embedding model of
 * the wrong width, will not fix itself between attempts, so it is recorded as
 * `failed` at once and the job gives up — burning three attempts and a backoff
 * on something only a person can fix would just delay the message.
 *
 * `last_error` only ever holds one of the codebase's own static messages. A
 * provider error body can contain request fragments, and this column is read by
 * people; {@see AiProviderException} exists to make sure none reaches it.
 *
 * **Unique until processing, per article.** Several edits in quick succession
 * need one run, not several; once a run has started, a further edit queues
 * another, so nothing that happened mid-run is missed.
 */
class GenerateEmbeddingJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Attempts before {@see failed()} gives up. The generator retries inside each one. */
    public int $tries = 3;

    /** Seconds allowed for a whole article — several chunks, each with its own retries. */
    public int $timeout = 180;

    /** @param int $articleId the `ai_knowledge_articles.id`; an id, not a model, so a deleted article is handled rather than fatal */
    public function __construct(public readonly int $articleId) {}

    /**
     * Back off between attempts: long enough for a rate limit to clear, short
     * enough that an article edited today is searchable today.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function uniqueId(): string
    {
        return (string) $this->articleId;
    }

    public function handle(
        EmbeddingGenerator $generator,
        KnowledgeIndexer $indexer,
        KnowledgeChunker $chunker,
        AiSettings $settings,
    ): void {
        $article = AiKnowledgeArticle::withTrashed()->find($this->articleId);

        if ($article === null) {
            $indexer->purge($this->articleId);

            return;
        }

        if (! $indexer->eligible($article)) {
            $indexer->retire($article);

            return;
        }

        // An administrator switched the assistant off. Leave every state exactly
        // as it is: nothing is lost, and it resumes when they switch it back on.
        if (! $settings->assistantEnabled()) {
            return;
        }

        $model = $indexer->model();

        if ($model === null) {
            // Nothing to record a state against (the source row is keyed on a
            // model). Not an error: the sweep will pick the article up.
            return;
        }

        $hash = $chunker->hash($article);
        $source = $this->claim($indexer, $article, $model, $hash);

        if ($source === null) {
            return; // already indexed for exactly this content
        }

        try {
            $this->assertStorable($model);
            $vectors = $this->embed($article, $chunker, $generator);
        } catch (AiUnavailableException|AiProviderException $exception) {
            $this->settle($source, $exception);

            return;
        } catch (Throwable $exception) {
            $this->reset($source, $exception);

            throw $exception;
        }

        $current = AiKnowledgeArticle::withTrashed()->find($this->articleId);

        if ($current === null || ! $indexer->eligible($current) || $chunker->hash($current) !== $hash) {
            $this->discard($source, $current, $indexer);

            return;
        }

        $this->store($source, $model, $hash, $vectors);
    }

    /**
     * Take the source row for this article and mark it `processing` — or return
     * null if the index already matches this content and there is nothing to do.
     */
    private function claim(KnowledgeIndexer $indexer, AiKnowledgeArticle $article, AiModel $model, string $hash): ?AiEmbeddingSource
    {
        return DB::transaction(function () use ($indexer, $article, $model, $hash): ?AiEmbeddingSource {
            $source = $indexer->lockSource((int) $article->getKey(), $model);

            if ($source->embedding_status === EmbeddingStatus::Indexed && $source->content_hash === $hash) {
                return null;
            }

            $source->forceFill(['embedding_status' => EmbeddingStatus::Processing->value, 'last_error' => null])->save();

            return $source;
        });
    }

    /**
     * The column is `vector(768)`. A model configured to any other width could
     * never be stored, so refuse it before a single provider call is spent.
     *
     * @throws AiProviderException
     */
    private function assertStorable(AiModel $model): void
    {
        if ($model->embedding_dimensions !== KnowledgeIndexer::COLUMN_DIMENSIONS) {
            throw AiProviderException::embeddingDimensionMismatch(
                KnowledgeIndexer::COLUMN_DIMENSIONS,
                (int) $model->embedding_dimensions,
            );
        }
    }

    /**
     * @return list<array{chunk: KnowledgeChunk, literal: string}>
     *
     * @throws AiProviderException
     * @throws AiUnavailableException
     */
    private function embed(AiKnowledgeArticle $article, KnowledgeChunker $chunker, EmbeddingGenerator $generator): array
    {
        $vectors = [];

        foreach ($chunker->chunk($article) as $chunk) {
            $vectors[] = [
                'chunk' => $chunk,
                // Validated here as well as in the generator: this is the last
                // point before storage, and the only one that also rejects a
                // non-finite value.
                'literal' => VectorLiteral::from($generator->embed($chunk->text), KnowledgeIndexer::COLUMN_DIMENSIONS),
            ];
        }

        return $vectors;
    }

    /**
     * Replace the article's chunks and mark it indexed, atomically.
     *
     * @param  list<array{chunk: KnowledgeChunk, literal: string}>  $vectors
     */
    private function store(AiEmbeddingSource $source, AiModel $model, string $hash, array $vectors): void
    {
        DB::transaction(function () use ($source, $model, $hash, $vectors): void {
            DB::table('ai_embeddings')
                ->where('embeddable_type', KnowledgeIndexer::sourceType()->value)
                ->where('embeddable_id', $this->articleId)
                ->where('ai_model_id', $model->getKey())
                ->delete();

            $now = now();

            foreach ($vectors as $row) {
                DB::table('ai_embeddings')->insert([
                    'embeddable_type' => KnowledgeIndexer::sourceType()->value,
                    'embeddable_id' => $this->articleId,
                    'ai_model_id' => $model->getKey(),
                    'chunk_index' => $row['chunk']->index,
                    'content' => $row['chunk']->text,
                    'content_hash' => $row['chunk']->hash,
                    'token_count' => $row['chunk']->tokenEstimate,
                    'metadata' => json_encode(['section' => $row['chunk']->section]),
                    'embedding' => $row['literal'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $source->forceFill([
                'embedding_status' => EmbeddingStatus::Indexed->value,
                'chunk_count' => count($vectors),
                'content_hash' => $hash,
                'indexed_at' => $now,
                'last_error' => null,
            ])->save();
        });
    }

    /**
     * The article changed (or was withdrawn) while the provider was being called:
     * throw the result away rather than store vectors for a version that no
     * longer exists, and go round again if there is still something to index.
     */
    private function discard(AiEmbeddingSource $source, ?AiKnowledgeArticle $current, KnowledgeIndexer $indexer): void
    {
        if ($current === null) {
            $indexer->purge($this->articleId);

            return;
        }

        if (! $indexer->eligible($current)) {
            $indexer->retire($current);

            return;
        }

        $source->forceFill(['embedding_status' => EmbeddingStatus::Stale->value, 'last_error' => null])->save();

        $indexer->dispatch($current);
    }

    /**
     * A failure that will not change on retry, or one that has been retried
     * enough. Provider failures retry (the source returns to `pending` and the
     * exception propagates); configuration failures stop here.
     */
    private function settle(AiEmbeddingSource $source, AiUnavailableException|AiProviderException $exception): void
    {
        if ($exception instanceof AiProviderException && $this->attempts() < $this->tries && $exception->isTransient()) {
            $source->forceFill([
                'embedding_status' => EmbeddingStatus::Pending->value,
                'last_error' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        $source->forceFill([
            'embedding_status' => EmbeddingStatus::Failed->value,
            'last_error' => $exception->getMessage(),
        ])->save();

        $this->fail($exception);
    }

    /** An unexpected failure (a database error, a bug): back to `pending`, and let the queue retry. */
    private function reset(AiEmbeddingSource $source, Throwable $exception): void
    {
        report($exception);

        $source->forceFill([
            'embedding_status' => EmbeddingStatus::Pending->value,
            'last_error' => 'Indexing failed unexpectedly and will be retried.',
        ])->save();
    }

    /**
     * Called by the queue after the last attempt. Whatever the cause, the
     * article must not be left claiming `processing` or `pending` for ever.
     */
    public function failed(Throwable $exception): void
    {
        $safe = $exception instanceof AiUnavailableException || $exception instanceof AiProviderException
            ? $exception->getMessage()
            : 'Indexing failed unexpectedly. See the application log.';

        if (! ($exception instanceof AiUnavailableException || $exception instanceof AiProviderException)) {
            report($exception);
        }

        AiEmbeddingSource::query()
            ->where('source_type', KnowledgeIndexer::sourceType()->value)
            ->where('source_id', $this->articleId)
            ->whereIn('embedding_status', [EmbeddingStatus::Processing->value, EmbeddingStatus::Pending->value])
            ->update(['embedding_status' => EmbeddingStatus::Failed->value, 'last_error' => $safe]);
    }
}
