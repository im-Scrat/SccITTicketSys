<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Observers;

use App\Domains\KnowledgeBase\Services\KnowledgeIndexer;
use App\Models\AiKnowledgeArticle;

/**
 * Keeps the vector index in step with the knowledge articles (WP-P).
 *
 * Every path an article can take into or out of the published set passes
 * through one of these events, so the index cannot drift from the source by
 * anyone's forgetting to call it — the alternative, a call in each controller
 * or action, fails the first time a seeder, a tinker session or a later
 * feature edits an article another way.
 *
 * The observer owns no logic: {@see KnowledgeIndexer} decides what a change
 * means (a hash comparison, so a save that touched nothing searchable costs one
 * indexed read and no provider call). It only says *when* to ask.
 */
class AiKnowledgeArticleObserver
{
    public function __construct(private readonly KnowledgeIndexer $indexer) {}

    /** Created or updated. Covers publish, unpublish and edit alike. */
    public function saved(AiKnowledgeArticle $article): void
    {
        $this->indexer->request($article);
    }

    /** Soft delete — the article is withdrawn, so its vectors go with it. */
    public function deleted(AiKnowledgeArticle $article): void
    {
        if ($article->isForceDeleting()) {
            return; // handled by forceDeleted, which purges instead of retiring
        }

        $this->indexer->retire($article);
    }

    /** Restored — it is eligible again only if it is still published. */
    public function restored(AiKnowledgeArticle $article): void
    {
        $this->indexer->request($article);
    }

    /** Gone for good: no source row worth keeping either. */
    public function forceDeleted(AiKnowledgeArticle $article): void
    {
        $this->indexer->purge((int) $article->getKey());
    }
}
