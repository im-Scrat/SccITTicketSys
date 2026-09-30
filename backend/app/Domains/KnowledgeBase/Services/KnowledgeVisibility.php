<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Enums\KnowledgeStatus;
use App\Models\AiKnowledgeArticle;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which knowledge articles a user may see — for lists and for single records
 * from **one predicate** (SRS FR-AI-005; SDD DD-40 pattern).
 *
 *   administrator  every article, every status
 *   technician     published articles, plus the drafts they wrote themselves
 *   requester      published articles only
 *
 * "Published only for Teachers" is FR-AI-005's own wording. The technician's
 * own-drafts allowance is what makes `knowledge.create` usable: a permission to
 * create an article that then vanishes from your own list would be a trap.
 *
 * Shared by the list query, the policy and the detail endpoint, so an article
 * absent from a user's list is equally unreachable by uuid — the IDOR rule
 * CLAUDE.md §6 requires.
 */
class KnowledgeVisibility
{
    /**
     * @param  Builder<AiKnowledgeArticle>  $query
     * @return Builder<AiKnowledgeArticle>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if (! $user->hasPermissionTo('knowledge.view')) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isManager($user)) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($user): void {
            $visible->where('ai_knowledge_articles.status', KnowledgeStatus::Published->value);

            if ($user->hasPermissionTo('knowledge.create')) {
                $visible->orWhere('ai_knowledge_articles.created_by', $user->getKey());
            }
        });
    }

    public function canSee(User $user, AiKnowledgeArticle $article): bool
    {
        if (! $user->hasPermissionTo('knowledge.view')) {
            return false;
        }

        if ($this->isManager($user) || $article->status === KnowledgeStatus::Published) {
            return true;
        }

        return $user->hasPermissionTo('knowledge.create') && $article->created_by === $user->getKey();
    }

    /** May this user edit this article? Managers always; authors only their own drafts. */
    public function canEdit(User $user, AiKnowledgeArticle $article): bool
    {
        if ($this->isManager($user)) {
            return true;
        }

        return $user->hasPermissionTo('knowledge.create')
            && $article->created_by === $user->getKey()
            && $article->status === KnowledgeStatus::Draft;
    }

    /** An administrator-grade manager of the whole knowledge base. */
    public function isManager(User $user): bool
    {
        return $user->hasPermissionTo('knowledge.update');
    }
}
