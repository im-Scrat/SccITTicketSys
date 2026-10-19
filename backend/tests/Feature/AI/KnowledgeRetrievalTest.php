<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\DTOs\KnowledgeMatch;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Domains\KnowledgeBase\Services\KnowledgeRetriever;
use App\Models\AiEmbeddingSource;
use App\Models\AiKnowledgeArticle;
use App\Models\AiModel;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Tests\Support\KnowledgeIndexFixtures as Fx;

/**
 * WP-P — similarity search over the published knowledge articles.
 *
 * Ranking is asserted, not scores: the deterministic embedder puts texts that
 * share words closer together, so "the projector article comes first for a
 * projector question" is a real property of the search, with no network.
 */
beforeEach(function (): void {
    $this->model = Fx::configure();
    $this->seen = [];
    Fx::fakeProvider($this->seen);

    $this->projector = Fx::published('Projector will not power on', 'The projector shows no light and no fan noise.', 'The projector power lead is loose.', 'Reseat the projector power lead.');
    $this->printer = Fx::published('Printer prints blank pages', 'The printer feeds paper but the pages come out blank.', 'The toner cartridge is empty.', 'Replace the toner cartridge.');
    $this->wifi = Fx::published('Wi-Fi keeps disconnecting', 'The laptop loses its wireless network every few minutes.', 'Outdated wireless driver.', 'Update the wireless driver.');
});

function retrieve(string $query, int $limit = 5, ?float $min = null): array
{
    return app(KnowledgeRetriever::class)->search($query, $limit, $min);
}

function slugs(array $matches): array
{
    return array_map(fn (KnowledgeMatch $m) => $m->slug, $matches);
}

it('ranks the article about the question first', function (): void {
    $matches = retrieve('projector shows no light no fan noise');

    expect($matches[0]->articleUuid)->toBe($this->projector->uuid)
        ->and($matches[0]->title)->toBe('Projector will not power on')
        ->and($matches[0]->similarity)->toBeGreaterThan(0.3);

    expect(retrieve('printer pages come out blank')[0]->articleUuid)->toBe($this->printer->uuid)
        ->and(retrieve('laptop loses wireless network')[0]->articleUuid)->toBe($this->wifi->uuid);
});

it('returns closest first, at most one match per article, and honours the limit', function (): void {
    $all = retrieve('projector printer wireless toner driver lead');

    expect(count($all))->toBe(3)
        ->and(count(array_unique(array_map(fn ($m) => $m->articleUuid, $all))))->toBe(3)
        ->and(array_map(fn ($m) => $m->similarity, $all))->toBe(collect($all)->pluck('similarity')->sortDesc()->values()->all());

    expect(retrieve('projector printer wireless', 2))->toHaveCount(2);
});

it('cites by uuid and slug, and carries the matching chunk — never a numeric id', function (): void {
    $match = retrieve('projector power lead')[0];

    expect($match->articleUuid)->toBe($this->projector->uuid)
        ->and($match->slug)->toBe($this->projector->slug)
        ->and($match->category)->toBe('Hardware')
        ->and($match->content)->toContain('projector')
        ->and($match->chunkIndex)->toBeInt()
        ->and(array_keys(get_object_vars($match)))->toBe(['articleUuid', 'slug', 'title', 'category', 'chunkIndex', 'content', 'similarity']);
});

it('can drop weak matches with a minimum similarity', function (): void {
    expect(retrieve('quantum chromodynamics lagrangian', 5, 0.5))->toBe([])
        ->and(retrieve('projector shows no light no fan noise', 5, 0.3))->not->toBeEmpty();
});

it('never returns a draft, even one that was indexed while published and then unpublished by a bulk update', function (): void {
    // A bulk update raises no model events: the vectors are still in the table.
    AiKnowledgeArticle::query()->whereKey($this->projector->id)->update(['status' => 'draft']);
    expect(DB::table('ai_embeddings')->where('embeddable_id', $this->projector->id)->exists())->toBeTrue();

    expect(slugs(retrieve('projector shows no light no fan noise')))->not->toContain($this->projector->slug);
});

it('never returns an archived or soft-deleted article', function (): void {
    AiKnowledgeArticle::query()->whereKey($this->printer->id)->update(['status' => 'archived']);
    AiKnowledgeArticle::query()->whereKey($this->wifi->id)->update(['deleted_at' => now()]);

    $found = slugs(retrieve('printer blank pages wireless network'));

    expect($found)->not->toContain($this->printer->slug)
        ->and($found)->not->toContain($this->wifi->slug)
        ->and($found)->toBe([$this->projector->slug]); // the one article still published, however weak the match
});

it('does not find an article that has never been indexed', function (): void {
    $fresh = AiKnowledgeArticle::withoutEvents(fn () => Fx::published('Brand new topic zebra', 'zebra zebra zebra'));

    expect(slugs(retrieve('zebra zebra zebra')))->not->toContain($fresh->slug);
});

it('does not find a stale article — not there yet beats found with an out-of-date fix', function (string $status): void {
    AiEmbeddingSource::query()->where('source_id', $this->projector->id)->update(['embedding_status' => $status]);

    expect(slugs(retrieve('projector shows no light no fan noise')))->not->toContain($this->projector->slug);
})->with(['stale', 'pending', 'processing', 'failed']);

it('finds an article again once it is re-indexed after an edit', function (): void {
    $this->projector->update(['verified_solution' => 'Replace the projector lamp.']);

    $match = retrieve('projector lamp')[0];

    expect($match->articleUuid)->toBe($this->projector->uuid)
        ->and(collect(retrieve('projector lamp'))->first()->content)->not->toBeEmpty();
});

it('ignores vectors produced by a different embedding model', function (): void {
    $other = AiModel::factory()->embedding()->create(['provider' => 'gemini', 'embedding_dimensions' => 768]);
    DB::table('ai_embeddings')->where('embeddable_id', $this->projector->id)->update(['ai_model_id' => $other->id]);
    AiEmbeddingSource::query()->where('source_id', $this->projector->id)->update(['ai_model_id' => $other->id]);

    expect(slugs(retrieve('projector shows no light no fan noise')))->not->toContain($this->projector->slug);
});

it('reads only knowledge-article vectors, even if a transactional row were somehow in the table', function (): void {
    // A ticket-typed row that is an exact match for the query. It must not surface,
    // and — since the retriever joins to the articles table — it could not be
    // presented as an article in any case.
    DB::table('ai_embeddings')->insert([
        'embeddable_type' => 'ticket', 'embeddable_id' => $this->projector->id, 'ai_model_id' => $this->model->id,
        'chunk_index' => 0, 'content' => 'Ticket: confidential complaint about the head teacher', 'content_hash' => str_repeat('b', 64),
        'embedding' => '['.implode(',', Fx::embedding('confidential complaint head teacher')).']',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $matches = retrieve('confidential complaint head teacher');

    expect(collect($matches)->pluck('content')->implode(' '))->not->toContain('confidential');
});

it('is not starved by withdrawn articles whose vectors have not been swept yet', function (): void {
    // Eight articles withdrawn by a bulk update: no model event, so their
    // vectors are still in the table and *nearer* the query than the target's.
    foreach (range(1, 8) as $i) {
        $withdrawn = Fx::published("Zzz spare part {$i}", 'zzz spare part zzz');
        AiKnowledgeArticle::query()->whereKey($withdrawn->id)->update(['status' => 'draft']);
    }
    $target = Fx::published('Zzz spare part target', 'zzz spare part zzz target');

    $matches = retrieve('zzz spare part zzz', 1);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->articleUuid)->toBe($target->uuid);
});

it('returns nothing for an empty question, without calling the provider', function (): void {
    $calls = count($this->seen);

    expect(retrieve('   '))->toBe([])
        ->and(count($this->seen))->toBe($calls);
});

it('returns nothing while the assistant is switched off', function (): void {
    SystemSetting::factory()->create(['group' => 'ai', 'key' => 'ai.assistant_enabled', 'value' => false, 'type' => 'boolean']);

    expect(retrieve('projector'))->toBe([]);
});

it('returns nothing when no embedding model is configured', function (): void {
    DB::table('ai_system_settings')->update(['embedding_model_id' => null]);

    expect(retrieve('projector'))->toBe([]);
});

it('surfaces a missing key as an unavailable error rather than an empty answer', function (): void {
    config(['ai.providers.gemini.key' => null]);

    expect(fn () => retrieve('projector'))->toThrow(AiUnavailableException::class);
});

it('rejects a query embedding of the wrong width instead of searching with it', function (): void {
    Embeddings::fake(fn () => [[0.1, 0.2]]);

    expect(fn () => retrieve('projector'))->toThrow(AiProviderException::class, 'requires 768');
});

it('passes the query to the provider once, as one input', function (): void {
    $before = count($this->seen);

    retrieve('projector shows no light');

    expect(array_slice($this->seen, $before))->toBe(['projector shows no light']);
});

it('is shaped so the HNSW cosine index can serve the nearest-neighbour scan', function (): void {
    // Whether the planner *chooses* the index on a handful of rows is its own
    // business. What must hold is that the scan is `ORDER BY embedding <=> $q
    // LIMIT n` straight on the table, so the index *can* be used. With
    // sequential scans and explicit sorts ruled out, the only way left to
    // produce that order is the HNSW index — so the plan naming it proves the
    // shape, and would stop naming it if a join or wrapper were put in the way.
    $literal = '['.implode(',', Fx::embedding('projector')).']';

    DB::transaction(function () use ($literal): void {
        DB::statement('SET LOCAL enable_seqscan = off');
        DB::statement('SET LOCAL enable_sort = off');

        $plan = collect(DB::select(
            "EXPLAIN SELECT embeddable_id FROM ai_embeddings WHERE embeddable_type = 'knowledge_article' AND ai_model_id = ? ORDER BY embedding <=> ?::vector LIMIT 50",
            [$this->model->id, $literal],
        ))->pluck('QUERY PLAN')->implode("\n");

        expect($plan)->toContain('ai_embeddings_embedding_hnsw');
    });
});
