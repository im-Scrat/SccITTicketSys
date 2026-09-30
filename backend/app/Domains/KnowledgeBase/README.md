# KnowledgeBase domain

AI in the school IT system: ticket pre-screening, predictive maintenance, and the
RAG index over the published knowledge articles. Everything AI here is
**advisory** — it proposes; a person decides.

Namespaced `App\Domains\KnowledgeBase`; Eloquent models are flat in `app/Models`
as everywhere else in the project.

## What is in the vector index (WP-P)

**Source A only: the published knowledge articles.** The application's own
records — tickets, comments, maintenance records — are deliberately **not**
embedded. They are visibility-scoped row by row by their own services, and one
shared index over them would be a second, unscoped route to the same text
(SDD DD-71). The `ticket`, `ticket_comment` and `maintenance_record` values of
`EmbeddableSourceType` exist in the baselined schema and are never written; a
test enforces it.

```
AiKnowledgeArticle ──observer──▶ KnowledgeIndexer ──▶ GenerateEmbeddingJob
   (save/delete/restore)          (state + hash)        (chunk → embed → store)
                                        ▲                       │
        knowledge:index (daily) ────────┘                       ▼
                                                     ai_embeddings (vector(768))
                                                                │  HNSW cosine
                                                     KnowledgeRetriever::search()
```

| Piece | Job |
|---|---|
| `Services/KnowledgeChunker` | Cuts an article into header-prefixed chunks (≤ 1200 chars, 150 overlap) and computes the content hash. Its `VERSION` is part of the hash, so changing the chunker makes everything stale on the next sweep. |
| `Services/KnowledgeIndexer` | Owns every transition of `ai_embedding_sources`: `request` (reconcile after a change), `retire` (take out of retrieval), `purge` (article gone). |
| `Jobs/GenerateEmbeddingJob` | Embeds one article. Unique per article until it starts; 3 tries with backoff; re-reads the article before writing; one transaction to replace chunks and mark `indexed`. |
| `Observers/AiKnowledgeArticleObserver` | Says *when* to reconcile; owns no logic. |
| `Console/IndexKnowledgeArticles` (`knowledge:index`) | Daily safety net for what the observer cannot see. `--retry-failed` re-queues failures. Never calls the provider itself. |
| `Services/KnowledgeRetriever` | Similarity search. Returns `KnowledgeMatch` (uuid + slug, never a numeric id). |

### States

`pending` → `processing` → `indexed` | `failed`; `indexed` → `stale` when the
content hash moves on or the article stops being published. **Only `indexed` is
retrievable**, and only for articles that are published and not deleted, and only
for vectors from the embedding model currently in force.

### Failure

A provider failure (`AiProviderException::isTransient()`) retries; a
configuration failure — no key, a model that is not 768 wide, a malformed
response — is recorded `failed` immediately. `last_error` only ever holds a
static message from this codebase, never provider output. An indexing failure
never surfaces from the article `save()`.

## Content

**No starter articles are authored here.** The index holds whatever an
administrator publishes. Nothing in this domain invents school procedures,
institutional contacts, escalation policies or official rules.

## Working on it

```bash
docker compose exec -T app ./vendor/bin/pest tests/Feature/AI tests/Unit/KnowledgeChunkerTest.php
docker compose exec -T app php artisan knowledge:index            # reconcile now
docker compose exec -T app php artisan knowledge:index --retry-failed
```

Tests never need a provider key: `Tests\Support\KnowledgeIndexFixtures` installs a
deterministic hashed-bag-of-words embedder through `Embeddings::fake()`, so
similarity *ranking* is assertable offline.
