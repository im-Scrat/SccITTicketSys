<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Http\Controllers;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\KnowledgeBase\Http\Requests\KnowledgeArticleRequest;
use App\Domains\KnowledgeBase\Http\Resources\KnowledgeArticleResource;
use App\Domains\KnowledgeBase\Services\KnowledgeVisibility;
use App\Enums\ActivityAction;
use App\Enums\KnowledgeStatus;
use App\Http\Controllers\Controller;
use App\Models\AiKnowledgeArticle;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The knowledge base (WP-Q; SRS FR-AI-005; SDD §23.3, DD-76).
 *
 * Who may see what is {@see KnowledgeVisibility}'s call, applied identically to
 * the list and to a single article. Publishing and archiving are editorial acts
 * kept to the roles holding `knowledge.publish` / `knowledge.update`; a
 * technician can author drafts but a draft is invisible to requesters until an
 * administrator publishes it.
 *
 * Indexing for retrieval is **not** done here. `AiKnowledgeArticleObserver`
 * (WP-P) reacts to the saved/deleted model, so every path that changes an
 * article — this controller, a future importer, an auto-draft from a resolved
 * ticket — keeps the vector index in step without remembering to; publishing
 * indexes it, and archiving or deleting retires it.
 */
class KnowledgeArticleController extends Controller
{
    public function __construct(
        private readonly KnowledgeVisibility $visibility,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:draft,published,archived'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = $this->visibility->scope(AiKnowledgeArticle::query()->with(['createdBy']), $user);

        if (! empty($filters['status'])) {
            $query->where('ai_knowledge_articles.status', $filters['status']);
        }

        if (! empty($filters['category'])) {
            $query->where('ai_knowledge_articles.category', $filters['category']);
        }

        if (! empty($filters['q'])) {
            // Full-text over the generated `search_vector` (FR-AI-005). Ranked,
            // and falls back to a title match so a one-word query that stems to
            // nothing still finds something.
            $term = (string) $filters['q'];
            $query->where(function ($match) use ($term): void {
                $match->whereRaw("ai_knowledge_articles.search_vector @@ websearch_to_tsquery('english', ?)", [$term])
                    ->orWhere('ai_knowledge_articles.title', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%');
            })->orderByRaw("ts_rank(ai_knowledge_articles.search_vector, websearch_to_tsquery('english', ?)) desc", [$term]);
        }

        $articles = $query
            ->orderByDesc('ai_knowledge_articles.published_at')
            ->orderByDesc('ai_knowledge_articles.id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return KnowledgeArticleResource::collection($articles);
    }

    public function show(AiKnowledgeArticle $article): JsonResponse
    {
        $this->authorize('view', $article);

        $article->load(['createdBy', 'createdFromTicket']);

        return (new KnowledgeArticleResource($article, detail: true))->response();
    }

    public function store(KnowledgeArticleRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $article = AiKnowledgeArticle::query()->create([
            ...$request->validated(),
            'slug' => $this->uniqueSlug((string) $request->validated('title')),
            'status' => KnowledgeStatus::Draft->value,
            'created_by' => $user->getKey(),
        ]);

        $this->audit->activity(ActivityAction::KnowledgeArticleCreated, $user, $article, ['title' => $article->title], $request, 'knowledge', "Knowledge article “{$article->title}” created");

        return (new KnowledgeArticleResource($article->load('createdBy'), detail: true))
            ->additional(['message' => 'Draft saved.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(KnowledgeArticleRequest $request, AiKnowledgeArticle $article): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $article->fill($request->validated())->save();

        $this->audit->activity(ActivityAction::KnowledgeArticleUpdated, $user, $article, ['title' => $article->title], $request, 'knowledge', "Knowledge article “{$article->title}” updated");

        return (new KnowledgeArticleResource($article->load('createdBy'), detail: true))
            ->additional(['message' => 'Article saved.'])
            ->response();
    }

    public function publish(Request $request, AiKnowledgeArticle $article): JsonResponse
    {
        $this->authorize('publish', $article);

        /** @var User $user */
        $user = $request->user();

        // A published article is advice people will follow. One with no
        // solution is noise that retrieval would then quote.
        if (trim((string) $article->verified_solution) === '') {
            throw ValidationException::withMessages([
                'verified_solution' => 'Add a verified solution before publishing.',
            ]);
        }

        DB::transaction(function () use ($article): void {
            $article->forceFill([
                'status' => KnowledgeStatus::Published->value,
                'published_at' => $article->published_at ?? now(),
            ])->save();
        });

        $this->audit->activity(ActivityAction::KnowledgeArticlePublished, $user, $article, ['title' => $article->title], $request, 'knowledge', "Knowledge article “{$article->title}” published");

        return (new KnowledgeArticleResource($article->load('createdBy'), detail: true))
            ->additional(['message' => 'Article published.'])
            ->response();
    }

    public function archive(Request $request, AiKnowledgeArticle $article): JsonResponse
    {
        $this->authorize('archive', $article);

        /** @var User $user */
        $user = $request->user();

        $article->forceFill(['status' => KnowledgeStatus::Archived->value])->save();

        $this->audit->activity(ActivityAction::KnowledgeArticleArchived, $user, $article, ['title' => $article->title], $request, 'knowledge', "Knowledge article “{$article->title}” archived");

        return (new KnowledgeArticleResource($article->load('createdBy'), detail: true))
            ->additional(['message' => 'Article archived and withdrawn from search and the assistant.'])
            ->response();
    }

    public function destroy(Request $request, AiKnowledgeArticle $article): JsonResponse
    {
        $this->authorize('delete', $article);

        /** @var User $user */
        $user = $request->user();

        $this->audit->activity(ActivityAction::KnowledgeArticleDeleted, $user, $article, ['title' => $article->title], $request, 'knowledge', "Knowledge article “{$article->title}” deleted");

        $article->delete();

        return response()->json(['message' => 'Article deleted.']);
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug(Str::limit($title, 80, '')) ?: 'article';
        $slug = $base;
        $n = 2;

        while (AiKnowledgeArticle::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
