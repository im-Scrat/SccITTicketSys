<?php

declare(strict_types=1);

use App\Enums\EmbeddingStatus;
use App\Models\AiEmbeddingSource;
use App\Models\AiKnowledgeArticle;
use Illuminate\Support\Facades\DB;
use Tests\Support\KnowledgeIndexFixtures as Fx;

/**
 * The knowledge base (SRS FR-AI-005): who may read, write and publish, and that
 * publishing and withdrawing keep the retrieval index in step.
 *
 * Per CLAUDE.md §6: the row-scoped rule is asserted both ways — an article
 * absent from a user's list is equally unreachable by direct uuid.
 */
beforeEach(function () {
    seedRbac();
    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
});

function knowledgeArticle(array $overrides = []): AiKnowledgeArticle
{
    return AiKnowledgeArticle::factory()->published()->create([
        'title' => 'Printer shows paper jam',
        'category' => 'peripheral',
        'problem_signature' => 'The printer reports a paper jam but no paper is stuck.',
        'root_cause' => 'Dirty paper sensor.',
        'verified_solution' => 'Open the rear door, clear any paper, and wipe the sensor with a dry cloth.',
        ...$overrides,
    ]);
}

/* ------------------------------------------------------------ visibility */

it('shows teachers published articles only, in the list and by direct uuid', function () {
    $published = knowledgeArticle(['title' => 'Published one']);
    $draft = knowledgeArticle(['title' => 'Draft one', 'status' => 'draft', 'published_at' => null]);
    $archived = knowledgeArticle(['title' => 'Archived one', 'status' => 'archived']);

    $titles = collect($this->actingAs($this->teacher)->getJson('/api/knowledge')->assertOk()->json('data'))->pluck('title');
    expect($titles)->toContain('Published one')->not->toContain('Draft one')->not->toContain('Archived one');

    $this->actingAs($this->teacher)->getJson("/api/knowledge/{$published->uuid}")->assertOk();
    $this->actingAs($this->teacher)->getJson("/api/knowledge/{$draft->uuid}")->assertForbidden();
    $this->actingAs($this->teacher)->getJson("/api/knowledge/{$archived->uuid}")->assertForbidden();
});

it('lets a technician see published articles and only their own drafts', function () {
    $mine = knowledgeArticle(['title' => 'My draft', 'status' => 'draft', 'created_by' => $this->technician->id, 'published_at' => null]);
    $theirs = knowledgeArticle(['title' => 'Their draft', 'status' => 'draft', 'created_by' => $this->otherTechnician->id, 'published_at' => null]);

    $titles = collect($this->actingAs($this->technician)->getJson('/api/knowledge')->json('data'))->pluck('title');
    expect($titles)->toContain('My draft')->not->toContain('Their draft');

    $this->actingAs($this->technician)->getJson("/api/knowledge/{$mine->uuid}")->assertOk();
    $this->actingAs($this->technician)->getJson("/api/knowledge/{$theirs->uuid}")->assertForbidden();
});

it('lets an administrator see every article', function () {
    knowledgeArticle(['title' => 'Draft x', 'status' => 'draft']);
    knowledgeArticle(['title' => 'Archived x', 'status' => 'archived']);

    expect(collect($this->actingAs($this->admin)->getJson('/api/knowledge')->json('data'))->pluck('title'))
        ->toContain('Draft x', 'Archived x');
});

it('requires authentication', function () {
    $this->getJson('/api/knowledge')->assertUnauthorized();
    $this->postJson('/api/knowledge', [])->assertUnauthorized();
});

it('searches by full text', function () {
    knowledgeArticle(['title' => 'Projector has no signal', 'problem_signature' => 'HDMI input shows nothing', 'verified_solution' => 'Select the HDMI input.']);
    knowledgeArticle(['title' => 'Printer shows paper jam']);

    $titles = collect($this->actingAs($this->teacher)->getJson('/api/knowledge?q=projector')->json('data'))->pluck('title');

    expect($titles->all())->toBe(['Projector has no signal']);
});

/* ---------------------------------------------------------- authoring */

it('lets a technician draft but not publish, edit others, archive or delete', function () {
    $response = $this->actingAs($this->technician)->postJson('/api/knowledge', [
        'title' => 'Monitor flickers',
        'verified_solution' => 'Replace the cable.',
    ])->assertCreated();

    $uuid = $response->json('data.id');
    expect($response->json('data.status'))->toBe('draft');

    $this->actingAs($this->technician)->putJson("/api/knowledge/{$uuid}", ['title' => 'Monitor flickers badly'])->assertOk();
    $this->actingAs($this->technician)->postJson("/api/knowledge/{$uuid}/publish")->assertForbidden();
    $this->actingAs($this->technician)->postJson("/api/knowledge/{$uuid}/archive")->assertForbidden();
    $this->actingAs($this->technician)->deleteJson("/api/knowledge/{$uuid}")->assertForbidden();

    $other = knowledgeArticle(['status' => 'draft', 'created_by' => $this->otherTechnician->id]);
    $this->actingAs($this->technician)->putJson("/api/knowledge/{$other->uuid}", ['title' => 'Hijack'])->assertForbidden();
});

it('refuses authoring to teachers', function () {
    $this->actingAs($this->teacher)->postJson('/api/knowledge', ['title' => 'x'])->assertForbidden();
});

it('refuses to publish an article with no solution', function () {
    $draft = knowledgeArticle(['status' => 'draft', 'verified_solution' => null, 'published_at' => null]);

    $this->actingAs($this->admin)->postJson("/api/knowledge/{$draft->uuid}/publish")->assertUnprocessable();
});

it('indexes an article when it is published and withdraws it when archived or deleted', function () {
    $seen = [];
    Fx::configure();
    Fx::fakeProvider($seen);

    $draft = knowledgeArticle(['status' => 'draft', 'published_at' => null]);
    expect(AiEmbeddingSource::query()->where('source_id', $draft->id)->exists())->toBeFalse();

    $this->actingAs($this->admin)->postJson("/api/knowledge/{$draft->uuid}/publish")->assertOk();

    $ledger = AiEmbeddingSource::query()->where('source_type', 'knowledge_article')->where('source_id', $draft->id)->firstOrFail();
    expect($ledger->embedding_status)->toBe(EmbeddingStatus::Indexed)
        ->and(DB::table('ai_embeddings')->where('embeddable_type', 'knowledge_article')->where('embeddable_id', $draft->id)->count())->toBeGreaterThan(0);

    // Archiving retires it: its chunks are gone, so it can no longer be retrieved.
    $this->actingAs($this->admin)->postJson("/api/knowledge/{$draft->uuid}/archive")->assertOk();
    expect(DB::table('ai_embeddings')->where('embeddable_type', 'knowledge_article')->where('embeddable_id', $draft->id)->count())->toBe(0);

    $this->actingAs($this->admin)->postJson("/api/knowledge/{$draft->uuid}/publish")->assertOk();
    expect(DB::table('ai_embeddings')->where('embeddable_type', 'knowledge_article')->where('embeddable_id', $draft->id)->count())->toBeGreaterThan(0);

    $this->actingAs($this->admin)->deleteJson("/api/knowledge/{$draft->uuid}")->assertOk();
    expect(DB::table('ai_embeddings')->where('embeddable_type', 'knowledge_article')->where('embeddable_id', $draft->id)->count())->toBe(0);
});

it('still lets articles be published when AI is not configured, and indexes nothing', function () {
    $draft = knowledgeArticle(['status' => 'draft', 'published_at' => null]);

    $this->actingAs($this->admin)->postJson("/api/knowledge/{$draft->uuid}/publish")->assertOk();

    expect($draft->refresh()->status->value)->toBe('published')
        ->and(DB::table('ai_embeddings')->count())->toBe(0);
});
