<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Jobs\GenerateEmbeddingJob;
use App\Domains\KnowledgeBase\Services\KnowledgeChunker;
use App\Enums\EmbeddingStatus;
use App\Enums\KnowledgeStatus;
use App\Models\AiEmbeddingSource;
use App\Models\AiKnowledgeArticle;
use App\Models\AiModel;
use App\Models\SystemSetting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Tests\Support\KnowledgeIndexFixtures as Fx;

/**
 * WP-P — {@see GenerateEmbeddingJob}: chunk, embed, store, and every way that
 * can go wrong.
 *
 * The queue is faked so creating an article does not index it behind the test's
 * back (under the `sync` driver the observer would); each test runs the job
 * explicitly, as a worker would, against a deterministic provider — no network,
 * no key.
 */
beforeEach(function (): void {
    Queue::fake();
    $this->model = Fx::configure();
});

function chunkRows(AiKnowledgeArticle $article)
{
    return DB::table('ai_embeddings')->where('embeddable_type', 'knowledge_article')
        ->where('embeddable_id', $article->id)->orderBy('chunk_index')->get();
}

function sourceOf(AiKnowledgeArticle $article): ?AiEmbeddingSource
{
    return AiEmbeddingSource::query()->where('source_type', 'knowledge_article')->where('source_id', $article->id)->first();
}

it('indexes a published article: one 768-dimension vector per chunk, hash recorded, state indexed', function (): void {
    $seen = [];
    Fx::fakeProvider($seen);
    $article = Fx::published('Projector will not power on', 'No light and no fan noise.', 'Loose power lead.', 'Reseat the power lead.');

    Fx::index($article);

    $rows = chunkRows($article);
    $source = sourceOf($article);

    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('chunk_index')->all())->toBe([0, 1, 2, 3])
        ->and($rows->every(fn ($r) => $r->ai_model_id === $this->model->id))->toBeTrue()
        ->and($rows[1]->content)->toContain('No light and no fan noise')
        ->and(json_decode($rows[1]->metadata, true))->toBe(['section' => 'Problem'])
        ->and($rows->every(fn ($r) => strlen($r->content_hash) === 64 && $r->token_count > 0))->toBeTrue()
        ->and($source->embedding_status)->toBe(EmbeddingStatus::Indexed)
        ->and($source->chunk_count)->toBe(4)
        ->and($source->content_hash)->toBe(app(KnowledgeChunker::class)->hash($article))
        ->and($source->indexed_at)->not->toBeNull()
        ->and($source->last_error)->toBeNull()
        ->and($seen)->toHaveCount(4);

    // The stored value really is a 768-wide pgvector, not just a string that fits.
    expect(DB::selectOne('SELECT DISTINCT vector_dims(embedding) AS d FROM ai_embeddings WHERE embeddable_id = ?', [$article->id])->d)->toBe(768);
});

it('does nothing the second time when the content has not changed (idempotent, no provider call)', function (): void {
    $seen = [];
    Fx::fakeProvider($seen);
    $article = Fx::published('Printer offline', 'Shows offline.', 'Spooler stuck.', 'Restart the spooler.');

    Fx::index($article);
    $calls = count($seen);
    $firstIds = chunkRows($article)->pluck('id')->all();

    Fx::index($article);

    expect(count($seen))->toBe($calls)
        ->and(chunkRows($article)->pluck('id')->all())->toBe($firstIds)
        ->and(AiEmbeddingSource::query()->count())->toBe(1);
});

it('replaces every old chunk when the article changes — none of the old text survives', function (): void {
    Fx::fakeProvider();
    $article = Fx::published('Wi-Fi drops', 'Wi-Fi drops every hour.', 'Old access point firmware.', 'Update the firmware. '.str_repeat('Old procedure detail. ', 120));
    Fx::index($article);
    $before = chunkRows($article)->count();
    expect($before)->toBeGreaterThan(4);

    AiKnowledgeArticle::query()->whereKey($article->id)->update(['verified_solution' => 'Replace the access point.']);
    Fx::index($article->id);

    $rows = chunkRows($article);
    expect($rows)->toHaveCount(4)
        ->and($rows->contains(fn ($r) => str_contains($r->content, 'Old procedure detail')))->toBeFalse()
        ->and($rows->contains(fn ($r) => str_contains($r->content, 'Replace the access point')))->toBeTrue()
        ->and(sourceOf($article)->chunk_count)->toBe(4)
        ->and(sourceOf($article)->content_hash)->toBe(app(KnowledgeChunker::class)->hash(AiKnowledgeArticle::find($article->id)));
});

it('never indexes a draft or an archived article, and never calls the provider for one', function (KnowledgeStatus $status): void {
    $seen = [];
    Fx::fakeProvider($seen);
    $article = Fx::published('Keyboard keys stuck', 'Keys stick.', attributes: ['status' => $status->value]);

    Fx::index($article);

    expect(chunkRows($article))->toHaveCount(0)
        ->and($seen)->toBe([])
        ->and(sourceOf($article))->toBeNull();
})->with([KnowledgeStatus::Draft, KnowledgeStatus::Archived]);

it('takes an article out of the index when it stops being published', function (): void {
    Fx::fakeProvider();
    $article = Fx::published('Monitor flickers', 'Screen flickers.');
    Fx::index($article);
    expect(chunkRows($article))->not->toBeEmpty();

    AiKnowledgeArticle::query()->whereKey($article->id)->update(['status' => KnowledgeStatus::Draft->value]);
    Fx::index($article->id);

    expect(chunkRows($article))->toHaveCount(0)
        ->and(sourceOf($article)->embedding_status)->toBe(EmbeddingStatus::Stale)
        ->and(sourceOf($article)->chunk_count)->toBe(0)
        ->and(sourceOf($article)->content_hash)->toBeNull();
});

it('purges every trace of an article that no longer exists', function (): void {
    Fx::fakeProvider();
    $article = Fx::published('Mouse jitter', 'Cursor jumps.');
    Fx::index($article);
    $id = $article->id;
    $article->forceDelete();
    // The observer's purge is faked-queue independent; restore the stale rows to
    // prove the *job's* own purge path handles a missing article.
    DB::table('ai_embeddings')->insert([
        'embeddable_type' => 'knowledge_article', 'embeddable_id' => $id, 'ai_model_id' => $this->model->id,
        'chunk_index' => 0, 'content' => 'orphan', 'content_hash' => str_repeat('a', 64),
        'embedding' => '['.implode(',', Fx::embedding('orphan')).']', 'created_at' => now(), 'updated_at' => now(),
    ]);

    Fx::index($id);

    expect(DB::table('ai_embeddings')->where('embeddable_id', $id)->count())->toBe(0)
        ->and(AiEmbeddingSource::query()->where('source_id', $id)->count())->toBe(0);
});

it('marks the article failed, without calling the provider, when the model is not 768 wide', function (): void {
    AiModel::query()->whereKey($this->model->id)->update(['embedding_dimensions' => 3072]);
    Fx::fakeProvider();
    $article = Fx::published('Docking station dead', 'No power.');

    Fx::index($article);

    Embeddings::assertNothingGenerated();
    expect(chunkRows($article))->toHaveCount(0)
        ->and(sourceOf($article)->embedding_status)->toBe(EmbeddingStatus::Failed)
        ->and(sourceOf($article)->last_error)->toContain('requires 768');
});

it('stores nothing and does not retry when the provider returns the wrong number of dimensions', function (): void {
    Embeddings::fake(fn () => [[0.1, 0.2, 0.3]]);
    $article = Fx::published('Headset silent', 'No sound.');

    Fx::index($article);

    expect(chunkRows($article))->toHaveCount(0)
        ->and(sourceOf($article)->embedding_status)->toBe(EmbeddingStatus::Failed)
        ->and(sourceOf($article)->last_error)->toContain('requires 768');
});

it('rejects a non-finite value at the storage boundary rather than writing it', function (): void {
    Embeddings::fake(fn () => [array_merge([NAN], array_fill(0, 767, 0.1))]);
    $article = Fx::published('Scanner streaks', 'Streaks on scans.');

    Fx::index($article);

    expect(chunkRows($article))->toHaveCount(0)
        ->and(sourceOf($article)->embedding_status)->toBe(EmbeddingStatus::Failed);
});

it('on a provider outage goes back to pending with a safe reason, and rethrows so the queue retries', function (): void {
    Fx::failingProvider();
    $article = Fx::published('Switch port dead', 'Port has no link.');

    expect(fn () => Fx::index($article))->toThrow(AiProviderException::class);

    $source = sourceOf($article);
    expect($source->embedding_status)->toBe(EmbeddingStatus::Pending)
        ->and($source->last_error)->not->toContain('secret-token')
        ->and($source->last_error)->not->toContain('exploded')
        ->and(chunkRows($article))->toHaveCount(0);
});

it('recovers on the retry: the same job succeeds once the provider is back', function (): void {
    Fx::failingProvider();
    $article = Fx::published('UPS beeping', 'Constant beep.');
    try {
        Fx::index($article);
    } catch (Throwable) {
    }
    expect(sourceOf($article)->embedding_status)->toBe(EmbeddingStatus::Pending);

    Fx::fakeProvider();
    Fx::index($article);

    expect(sourceOf($article)->embedding_status)->toBe(EmbeddingStatus::Indexed)
        ->and(sourceOf($article)->last_error)->toBeNull()
        ->and(chunkRows($article))->not->toBeEmpty();
});

it('gives up as failed on the last attempt, with a static message that carries no provider detail', function (): void {
    Fx::failingProvider();
    $article = Fx::published('Cable tester broken', 'No result.');

    $job = new GenerateEmbeddingJob($article->id);
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn($job->tries);
    $queueJob->shouldReceive('fail')->once();
    $job->setJob($queueJob);

    app()->call([$job, 'handle']);

    $source = sourceOf($article);
    expect($source->embedding_status)->toBe(EmbeddingStatus::Failed)
        ->and($source->last_error)->toBe('The AI provider could not complete this request. Please try again shortly.')
        ->and($source->last_error)->not->toContain('secret-token');
});

it('the failed() hook never leaves an article claiming processing or pending, and leaves indexed ones alone', function (): void {
    Fx::fakeProvider();
    $stuck = Fx::published('Stuck one', 'Stuck.');
    $done = Fx::published('Done one', 'Done.');
    Fx::index($done);
    // The observer already opened a pending row for it; put it mid-run.
    AiEmbeddingSource::query()->where('source_id', $stuck->id)->update(['embedding_status' => 'processing']);

    (new GenerateEmbeddingJob($stuck->id))->failed(new RuntimeException('db exploded: password=hunter2'));
    (new GenerateEmbeddingJob($done->id))->failed(new RuntimeException('irrelevant'));

    expect(sourceOf($stuck)->embedding_status)->toBe(EmbeddingStatus::Failed)
        ->and(sourceOf($stuck)->last_error)->not->toContain('hunter2')
        ->and(sourceOf($done)->embedding_status)->toBe(EmbeddingStatus::Indexed);
});

it('discards the result and goes round again when the article is edited while the provider is being called', function (): void {
    $article = Fx::published('Camera blurry', 'Image is blurry.');
    $edited = false;
    Embeddings::fake(function ($prompt) use ($article, &$edited) {
        if (! $edited) {
            $edited = true;
            AiKnowledgeArticle::query()->whereKey($article->id)->update(['verified_solution' => 'A different fix entirely.']);
        }

        return [Fx::embedding($prompt->inputs[0])];
    });
    Queue::fake(); // forget the push made when the article was created

    Fx::index($article);

    expect(chunkRows($article))->toHaveCount(0)
        ->and(sourceOf($article)->embedding_status)->toBe(EmbeddingStatus::Stale);
    Queue::assertPushed(GenerateEmbeddingJob::class, fn ($job) => $job->articleId === $article->id);
});

it('writes nothing when the article is unpublished while the provider is being called', function (): void {
    $article = Fx::published('Speaker crackle', 'Crackling.');
    $withdrawn = false;
    Embeddings::fake(function ($prompt) use ($article, &$withdrawn) {
        if (! $withdrawn) {
            $withdrawn = true;
            AiKnowledgeArticle::query()->whereKey($article->id)->update(['status' => 'draft']);
        }

        return [Fx::embedding($prompt->inputs[0])];
    });
    Queue::fake(); // forget the push made when the article was created

    Fx::index($article);

    expect(chunkRows($article))->toHaveCount(0)
        ->and(sourceOf($article)->embedding_status)->toBe(EmbeddingStatus::Stale);
    Queue::assertNotPushed(GenerateEmbeddingJob::class);
});

it('leaves everything untouched, and spends nothing, while the AI assistant is switched off', function (): void {
    SystemSetting::factory()->create(['group' => 'ai', 'key' => 'ai.assistant_enabled', 'value' => false, 'type' => 'boolean']);
    $seen = [];
    Fx::fakeProvider($seen);
    $article = Fx::published('Laptop battery', 'Drains quickly.');

    Fx::index($article);

    expect($seen)->toBe([])
        ->and(chunkRows($article))->toHaveCount(0)
        // Tracked, not lost: pending, so it resumes when the switch goes back on.
        ->and(sourceOf($article)->embedding_status)->toBe(EmbeddingStatus::Pending);
    Queue::assertNothingPushed();
});

it('is unique per article until it starts, retries with backoff, and has a bounded run time', function (): void {
    $job = new GenerateEmbeddingJob(42);

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([30, 120, 600])
        ->and($job->timeout)->toBeGreaterThan(0);
});
