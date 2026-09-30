<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Services\KnowledgeChunker;
use App\Enums\EmbeddingStatus;
use App\Models\AiEmbeddingSource;
use App\Models\AiKnowledgeArticle;
use App\Models\MaintenanceRecord;
use App\Models\SystemSetting;
use App\Models\Ticket;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\KnowledgeIndexFixtures as Fx;

/**
 * WP-P — the index follows the articles: the model observer for every way an
 * article changes, and the `knowledge:index` sweep for everything the observer
 * cannot see.
 *
 * With the `sync` queue driver these run the real job inline, against the
 * deterministic provider, so "saving an article indexes it" is proved end to end.
 */
beforeEach(function (): void {
    $this->model = Fx::configure();
    $this->seen = [];
    Fx::fakeProvider($this->seen);
});

function lifecycleChunks(AiKnowledgeArticle $article): int
{
    return DB::table('ai_embeddings')->where('embeddable_id', $article->id)->count();
}

function lifecycleState(AiKnowledgeArticle $article): ?EmbeddingStatus
{
    return AiEmbeddingSource::query()->where('source_id', $article->id)->first()?->embedding_status;
}

it('indexes an article the moment it is published', function (): void {
    $article = Fx::published('Projector lamp dim', 'Picture is dim.');

    expect(lifecycleChunks($article))->toBe(4)
        ->and(lifecycleState($article))->toBe(EmbeddingStatus::Indexed);
});

it('indexes a draft only once it is published', function (): void {
    $article = AiKnowledgeArticle::factory()->create(['title' => 'Draft topic', 'status' => 'draft']);

    expect(lifecycleChunks($article))->toBe(0)
        ->and(lifecycleState($article))->toBeNull()
        ->and($this->seen)->toBe([]);

    $article->update(['status' => 'published', 'published_at' => now()]);

    expect(lifecycleChunks($article))->toBeGreaterThan(0)
        ->and(lifecycleState($article))->toBe(EmbeddingStatus::Indexed);
});

it('re-indexes on an edit to searchable text, and not at all on an edit to anything else', function (): void {
    $article = Fx::published('Toner low', 'Low toner warning.');
    $calls = count($this->seen);

    $article->update(['verification_count' => 99, 'published_at' => now()->addDay()]);
    expect(count($this->seen))->toBe($calls);

    $article->update(['verified_solution' => 'Order a new cartridge from the approved supplier list.']);
    expect(count($this->seen))->toBeGreaterThan($calls)
        ->and(DB::table('ai_embeddings')->where('embeddable_id', $article->id)->where('content', 'like', '%new cartridge%')->exists())->toBeTrue();
});

it('removes the vectors when an article is unpublished, and restores them when it is republished', function (): void {
    $article = Fx::published('Fan noise', 'Loud fan.');
    expect(lifecycleChunks($article))->toBeGreaterThan(0);

    $article->update(['status' => 'draft']);
    expect(lifecycleChunks($article))->toBe(0)
        ->and(lifecycleState($article))->toBe(EmbeddingStatus::Stale);

    $article->update(['status' => 'published']);
    expect(lifecycleChunks($article))->toBeGreaterThan(0)
        ->and(lifecycleState($article))->toBe(EmbeddingStatus::Indexed);
});

it('removes the vectors on soft delete and brings them back on restore', function (): void {
    $article = Fx::published('Cable fraying', 'Frayed cable.');

    $article->delete();
    expect(lifecycleChunks($article))->toBe(0);

    $article->restore();
    expect(lifecycleChunks($article))->toBeGreaterThan(0)
        ->and(lifecycleState($article))->toBe(EmbeddingStatus::Indexed);
});

it('does not index a restored article that is still a draft', function (): void {
    $article = AiKnowledgeArticle::factory()->create(['status' => 'draft']);
    $article->delete();
    $article->restore();

    expect(lifecycleChunks($article))->toBe(0);
});

it('purges the source row as well on a permanent delete', function (): void {
    $article = Fx::published('Gone for good', 'Removed.');
    $id = $article->id;

    $article->forceDelete();

    expect(DB::table('ai_embeddings')->where('embeddable_id', $id)->count())->toBe(0)
        ->and(AiEmbeddingSource::query()->where('source_id', $id)->count())->toBe(0);
});

it('never breaks the save that triggered it, even when the provider is down', function (): void {
    Fx::failingProvider();

    $article = Fx::published('Provider down', 'Nothing works.');

    expect($article->exists)->toBeTrue()
        ->and(AiKnowledgeArticle::query()->whereKey($article->id)->exists())->toBeTrue()
        ->and(lifecycleChunks($article))->toBe(0)
        // Not indexed and not lost: the sweep will pick it up.
        ->and(lifecycleState($article))->not->toBe(EmbeddingStatus::Indexed);
});

it('marks an edit as stale, not indexed, when nothing can run (no key), and queues no job', function (): void {
    $article = Fx::published('Needs a key', 'Original text.');
    expect(lifecycleState($article))->toBe(EmbeddingStatus::Indexed);

    config(['ai.providers.gemini.key' => null]);
    Queue::fake();
    $article->update(['verified_solution' => 'Edited while no key was configured.']);

    expect(lifecycleState($article))->toBe(EmbeddingStatus::Stale)
        ->and(lifecycleChunks($article))->toBeGreaterThan(0); // old vectors remain, but stale ones are never retrieved
    Queue::assertNothingPushed();
});

it('records nothing and queues nothing when no embedding model is configured at all', function (): void {
    DB::table('ai_system_settings')->update(['embedding_model_id' => null]);
    Queue::fake();

    $article = Fx::published('No model', 'Unconfigured.');

    expect(AiEmbeddingSource::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

describe('knowledge:index sweep', function (): void {
    beforeEach(function (): void {
        // Build the "before" state without the observer, as an install that
        // pre-dates the index would have it.
        $this->quiet = function (array $attributes = []): AiKnowledgeArticle {
            return AiKnowledgeArticle::withoutEvents(fn () => Fx::published(
                $attributes['title'] ?? 'Quiet article',
                $attributes['problem'] ?? 'Quiet problem.',
            ));
        };
    });

    it('indexes published articles that were never indexed, and skips drafts', function (): void {
        $published = ($this->quiet)(['title' => 'Old published']);
        $draft = AiKnowledgeArticle::withoutEvents(fn () => AiKnowledgeArticle::factory()->create(['status' => 'draft']));

        $this->artisan('knowledge:index')->assertSuccessful();

        expect(lifecycleState($published))->toBe(EmbeddingStatus::Indexed)
            ->and(lifecycleChunks($draft))->toBe(0)
            ->and(lifecycleState($draft))->toBeNull();
    });

    it('is idempotent: a second run spends nothing', function (): void {
        ($this->quiet)();
        $this->artisan('knowledge:index')->assertSuccessful();
        $calls = count($this->seen);

        $this->artisan('knowledge:index')->assertSuccessful();

        expect(count($this->seen))->toBe($calls);
    });

    it('marks an indexed article stale and re-indexes it when its content moved on without the observer', function (): void {
        $article = ($this->quiet)();
        $this->artisan('knowledge:index');
        AiKnowledgeArticle::query()->whereKey($article->id)->update(['verified_solution' => 'Bulk-updated fix.']);

        $this->artisan('knowledge:index')->assertSuccessful();

        expect(lifecycleState($article))->toBe(EmbeddingStatus::Indexed)
            ->and(DB::table('ai_embeddings')->where('embeddable_id', $article->id)->where('content', 'like', '%Bulk-updated%')->exists())->toBeTrue();
    });

    it('retires the vectors of an article withdrawn by a bulk update', function (): void {
        $article = ($this->quiet)();
        $this->artisan('knowledge:index');
        AiKnowledgeArticle::query()->whereKey($article->id)->update(['status' => 'archived']);

        $this->artisan('knowledge:index')->assertSuccessful();

        expect(lifecycleChunks($article))->toBe(0)
            ->and(lifecycleState($article))->toBe(EmbeddingStatus::Stale);
    });

    it('re-queues a worker that died mid-run, but not one that started a minute ago', function (): void {
        $recent = ($this->quiet)(['title' => 'Recent']);
        $dead = ($this->quiet)(['title' => 'Dead']);
        foreach ([$recent, $dead] as $article) {
            AiEmbeddingSource::query()->create(['source_type' => 'knowledge_article', 'source_id' => $article->id, 'ai_model_id' => $this->model->id, 'embedding_status' => 'processing', 'chunk_count' => 0]);
        }
        AiEmbeddingSource::query()->where('source_id', $dead->id)->update(['updated_at' => now()->subHours(3)]);

        $this->artisan('knowledge:index')->assertSuccessful();

        expect(lifecycleState($dead))->toBe(EmbeddingStatus::Indexed)
            ->and(lifecycleState($recent))->toBe(EmbeddingStatus::Processing);
    });

    it('leaves a failed article alone unless asked, and retries it with --retry-failed', function (): void {
        $article = ($this->quiet)();
        AiEmbeddingSource::query()->create(['source_type' => 'knowledge_article', 'source_id' => $article->id, 'ai_model_id' => $this->model->id, 'embedding_status' => 'failed', 'chunk_count' => 0, 'last_error' => 'x']);

        $this->artisan('knowledge:index')->assertSuccessful();
        expect(lifecycleState($article))->toBe(EmbeddingStatus::Failed);

        $this->artisan('knowledge:index --retry-failed')->assertSuccessful();
        expect(lifecycleState($article))->toBe(EmbeddingStatus::Indexed);
    });

    it('re-indexes a failed article when it is edited — the old error described another version', function (): void {
        $article = Fx::published('Was failing', 'First version.');
        AiEmbeddingSource::query()->where('source_id', $article->id)
            ->update(['embedding_status' => 'failed', 'last_error' => 'The AI provider took too long to respond.']);

        $article->update(['verified_solution' => 'Second version of the fix.']);

        expect(lifecycleState($article))->toBe(EmbeddingStatus::Indexed)
            ->and(AiEmbeddingSource::query()->where('source_id', $article->id)->value('last_error'))->toBeNull();
    });

    it('reconciles state but queues nothing while no key is configured', function (): void {
        $article = ($this->quiet)();
        config(['ai.providers.gemini.key' => null]);
        Queue::fake();

        $this->artisan('knowledge:index')->assertSuccessful();

        expect(lifecycleState($article))->toBe(EmbeddingStatus::Pending);
        Queue::assertNothingPushed();
    });

    it('does nothing, and says so, when no embedding model is configured', function (): void {
        ($this->quiet)();
        DB::table('ai_system_settings')->update(['embedding_model_id' => null]);

        $this->artisan('knowledge:index')->expectsOutputToContain('No embedding model')->assertSuccessful();

        expect(AiEmbeddingSource::query()->count())->toBe(0);
    });

    it('does not index while the assistant is switched off, and resumes when it is back on', function (): void {
        $article = ($this->quiet)();
        SystemSetting::factory()->create(['group' => 'ai', 'key' => 'ai.assistant_enabled', 'value' => false, 'type' => 'boolean']);

        $this->artisan('knowledge:index')->assertSuccessful();
        expect(lifecycleChunks($article))->toBe(0)
            ->and($this->seen)->toBe([]);

        SystemSetting::query()->where('key', 'ai.assistant_enabled')->update(['value' => true]);
        $this->artisan('knowledge:index')->assertSuccessful();

        expect(lifecycleState($article))->toBe(EmbeddingStatus::Indexed);
    });

    it('re-indexes everything when the chunker version changes, because the version is part of the hash', function (): void {
        $article = ($this->quiet)();
        $this->artisan('knowledge:index');
        // Simulate an index written by an older chunker.
        AiEmbeddingSource::query()->where('source_id', $article->id)->update(['content_hash' => 'written-by-chunker-v0']);
        $calls = count($this->seen);

        $this->artisan('knowledge:index')->assertSuccessful();

        expect(count($this->seen))->toBeGreaterThan($calls)
            ->and(AiEmbeddingSource::query()->where('source_id', $article->id)->value('content_hash'))
            ->toBe(app(KnowledgeChunker::class)->hash($article));
    });

    it('is registered and scheduled', function (): void {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'knowledge:index'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->withoutOverlapping)->toBeTrue()
            ->and($events->first()->onOneServer)->toBeTrue();
    });
});

it('only ever writes knowledge_article rows — transactional records are never put in the shared index', function (): void {
    // Source B: a ticket and a maintenance record exist, and the sweep and the
    // observers have run. None of it may appear in the vector index.
    Ticket::factory()->create();
    MaintenanceRecord::factory()->create();
    Fx::published('Only source A', 'Knowledge.');
    $this->artisan('knowledge:index')->assertSuccessful();

    expect(DB::table('ai_embeddings')->distinct()->pluck('embeddable_type')->all())->toBe(['knowledge_article'])
        ->and(AiEmbeddingSource::query()->distinct()->pluck('source_type')->map->value->all())->toBe(['knowledge_article']);
});
