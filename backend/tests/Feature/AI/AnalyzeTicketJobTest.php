<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Agents\TicketPreScreeningAgent;
use App\Domains\KnowledgeBase\Jobs\AnalyzeTicketJob;
use App\Domains\KnowledgeBase\Listeners\AnalyzeTicketOnCreated;
use App\Domains\Tickets\Actions\CreateTicket;
use App\Domains\Tickets\Events\TicketCreated;
use App\Enums\ActivityAction;
use App\Enums\AiModality;
use App\Enums\MaintenanceStatus;
use App\Models\ActivityLog;
use App\Models\AiAnalysisLog;
use App\Models\AiModel;
use App\Models\AiSystemSetting;
use App\Models\PcSpecification;
use App\Models\PcUnit;
use App\Models\Ticket;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

/**
 * WP-I — TicketCreated -> AnalyzeTicketJob -> ai_analysis_logs -> ordered
 * ai_recommendations -> the ticket AI snapshot. Every AI call in this file
 * goes through `laravel/ai`'s own fake gateway (TicketPreScreeningAgent::fake());
 * nothing here makes a network call.
 */
beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);
    $this->seed(MaintenanceTypeSeeder::class);

    $this->teacher = userWithRole('teacher');

    $this->aiModel = AiModel::factory()->create([
        'provider' => 'gemini',
        'model_identifier' => 'gemini-1.5-flash',
        'modality' => AiModality::Text->value,
    ]);
    AiSystemSetting::factory()->create(['active_model_id' => $this->aiModel->id]);
    config(['ai.providers.gemini.key' => 'test-key']);
});

function successfulAnalysis(array $overrides = []): array
{
    return [
        'problem_category' => 'Hardware — Display',
        'severity' => 'medium',
        'estimated_resolution_minutes' => 45,
        'technician_required' => true,
        'confidence' => 0.82,
        'summary' => 'The reported monitor does not power on; likely a cable or PSU fault.',
        'recommendations' => [
            'Check the monitor power cable is seated at both ends.',
            'Try a known-working outlet.',
            'Swap the VGA/HDMI cable to rule out a display cable fault.',
        ],
        ...$overrides,
    ];
}

/** Runs the job exactly as the queue worker would: method injection, no manual wiring. */
function runAnalysis(Ticket $ticket): void
{
    app()->call([new AnalyzeTicketJob($ticket), 'handle']);
}

/* ------------------------------------------------------------- the seam */

it('dispatches the job only after the enclosing transaction commits', function () {
    Queue::fake();
    $ticket = ticketFor($this->teacher);

    DB::transaction(function () use ($ticket) {
        (new AnalyzeTicketOnCreated)->handle(new TicketCreated($ticket));

        Queue::assertNotPushed(AnalyzeTicketJob::class);
    });

    Queue::assertPushed(AnalyzeTicketJob::class, fn (AnalyzeTicketJob $job): bool => $job->ticket->is($ticket));
});

it('never dispatches the job for a ticket creation that rolls back', function () {
    Queue::fake();
    $ticket = ticketFor($this->teacher);

    try {
        DB::transaction(function () use ($ticket) {
            (new AnalyzeTicketOnCreated)->handle(new TicketCreated($ticket));

            throw new RuntimeException('simulated rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    Queue::assertNotPushed(AnalyzeTicketJob::class);
});

it('is actually wired to real ticket creation end to end', function () {
    Queue::fake();

    $ticket = app(CreateTicket::class)->handle([
        'title' => 'Projector will not turn on',
        'description' => 'Pressed the power button several times, no response, no lights.',
        'category' => 'hardware',
    ], $this->teacher, Request::create('/'));

    Queue::assertPushed(AnalyzeTicketJob::class, fn (AnalyzeTicketJob $job): bool => $job->ticket->is($ticket));
});

/* --------------------------------------------------------- the pipeline */

it('persists the analysis log, ordered recommendations, the ticket snapshot, and an audit entry', function () {
    $pcUnit = PcUnit::factory()->create();
    PcSpecification::factory()->for($pcUnit)->create(['cpu' => 'Intel i5-8500', 'ram' => '8GB']);
    $ticket = ticketFor($this->teacher, 'open', ['pc_unit_id' => $pcUnit->id]);

    TicketPreScreeningAgent::fake([successfulAnalysis()]);

    runAnalysis($ticket);

    $log = AiAnalysisLog::query()->where('ticket_id', $ticket->id)->firstOrFail();

    expect($log->ai_model_id)->toBe($this->aiModel->id)
        ->and($log->problem_category)->toBe('Hardware — Display')
        ->and($log->severity->value)->toBe('medium')
        ->and($log->estimated_resolution_minutes)->toBe(45)
        ->and($log->technician_required)->toBeTrue()
        ->and((float) $log->confidence_score)->toBe(0.82)
        ->and($log->summary)->toContain('monitor does not power on');

    $recommendations = $log->recommendations()->orderBy('step_order')->get();
    expect($recommendations)->toHaveCount(3)
        ->and($recommendations[0]->step_order)->toBe(1)
        ->and($recommendations[0]->recommendation)->toBe('Check the monitor power cable is seated at both ends.')
        ->and($recommendations[2]->step_order)->toBe(3);

    $ticket->refresh();
    expect($ticket->ai_summary)->toBe($log->summary)
        ->and((float) $ticket->ai_confidence)->toBe(0.82)
        ->and($ticket->estimated_resolution_minutes)->toBe(45)
        ->and($ticket->technician_required)->toBeTrue();

    assertDatabaseHas(ActivityLog::class, [
        'action' => ActivityAction::TicketAiAnalyzed->value,
        'subject_type' => $ticket->getMorphClass(),
        'subject_id' => $ticket->id,
    ]);

    $entry = ActivityLog::query()->where('action', ActivityAction::TicketAiAnalyzed->value)->firstOrFail();
    expect($entry->properties)->not->toHaveKey('summary')
        ->and($entry->properties)->not->toHaveKey('recommendations');
});

it('includes real PC specification and maintenance history in the assembled context, never fabricated data', function () {
    $pcUnit = PcUnit::factory()->create();
    PcSpecification::factory()->for($pcUnit)->create(['cpu' => 'Ryzen 5 5600G', 'ram' => '16GB']);
    maintenanceFor(userWithRole('technician'), 'corrective', [
        'pc_unit_id' => $pcUnit->id,
        'status' => MaintenanceStatus::Completed->value,
        'diagnosis' => 'Dust-clogged heatsink causing thermal shutdown.',
        'resolution' => 'Cleaned heatsink and reapplied thermal paste.',
        'maintenance_date' => now()->subWeek(),
    ]);
    $ticket = ticketFor($this->teacher, 'open', ['pc_unit_id' => $pcUnit->id]);

    $captured = null;
    TicketPreScreeningAgent::fake(function (string $prompt) use (&$captured): array {
        $captured = $prompt;

        return successfulAnalysis();
    });

    runAnalysis($ticket);

    expect($captured)
        ->toContain('Ryzen 5 5600G')
        ->toContain('16GB')
        ->toContain('Dust-clogged heatsink causing thermal shutdown.')
        ->toContain('Cleaned heatsink and reapplied thermal paste.')
        ->toContain('<sccit-untrusted-data label="reported fault">')
        ->toContain('<sccit-untrusted-data label="PC specification">')
        ->toContain('<sccit-untrusted-data label="past maintenance on this machine">');
});

it('proceeds with a minimal context when the ticket names no PC unit, rather than inventing one', function () {
    $ticket = ticketFor($this->teacher, 'open', ['pc_unit_id' => null]);

    $captured = null;
    TicketPreScreeningAgent::fake(function (string $prompt) use (&$captured): array {
        $captured = $prompt;

        return successfulAnalysis();
    });

    runAnalysis($ticket);

    expect($captured)
        ->toContain('<sccit-untrusted-data label="reported fault">')
        ->not->toContain('PC specification')
        ->not->toContain('past maintenance');

    assertDatabaseCount(AiAnalysisLog::class, 1);
});

/* ------------------------------------------------------ failure handling */

it('completes without writing anything when the AI provider is not configured', function () {
    config(['ai.providers.gemini.key' => null]);
    $ticket = ticketFor($this->teacher);

    runAnalysis($ticket);

    assertDatabaseCount(AiAnalysisLog::class, 0);
    $ticket->refresh();
    expect($ticket->ai_summary)->toBeNull();
});

it('completes without writing anything when the AI returns malformed structured output', function () {
    $ticket = ticketFor($this->teacher);
    TicketPreScreeningAgent::fake([
        successfulAnalysis(['severity' => 'catastrophic']), // not one of the four recognized values
    ]);

    runAnalysis($ticket);

    assertDatabaseCount(AiAnalysisLog::class, 0);
});

it('completes without writing anything when the AI returns no recommendations at all', function () {
    $ticket = ticketFor($this->teacher);
    TicketPreScreeningAgent::fake([
        successfulAnalysis(['recommendations' => []]),
    ]);

    runAnalysis($ticket);

    assertDatabaseCount(AiAnalysisLog::class, 0);
});
