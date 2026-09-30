<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Console;

use App\Domains\KnowledgeBase\Services\KnowledgeChunker;
use App\Domains\KnowledgeBase\Services\KnowledgeIndexer;
use App\Enums\EmbeddingStatus;
use App\Enums\KnowledgeStatus;
use App\Models\AiEmbeddingSource;
use App\Models\AiKnowledgeArticle;
use Illuminate\Console\Command;

/**
 * **Reconcile the vector index with the knowledge articles** (WP-P; SRS FR-AI-006).
 *
 * The observer keeps the index current as articles change; this is the safety
 * net for everything it cannot see — articles that existed before the index
 * did, ones edited while the queue or the provider was down, a worker that
 * died mid-run, a chunker upgrade (its version is inside the content hash, so
 * a new version makes every article stale on the next sweep), and a change of
 * embedding model. It is idempotent: running it twice queues nothing the
 * first run did not, because every article it finds already indexed for its
 * current content is left alone.
 *
 * ── What it does ───────────────────────────────────────────────────────────
 *
 *  1. Retires any source row whose article is no longer published.
 *  2. Marks `indexed` rows `stale` when the article's content hash has moved on.
 *  3. Queues a job for every published article that is missing, pending, stale
 *     or stuck `processing` (a worker gone for {@see KnowledgeIndexer::STUCK_AFTER_MINUTES}).
 *  4. Leaves `failed` articles alone unless `--retry-failed` is given: a failure
 *     that already exhausted its retries is usually a configuration problem, and
 *     re-queuing it every night would only repeat the same failure. An edit made
 *     through the model retries it automatically (the observer resets `failed`
 *     to `pending`); one made by a bulk update needs `--retry-failed`.
 *
 * Nothing is queued when no embedding model, no provider key or the assistant
 * switch is off — the states are still reconciled, so the screens tell the
 * truth, but no job is spent on work that cannot succeed.
 *
 * Detection reads state and hashes only; **the provider is never called here**.
 */
class IndexKnowledgeArticles extends Command
{
    protected $signature = 'knowledge:index
                            {--retry-failed : Also re-queue articles whose last indexing attempt failed}';

    protected $description = 'Reconcile the knowledge-article vector index and queue indexing for anything missing or out of date';

    public function handle(KnowledgeIndexer $indexer, KnowledgeChunker $chunker): int
    {
        $model = $indexer->model();

        if ($model === null) {
            $this->warn('No embedding model is configured; nothing to index.');

            return self::SUCCESS;
        }

        $retired = $this->retireIneligible($indexer);

        $sources = AiEmbeddingSource::query()
            ->where('source_type', KnowledgeIndexer::sourceType()->value)
            ->where('ai_model_id', $model->getKey())
            ->get()
            ->keyBy('source_id');

        $canRun = $indexer->canRun($model);
        $retryFailed = (bool) $this->option('retry-failed');
        $queued = 0;
        $staled = 0;

        AiKnowledgeArticle::query()
            ->where('status', KnowledgeStatus::Published->value)
            ->orderBy('id')
            ->chunkById(100, function ($articles) use ($sources, $model, $chunker, $indexer, $canRun, $retryFailed, &$queued, &$staled): void {
                foreach ($articles as $article) {
                    /** @var AiKnowledgeArticle $article */
                    $source = $sources->get($article->getKey());
                    $hash = $chunker->hash($article);

                    $needsWork = match (true) {
                        $source === null => true,
                        $source->embedding_status === EmbeddingStatus::Indexed => $source->content_hash !== $hash,
                        $source->embedding_status === EmbeddingStatus::Failed => $retryFailed,
                        $source->embedding_status === EmbeddingStatus::Processing => $source->updated_at !== null
                            && $source->updated_at->lt(now()->subMinutes(KnowledgeIndexer::STUCK_AFTER_MINUTES)),
                        default => true, // pending, stale
                    };

                    if (! $needsWork) {
                        continue;
                    }

                    if ($source === null) {
                        AiEmbeddingSource::query()->create([
                            'source_type' => KnowledgeIndexer::sourceType()->value,
                            'source_id' => $article->getKey(),
                            'ai_model_id' => $model->getKey(),
                            'embedding_status' => EmbeddingStatus::Pending->value,
                            'chunk_count' => 0,
                        ]);
                    } else {
                        $next = $source->embedding_status === EmbeddingStatus::Indexed
                            ? EmbeddingStatus::Stale
                            : ($source->embedding_status === EmbeddingStatus::Processing || $source->embedding_status === EmbeddingStatus::Failed
                                ? EmbeddingStatus::Pending
                                : $source->embedding_status);

                        if ($next !== $source->embedding_status) {
                            $source->forceFill(['embedding_status' => $next->value])->save();
                            $staled++;
                        }
                    }

                    if ($canRun) {
                        $indexer->dispatch($article);
                        $queued++;
                    }
                }
            });

        $this->info(sprintf(
            'Knowledge index: %d retired, %d marked out of date, %d queued%s.',
            $retired,
            $staled,
            $queued,
            $canRun ? '' : ' (indexing is not currently possible: no provider key, or the assistant is switched off)',
        ));

        return self::SUCCESS;
    }

    /**
     * Retire the vectors of any article that stopped being published without the
     * observer seeing it — a bulk update, which raises no model events.
     */
    private function retireIneligible(KnowledgeIndexer $indexer): int
    {
        $ids = AiEmbeddingSource::query()
            ->where('source_type', KnowledgeIndexer::sourceType()->value)
            ->pluck('source_id')
            ->unique();

        $retired = 0;

        foreach ($ids->chunk(200) as $batch) {
            $eligible = AiKnowledgeArticle::query()
                ->whereIn('id', $batch)
                ->where('status', KnowledgeStatus::Published->value)
                ->pluck('id');

            foreach ($batch->diff($eligible) as $id) {
                $exists = AiKnowledgeArticle::withTrashed()->whereKey($id)->exists();
                $exists ? $indexer->retire((int) $id) : $indexer->purge((int) $id);
                $retired++;
            }
        }

        return $retired;
    }
}
