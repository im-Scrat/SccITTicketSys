<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\DTOs\KnowledgeMatch;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Enums\EmbeddingStatus;
use App\Enums\KnowledgeStatus;
use Illuminate\Support\Facades\DB;

/**
 * **Similarity search over the published knowledge articles** (WP-P; SRS
 * FR-AI-007). Returns the closest articles to a piece of text — nothing more.
 * It does not answer, rank by authority or act: what a caller does with the
 * matches is advisory, and the caller cites them (FR-AI-007) by uuid.
 *
 * ── What can be found ──────────────────────────────────────────────────────
 *
 * Only chunks that satisfy **all** of these:
 *
 *  - the article is `published` and not soft-deleted (a draft or archived
 *    article is never returned, even if a chunk of it were somehow still in the
 *    table — publication is checked here as well as by the indexer removing
 *    chunks on unpublish);
 *  - its source row is `indexed` — a `stale`, `pending`, `processing` or
 *    `failed` article is *not found* rather than found with an out-of-date
 *    solution;
 *  - the chunk was produced by the embedding model in force now. Vectors from
 *    different models are not comparable, so after a model change nothing
 *    matches until the sweep has re-indexed — an empty result, never a wrong one.
 *
 * Only `knowledge_article` rows are ever read. The transactional sources are
 * not in the index (see {@see KnowledgeIndexer}), and the type filter is the
 * second line of defence should one ever be put there.
 *
 * ── How the index is used ──────────────────────────────────────────────────
 *
 * The `ai_embeddings_embedding_hnsw` index only serves a query shaped
 * `ORDER BY embedding <=> $query LIMIT n` with no join between the ordering and
 * the limit. So the nearest chunks are taken first, in a CTE that can use it,
 * and the publication / state filters are applied to that candidate pool after.
 * The pool is far larger than the number of results asked for, so filtering
 * out withdrawn articles does not starve the result; it is bounded, so a huge
 * table never turns into a huge scan. `hnsw.ef_search` is raised to match the
 * pool, because pgvector returns at most that many candidates from an HNSW
 * scan regardless of the LIMIT.
 *
 * **Known limit.** pgvector 0.6 applies a `WHERE` *after* the index scan, so the
 * type and model filters inside the CTE also thin the pool. Today the table
 * holds one source type and, outside a model change, one model, so they remove
 * nothing; after a model change the previous model's vectors compete for pool
 * slots until the sweep has re-indexed. The failure mode is fewer results, never
 * a wrong one — the join filters below are what guarantee correctness.
 */
class KnowledgeRetriever
{
    /** Candidate chunks fetched per result requested, before filtering. */
    private const POOL_FACTOR = 10;

    private const MIN_POOL = 50;

    /** pgvector's own ceiling for `hnsw.ef_search`. */
    private const MAX_EF_SEARCH = 1000;

    public function __construct(
        private readonly EmbeddingGenerator $generator,
        private readonly KnowledgeIndexer $indexer,
        private readonly AiSettings $settings,
    ) {}

    /**
     * @param  float|null  $minSimilarity  drop matches below this cosine similarity; null keeps them all
     * @return list<KnowledgeMatch> at most one match per article, closest first
     *
     * @throws AiUnavailableException
     * @throws AiProviderException
     */
    public function search(string $query, int $limit = 5, ?float $minSimilarity = null): array
    {
        $query = trim($query);
        $limit = max(1, min($limit, 20));

        if ($query === '' || ! $this->settings->assistantEnabled()) {
            return [];
        }

        $model = $this->indexer->model();

        if ($model === null) {
            return [];
        }

        // Dimension-checked by the generator, and again here as we build the
        // literal: this string is interpolated into SQL as a bound parameter,
        // never concatenated.
        $literal = VectorLiteral::from($this->generator->embed($query), KnowledgeIndexer::COLUMN_DIMENSIONS);

        $pool = max(self::MIN_POOL, $limit * self::POOL_FACTOR);

        $rows = DB::transaction(function () use ($literal, $model, $pool) {
            // SET LOCAL: scoped to this transaction, so it never leaks into the
            // connection's next use (queue workers and Octane keep connections).
            DB::statement('SET LOCAL hnsw.ef_search = '.min(self::MAX_EF_SEARCH, $pool));

            return DB::select(
                <<<'SQL'
                WITH nearest AS (
                    SELECT embeddable_id, chunk_index, content, embedding <=> ?::vector AS distance
                    FROM ai_embeddings
                    WHERE embeddable_type = ?
                      AND ai_model_id = ?
                    ORDER BY embedding <=> ?::vector
                    LIMIT ?
                )
                SELECT DISTINCT ON (a.id)
                    a.uuid, a.slug, a.title, a.category,
                    n.chunk_index, n.content, n.distance
                FROM nearest n
                JOIN ai_knowledge_articles a ON a.id = n.embeddable_id
                JOIN ai_embedding_sources s
                    ON s.source_type = ? AND s.source_id = a.id AND s.ai_model_id = ?
                WHERE a.status = ?
                  AND a.deleted_at IS NULL
                  AND s.embedding_status = ?
                ORDER BY a.id, n.distance
                SQL,
                [
                    $literal,
                    KnowledgeIndexer::sourceType()->value,
                    $model->getKey(),
                    $literal,
                    $pool,
                    KnowledgeIndexer::sourceType()->value,
                    $model->getKey(),
                    KnowledgeStatus::Published->value,
                    EmbeddingStatus::Indexed->value,
                ],
            );
        });

        $matches = [];

        foreach ($rows as $row) {
            $similarity = 1.0 - (float) $row->distance;

            if ($minSimilarity !== null && $similarity < $minSimilarity) {
                continue;
            }

            $matches[] = new KnowledgeMatch(
                articleUuid: (string) $row->uuid,
                slug: (string) $row->slug,
                title: (string) $row->title,
                category: $row->category !== null ? (string) $row->category : null,
                chunkIndex: (int) $row->chunk_index,
                content: (string) $row->content,
                similarity: $similarity,
            );
        }

        // DISTINCT ON forced ordering by article id; restore closest-first.
        usort($matches, static fn (KnowledgeMatch $a, KnowledgeMatch $b): int => $b->similarity <=> $a->similarity);

        return array_slice($matches, 0, $limit);
    }
}
