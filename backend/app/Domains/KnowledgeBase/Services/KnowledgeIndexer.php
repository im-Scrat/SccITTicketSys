<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Domains\KnowledgeBase\Jobs\GenerateEmbeddingJob;
use App\Enums\EmbeddableSourceType;
use App\Enums\EmbeddingStatus;
use App\Enums\KnowledgeStatus;
use App\Models\AiEmbeddingSource;
use App\Models\AiKnowledgeArticle;
use App\Models\AiModel;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * **Which knowledge articles are in the vector index, and in what state** —
 * the single owner of every transition of `ai_embedding_sources` (WP-P).
 *
 * ── SOURCE A only ──────────────────────────────────────────────────────────
 *
 * The RAG design has two sources. **Source A** — general technical knowledge,
 * the published knowledge articles — is what this indexes. **Source B** — the
 * application's own transactional records (tickets, comments, maintenance
 * records) — is deliberately *not* here: that data already has a visibility
 * service deciding who may see which row, and copying it into one shared vector
 * index would create a second, unscoped path to it that no policy governs. So
 * the only source this class knows is {@see EmbeddableSourceType::KnowledgeArticle};
 * the other cases of that enum exist for a schema written earlier and are never
 * written by this pipeline (a test asserts it).
 *
 * ── Only published articles participate ────────────────────────────────────
 *
 * An article is {@see eligible()} when it is `published` and not soft-deleted.
 * Everything else is *retired*: its chunks are removed, so a draft or archived
 * article has no vectors to retrieve at all — the retriever additionally filters
 * on article status, but the first line of defence is that nothing is there.
 *
 * ── The five states ────────────────────────────────────────────────────────
 *
 *   pending     an eligible article that has not been indexed yet (or whose last
 *               attempt failed transiently and is waiting for a retry)
 *   processing  a worker is embedding it now
 *   indexed     its chunks are stored and match its current content hash
 *   failed      indexing gave up; `last_error` carries a safe, static reason
 *   stale       the index no longer matches the article — its content changed
 *               since it was indexed, or it stopped being eligible
 *
 * **Only `indexed` is retrievable.** A stale article is temporarily *not found*
 * rather than found with an out-of-date solution: for a verified fix, "not
 * there yet" is better than "wrong".
 */
class KnowledgeIndexer
{
    /**
     * The width of `ai_embeddings.embedding` — `vector(768)`, fixed by OD-4 and
     * by the schema. A model configured to any other width cannot be stored, and
     * is refused before a single provider call is spent on it.
     */
    public const COLUMN_DIMENSIONS = 768;

    /** A `processing` row untouched for this long belongs to a worker that died. */
    public const STUCK_AFTER_MINUTES = 60;

    public function __construct(
        private readonly AiSettings $settings,
        private readonly KnowledgeChunker $chunker,
    ) {}

    public static function sourceType(): EmbeddableSourceType
    {
        return EmbeddableSourceType::KnowledgeArticle;
    }

    /** Published and not withdrawn — the only articles that may be retrieved. */
    public function eligible(AiKnowledgeArticle $article): bool
    {
        return $article->status === KnowledgeStatus::Published && ! $article->trashed();
    }

    /**
     * The embedding model in force, or null when none is configured. "Not set up
     * yet" is a normal state for an installation that has not switched AI on, so
     * it is a null here and not an exception.
     */
    public function model(): ?AiModel
    {
        try {
            return $this->settings->embeddingModel();
        } catch (AiUnavailableException) {
            return null;
        }
    }

    /**
     * Whether a job dispatched now could do any work: AI is on, a model is
     * chosen, and its provider has a key. Used to decide whether to *queue* —
     * never whether to record state, which is tracked regardless.
     */
    public function canRun(?AiModel $model = null): bool
    {
        $model ??= $this->model();

        return $model !== null
            && $this->settings->assistantEnabled()
            && $this->settings->hasProviderKey($this->settings->providerFor($model));
    }

    /**
     * Bring the index's record of this article up to date, and queue the work if
     * it can be done.
     *
     * Called on every save, so the common case — nothing about the *content*
     * changed — must be cheap and must not touch the provider: it is a hash
     * comparison and, at most, one small write.
     */
    public function request(AiKnowledgeArticle $article): void
    {
        if (! $this->eligible($article)) {
            $this->retire($article);

            return;
        }

        $model = $this->model();

        if ($model === null) {
            // Nothing can be recorded without a model (the source row is keyed
            // on one). The sweep picks the article up once one is configured.
            return;
        }

        $hash = $this->chunker->hash($article);

        $needsWork = DB::transaction(function () use ($article, $model, $hash): bool {
            $source = $this->lockSource((int) $article->getKey(), $model);

            if ($source->embedding_status === EmbeddingStatus::Indexed && $source->content_hash === $hash) {
                return false;
            }

            $source->forceFill([
                'embedding_status' => match ($source->embedding_status) {
                    // The index existed and no longer matches: stale, not new.
                    EmbeddingStatus::Indexed => EmbeddingStatus::Stale->value,
                    // A new edit deserves a fresh attempt, and the old error
                    // described a different version of the article.
                    EmbeddingStatus::Failed => EmbeddingStatus::Pending->value,
                    default => $source->embedding_status->value,
                },
                'last_error' => null,
            ])->save();

            return true;
        });

        if ($needsWork && $this->canRun($model)) {
            $this->dispatch($article);
        }
    }

    /**
     * The source row for an article and model, locked for the surrounding
     * transaction — created `pending` if this is the first time it is seen.
     *
     * `insertOrIgnore` then a locking read, rather than find-or-create: two
     * writers (an edit and a sweep, or two saves) meeting on a brand-new article
     * would both find nothing and both insert, and the loser would die on the
     * unique key. This way exactly one inserts and both proceed.
     *
     * Must be called inside a transaction, or the lock is released at once.
     */
    public function lockSource(int $articleId, AiModel $model): AiEmbeddingSource
    {
        $now = now();

        AiEmbeddingSource::query()->insertOrIgnore([
            'source_type' => self::sourceType()->value,
            'source_id' => $articleId,
            'ai_model_id' => $model->getKey(),
            'embedding_status' => EmbeddingStatus::Pending->value,
            'chunk_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return AiEmbeddingSource::query()
            ->where('source_type', self::sourceType()->value)
            ->where('source_id', $articleId)
            ->where('ai_model_id', $model->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Queue an indexing run for an article, once the surrounding transaction (if
     * any) has committed — a worker that starts before the row it was told about
     * is visible would find nothing and give up.
     *
     * **Never lets indexing break the write that triggered it.** Saving an
     * article is the user's act; searchability is a consequence. Under the
     * `sync` queue driver (local development, tests) a provider failure would
     * otherwise surface as an exception out of `save()`, and even on a real
     * queue a broker outage would. The failure is reported and the source row
     * already records where things stand, so the sweep — which re-queues
     * anything not indexed — is the recovery path.
     */
    public function dispatch(AiKnowledgeArticle $article): void
    {
        $id = (int) $article->getKey();

        DB::afterCommit(static function () use ($id): void {
            try {
                GenerateEmbeddingJob::dispatch($id);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * Take an article out of retrieval: delete its chunks, and mark any source
     * row stale with nothing behind it.
     *
     * Deliberately *not* deleting the source row: it is the record that the
     * article was once indexed and why it no longer is, and it is what lets a
     * republish resume from a known state instead of from nothing.
     */
    public function retire(AiKnowledgeArticle|int $article): void
    {
        $id = $article instanceof AiKnowledgeArticle ? (int) $article->getKey() : $article;

        DB::transaction(function () use ($id): void {
            DB::table('ai_embeddings')
                ->where('embeddable_type', self::sourceType()->value)
                ->where('embeddable_id', $id)
                ->delete();

            AiEmbeddingSource::query()
                ->where('source_type', self::sourceType()->value)
                ->where('source_id', $id)
                ->update([
                    'embedding_status' => EmbeddingStatus::Stale->value,
                    'chunk_count' => 0,
                    'content_hash' => null,
                    'indexed_at' => null,
                    'last_error' => null,
                ]);
        });
    }

    /**
     * Remove every trace of an article from the index — its chunks *and* its
     * source rows. For an article that no longer exists at all.
     */
    public function purge(int $articleId): void
    {
        DB::transaction(function () use ($articleId): void {
            DB::table('ai_embeddings')
                ->where('embeddable_type', self::sourceType()->value)
                ->where('embeddable_id', $articleId)
                ->delete();

            AiEmbeddingSource::query()
                ->where('source_type', self::sourceType()->value)
                ->where('source_id', $articleId)
                ->delete();
        });
    }
}
