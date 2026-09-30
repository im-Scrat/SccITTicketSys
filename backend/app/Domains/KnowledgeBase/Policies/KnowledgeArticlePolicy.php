<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Policies;

use App\Domains\KnowledgeBase\Services\KnowledgeVisibility;
use App\Models\AiKnowledgeArticle;
use App\Models\User;

/**
 * Per-article rules on top of the `knowledge.*` route gates (SRS FR-AI-005).
 *
 * The route gate says "you may use the knowledge base at all"; this says which
 * articles, delegating to {@see KnowledgeVisibility} so the list query and the
 * single-record check cannot disagree.
 */
class KnowledgeArticlePolicy
{
    public function __construct(private readonly KnowledgeVisibility $visibility) {}

    public function view(User $actor, AiKnowledgeArticle $article): bool
    {
        return $this->visibility->canSee($actor, $article);
    }

    public function update(User $actor, AiKnowledgeArticle $article): bool
    {
        return $this->visibility->canEdit($actor, $article);
    }

    /** Publishing is an editorial act — `knowledge.publish`, administrators only. */
    public function publish(User $actor, AiKnowledgeArticle $article): bool
    {
        return $actor->hasPermissionTo('knowledge.publish');
    }

    public function archive(User $actor, AiKnowledgeArticle $article): bool
    {
        return $actor->hasPermissionTo('knowledge.update');
    }

    public function delete(User $actor, AiKnowledgeArticle $article): bool
    {
        return $actor->hasPermissionTo('knowledge.delete');
    }
}
