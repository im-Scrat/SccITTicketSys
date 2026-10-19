<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Agents\TicketPreScreeningAgent;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Jobs\AnalyzePcHistoryJob;
use App\Domains\KnowledgeBase\Jobs\AnalyzeTicketJob;
use App\Domains\KnowledgeBase\Jobs\GenerateEmbeddingJob;
use App\Domains\KnowledgeBase\Services\GeminiGovernor;
use App\Domains\KnowledgeBase\Services\SafeAgentInvoker;
use App\Enums\AiModality;
use App\Models\AiAnalysisLog;
use App\Models\AiModel;
use App\Models\AiRecommendation;
use App\Models\AiSystemSetting;
use App\Models\PcUnit;
use App\Models\Ticket;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakeTestAgent;

/*
 * Phase 2.9 — many tickets analysed at once, safely.
 *
 * Ticket A, B, C and D each get their own job on the `ai` queue; up to the
 * governor's concurrency cap they run in parallel, and beyond it — or past the
 * per-minute budget — a call is refused as "busy" and the job releases itself
 * to try again later, rather than dropping the analysis. A job that runs twice
 * still writes exactly one analysis.
 *
 * The governor is off by default under test (phpunit.xml); tests that exercise
 * it switch it on with config(). The concurrency ceiling uses the gate's real
 * Redis, exactly as production does.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);
    $this->seed(MaintenanceTypeSeeder::class);

    $this->teacher = userWithRole('teacher');

    $model = AiModel::factory()->create([
        'provider' => 'gemini',
        'model_identifier' => 'gemini-1.5-flash',
        'modality' => AiModality::Text->value,
    ]);
    AiSystemSetting::factory()->create(['active_model_id' => $model->id]);
    config(['ai.providers.gemini.key' => 'test-key']);

    RateLimiter::clear('ai:gemini:rpm');
});

/** @return array<string, mixed> */
function concurrencyAnalysis(string $summary): array
{
    return [
        'problem_category' => 'Hardware — Display',
        'severity' => 'medium',
        'estimated_resolution_minutes' => 30,
        'technician_required' => false,
        'confidence' => 0.8,
        'summary' => $summary,
        'recommendations' => ['Reseat the display cable.', 'Try another outlet.'],
    ];
}

function analyse(Ticket $ticket): AnalyzeTicketJob
{
    $job = new AnalyzeTicketJob($ticket);
    $job->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

function rateLimitedResponse(): RequestException
{
    return new RequestException(new Response(new Psr7Response(429)));
}

/* ------------------------------------------------------------- the job */

it('runs ticket analysis on the dedicated ai queue', function () {
    expect((new AnalyzeTicketJob(ticketFor($this->teacher)))->queue)->toBe('ai');
});

it('puts every Gemini-calling job on the ai queue', function () {
    expect((new GenerateEmbeddingJob(1))->queue)->toBe('ai')
        ->and((new AnalyzePcHistoryJob(PcUnit::factory()->create()))->queue)->toBe('ai');
});

it('queues one job per ticket even when dispatched twice', function () {
    Queue::fake();
    $ticket = ticketFor($this->teacher);

    AnalyzeTicketJob::dispatch($ticket);
    AnalyzeTicketJob::dispatch($ticket);

    Queue::assertPushed(AnalyzeTicketJob::class, 1);
});

it('queues independent jobs for four tickets reported at the same time', function () {
    Queue::fake();
    $tickets = collect(range(1, 4))->map(fn () => ticketFor(userWithRole('teacher')));

    $tickets->each(fn (Ticket $ticket) => AnalyzeTicketJob::dispatch($ticket));

    Queue::assertPushed(AnalyzeTicketJob::class, 4);
    foreach ($tickets as $ticket) {
        Queue::assertPushed(AnalyzeTicketJob::class, fn (AnalyzeTicketJob $job): bool => $job->ticket->is($ticket));
    }
});

it('gives each of four tickets its own analysis and nothing of another’s', function () {
    $tickets = collect(['A', 'B', 'C', 'D'])->mapWithKeys(fn (string $label) => [
        $label => ticketFor(userWithRole('teacher'), 'open', ['title' => "Fault {$label}"]),
    ]);
    // Each answer names its own ticket, so a crossed write would be visible.
    TicketPreScreeningAgent::fake(function (string $prompt): array {
        preg_match('/Fault ([ABCD])/', $prompt, $m);

        return concurrencyAnalysis('Summary for '.($m[1] ?? '?'));
    });

    $tickets->each(fn (Ticket $ticket) => analyse($ticket));

    expect(AiAnalysisLog::query()->count())->toBe(4);
    foreach ($tickets as $label => $ticket) {
        $log = AiAnalysisLog::query()->where('ticket_id', $ticket->id)->sole();
        expect($log->summary)->toBe("Summary for {$label}")
            ->and($log->analysis_key)->toBe("ticket:{$ticket->id}:created")
            ->and($ticket->refresh()->ai_summary)->toBe("Summary for {$label}");
    }
});

it('writes exactly one analysis when the same ticket’s job runs twice', function () {
    $ticket = ticketFor($this->teacher);
    TicketPreScreeningAgent::fake([concurrencyAnalysis('first'), concurrencyAnalysis('second')]);

    analyse($ticket);
    analyse($ticket);

    expect(AiAnalysisLog::query()->where('ticket_id', $ticket->id)->count())->toBe(1)
        ->and(AiRecommendation::query()->count())->toBe(2);
});

it('does not call the provider again for a ticket that is already analysed', function () {
    $ticket = ticketFor($this->teacher);
    AiAnalysisLog::factory()->create([
        'ticket_id' => $ticket->id,
        'analysis_key' => AnalyzeTicketJob::analysisKeyFor($ticket),
    ]);
    TicketPreScreeningAgent::fake([concurrencyAnalysis('never')]);

    analyse($ticket);

    TicketPreScreeningAgent::assertNeverPrompted();
});

it('lets the database refuse a second on-creation analysis for one ticket', function () {
    $ticket = ticketFor($this->teacher);
    $key = AnalyzeTicketJob::analysisKeyFor($ticket);
    AiAnalysisLog::factory()->create(['ticket_id' => $ticket->id, 'analysis_key' => $key]);

    expect(fn () => AiAnalysisLog::factory()->create(['ticket_id' => $ticket->id, 'analysis_key' => $key]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('releases the job to retry later when the provider rate-limits, instead of dropping it', function () {
    $ticket = ticketFor($this->teacher);
    TicketPreScreeningAgent::fake(fn () => throw rateLimitedResponse());

    $job = analyse($ticket);

    $job->assertReleased(delay: 15);
    expect(AiAnalysisLog::query()->count())->toBe(0);
});

it('releases the job when the governor has no capacity', function () {
    config(['ai.sccit.gemini_rpm' => 1, 'ai.sccit.gemini_concurrency' => 0]);
    RateLimiter::hit('ai:gemini:rpm', 60);
    $ticket = ticketFor($this->teacher);
    TicketPreScreeningAgent::fake([concurrencyAnalysis('unused')]);

    $job = analyse($ticket);

    $job->assertReleased();
    TicketPreScreeningAgent::assertNeverPrompted();
});

it('ends without retrying when the answer is malformed — a retry cannot fix it', function () {
    $ticket = ticketFor($this->teacher);
    TicketPreScreeningAgent::fake([[]]);

    $job = analyse($ticket);

    $job->assertNotReleased();
    expect(AiAnalysisLog::query()->count())->toBe(0);
});

it('bounds the retry effort in time and in genuine failures', function () {
    $job = new AnalyzeTicketJob(ticketFor($this->teacher));

    expect($job->retryUntil()->getTimestamp())->toBeLessThanOrEqual(now()->addMinutes(31)->getTimestamp())
        ->and($job->maxExceptions)->toBe(3)
        // The worker's --timeout and the connection's retry_after sit above this.
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'));
});

/* -------------------------------------------------------- the invoker */

it('does not burn quota retrying a 429 inside one invocation', function () {
    $calls = 0;
    FakeTestAgent::fake(function () use (&$calls) {
        $calls++;

        throw rateLimitedResponse();
    });

    expect(fn () => app(SafeAgentInvoker::class)->invoke(new FakeTestAgent, 'prompt'))
        ->toThrow(AiProviderException::class);
    expect($calls)->toBe(1);
});

it('marks a provider rate limit as transient so callers back off', function () {
    FakeTestAgent::fake(fn () => throw rateLimitedResponse());

    try {
        app(SafeAgentInvoker::class)->invoke(new FakeTestAgent, 'prompt');
        $this->fail('Expected a provider exception.');
    } catch (AiProviderException $e) {
        expect($e->isTransient())->toBeTrue();
    }
});

/* -------------------------------------------------------- the governor */

it('refuses calls beyond the per-minute budget as busy, without calling out', function () {
    config(['ai.sccit.gemini_rpm' => 2, 'ai.sccit.gemini_concurrency' => 0]);
    $governor = app(GeminiGovernor::class);
    $calls = 0;

    $governor->run(function () use (&$calls) {
        $calls++;
    });
    $governor->run(function () use (&$calls) {
        $calls++;
    });

    expect(fn () => $governor->run(function () use (&$calls) {
        $calls++;
    }))
        ->toThrow(AiProviderException::class, 'busy');
    expect($calls)->toBe(2);
});

it('caps how many calls are in flight at once across processes', function () {
    config(['ai.sccit.gemini_rpm' => 0, 'ai.sccit.gemini_concurrency' => 1, 'ai.sccit.gemini_slot_wait_seconds' => 0]);
    $governor = app(GeminiGovernor::class);
    $inner = null;

    // While one call holds the only slot, a second (as from another worker) is refused.
    $governor->run(function () use ($governor, &$inner) {
        try {
            $governor->run(fn () => null);
            $inner = 'ran';
        } catch (AiProviderException $e) {
            $inner = $e->getMessage();
        }
    });

    expect($inner)->toContain('busy');

    // And the slot is released afterwards: the next call runs.
    expect($governor->run(fn () => 'ok'))->toBe('ok');
});

it('is a no-op when both ceilings are disabled', function () {
    config(['ai.sccit.gemini_rpm' => 0, 'ai.sccit.gemini_concurrency' => 0]);

    expect(app(GeminiGovernor::class)->run(fn () => 'direct'))->toBe('direct');
});
