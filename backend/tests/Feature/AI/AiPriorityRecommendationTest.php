<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Agents\TicketPreScreeningAgent;
use App\Domains\KnowledgeBase\Events\AiAnalysisReady;
use App\Domains\KnowledgeBase\Jobs\AnalyzeTicketJob;
use App\Enums\AiModality;
use App\Enums\TicketPrioritySource;
use App\Enums\TicketUpdateType;
use App\Models\AiAnalysisLog;
use App\Models\AiModel;
use App\Models\AiSystemSetting;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\SystemSetting;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketUpdate;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Support\Facades\Event;

/*
 * Phase 2.9 (owner decision D-B) — the AI *recommends* a priority; plain PHP
 * (PriorityRecommendationPolicy) decides whether it is applied. It may only
 * raise, only above the confidence threshold, only with the administrator's
 * switch on, never over a human's explicit choice, and never on a finished
 * ticket. The recommendation is recorded either way, for administrators.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);
    $this->seed(MaintenanceTypeSeeder::class);

    $this->teacher = userWithRole('teacher');
    $this->admin = userWithRole('administrator');

    $model = AiModel::factory()->create([
        'provider' => 'gemini',
        'model_identifier' => 'gemini-1.5-flash',
        'modality' => AiModality::Text->value,
    ]);
    AiSystemSetting::factory()->create(['active_model_id' => $model->id, 'confidence_threshold' => 0.7]);
    config(['ai.providers.gemini.key' => 'test-key']);
});

function autoRaise(bool $on): void
{
    SystemSetting::query()->where('key', 'ai.priority_auto_raise')->update(['value' => json_encode($on)]);
}

function priorityId(string $slug): int
{
    return (int) TicketPriority::query()->where('slug', $slug)->value('id');
}

/** @return array<string, mixed> */
function recommending(string $priority, float $confidence = 0.9): array
{
    return [
        'problem_category' => 'Hardware — Server',
        'severity' => 'high',
        'estimated_resolution_minutes' => 60,
        'technician_required' => true,
        'confidence' => $confidence,
        'summary' => 'The rack server does not boot.',
        'recommendations' => ['Check the PSU.', 'Reseat the RAM.'],
        'recommended_priority' => $priority,
        'priority_rationale' => 'A server outage stops the whole lab.',
    ];
}

function prescreen(Ticket $ticket): void
{
    $job = new AnalyzeTicketJob($ticket);
    $job->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
}

/** @param array<string, mixed> $overrides */
function lowTicket(mixed $test, array $overrides = []): Ticket
{
    return ticketFor($test->teacher, 'open', [
        'priority_id' => priorityId('low'),
        'priority_source' => TicketPrioritySource::Category->value,
        ...$overrides,
    ]);
}

/* ---------------------------------------------------------- recording */

it('records the recommendation even while auto-raise is off, and changes nothing', function () {
    $ticket = lowTicket($this);
    TicketPreScreeningAgent::fake([recommending('critical')]);

    prescreen($ticket);

    expect(AiAnalysisLog::query()->sole()->recommended_priority_id)->toBe(priorityId('critical'))
        ->and($ticket->refresh()->priority_id)->toBe(priorityId('low'))
        ->and($ticket->priority_source)->toBe(TicketPrioritySource::Category);
});

it('keeps a well-formed analysis when the recommendation itself is invalid', function () {
    $ticket = lowTicket($this);
    TicketPreScreeningAgent::fake([recommending('apocalyptic')]);

    prescreen($ticket);

    expect(AiAnalysisLog::query()->sole()->recommended_priority_id)->toBeNull()
        ->and($ticket->refresh()->ai_summary)->toBe('The rack server does not boot.');
});

/* ------------------------------------------------------------ applying */

it('raises the priority when every rule holds, and moves the SLA clock', function () {
    autoRaise(true);
    $ticket = lowTicket($this);
    TicketPreScreeningAgent::fake([recommending('high')]);

    prescreen($ticket);

    $ticket->refresh();
    expect($ticket->priority_id)->toBe(priorityId('high'))
        ->and($ticket->priority_source)->toBe(TicketPrioritySource::Ai)
        // High resolves in 1440 minutes (seeded), anchored to creation.
        ->and((int) round($ticket->created_at->diffInMinutes($ticket->resolution_due_at)))->toBe(1440);

    $row = TicketUpdate::query()->where('ticket_id', $ticket->id)
        ->where('update_type', TicketUpdateType::PriorityChange->value)->sole();
    expect($row->user_id)->toBeNull()
        ->and($row->metadata['source'])->toBe('ai')
        ->and($row->metadata['to'])->toBe('high');
});

it('does not raise below the confidence threshold', function () {
    autoRaise(true);
    $ticket = lowTicket($this);
    TicketPreScreeningAgent::fake([recommending('critical', 0.5)]);

    prescreen($ticket);

    expect($ticket->refresh()->priority_id)->toBe(priorityId('low'));
});

it('does not raise when no confidence threshold is configured', function () {
    autoRaise(true);
    AiSystemSetting::query()->update(['confidence_threshold' => null]);
    $ticket = lowTicket($this);
    TicketPreScreeningAgent::fake([recommending('critical', 0.99)]);

    prescreen($ticket);

    expect($ticket->refresh()->priority_id)->toBe(priorityId('low'));
});

it('never lowers a priority, so a room’s floor always stands', function () {
    autoRaise(true);
    $ticket = lowTicket($this, [
        'priority_id' => priorityId('high'),
        'priority_source' => TicketPrioritySource::Location->value,
    ]);
    TicketPreScreeningAgent::fake([recommending('low', 0.99)]);

    prescreen($ticket);

    expect($ticket->refresh()->priority_id)->toBe(priorityId('high'))
        ->and($ticket->priority_source)->toBe(TicketPrioritySource::Location);
});

it('never overrides a priority a human chose', function () {
    autoRaise(true);
    $ticket = lowTicket($this, ['priority_source' => TicketPrioritySource::Manual->value]);
    TicketPreScreeningAgent::fake([recommending('critical', 0.99)]);

    prescreen($ticket);

    expect($ticket->refresh()->priority_id)->toBe(priorityId('low'))
        ->and($ticket->priority_source)->toBe(TicketPrioritySource::Manual);
});

it('raises a priority the reporter chose — only IT staff’s choice locks it', function () {
    // Phase 2.9 (SDD DD-75): a reporter's own choice is the second-weakest
    // claim, not a human override.
    autoRaise(true);
    $ticket = lowTicket($this, ['priority_source' => TicketPrioritySource::Reporter->value]);
    TicketPreScreeningAgent::fake([recommending('high')]);

    prescreen($ticket);

    expect($ticket->refresh()->priority_id)->toBe(priorityId('high'))
        ->and($ticket->priority_source)->toBe(TicketPrioritySource::Ai);
});

it('does not touch a ticket that is no longer live', function () {
    autoRaise(true);
    $ticket = ticketFor($this->teacher, 'closed', ['priority_id' => priorityId('low')]);
    TicketPreScreeningAgent::fake([recommending('critical', 0.99)]);

    prescreen($ticket);

    expect($ticket->refresh()->priority_id)->toBe(priorityId('low'));
});

it('sees a human priority change committed after the job was queued', function () {
    autoRaise(true);
    $ticket = lowTicket($this);
    $job = new AnalyzeTicketJob($ticket);
    $job->withFakeQueueInteractions();
    // An administrator sets it by hand between dispatch and execution.
    Ticket::query()->whereKey($ticket->id)->update(['priority_source' => TicketPrioritySource::Manual->value]);
    TicketPreScreeningAgent::fake([recommending('critical', 0.99)]);

    app()->call([$job, 'handle']);

    expect($ticket->refresh()->priority_id)->toBe(priorityId('low'));
});

/* ------------------------------------------------------------- context */

it('tells the model where the fault is, and not who reported it', function () {
    $room = Room::factory()->create(['name' => 'Server Room B', 'priority_floor_id' => priorityId('high')]);
    $pc = PcUnit::factory()->create(['room_id' => $room->id]);
    $ticket = lowTicket($this, ['pc_unit_id' => $pc->id, 'room_id' => $room->id]);
    $prompts = [];
    TicketPreScreeningAgent::fake(function (string $prompt) use (&$prompts): array {
        $prompts[] = $prompt;

        return recommending('high');
    });

    prescreen($ticket);

    expect($prompts[0])->toContain('Server Room B')
        ->and($prompts[0])->toContain('Minimum priority set by the school for this room: High')
        ->and($prompts[0])->not->toContain($this->teacher->email)
        ->and($prompts[0])->not->toContain($this->teacher->fullName());
});

/* ------------------------------------------------------------ exposure */

it('shows the recommendation and its rationale to an administrator only', function () {
    $ticket = lowTicket($this);
    TicketPreScreeningAgent::fake([recommending('critical')]);
    prescreen($ticket);

    $this->actingAs($this->admin)->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")
        ->assertOk()
        ->assertJsonPath('data.priority_recommendation.priority', 'critical')
        ->assertJsonPath('data.priority_recommendation.rationale', 'A server outage stops the whole lab.');

    $teacherView = $this->actingAs($this->teacher)->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")->assertOk();
    expect($teacherView->getContent())->not->toContain('priority_recommendation')
        ->and($teacherView->getContent())->not->toContain('A server outage stops the whole lab.');
});

/* ------------------------------------------------------ realtime signal */

it('tells only the reporter that the analysis is ready', function () {
    Event::fake([AiAnalysisReady::class]);
    $ticket = lowTicket($this);
    TicketPreScreeningAgent::fake([recommending('medium')]);

    prescreen($ticket);

    Event::assertDispatchedTimes(AiAnalysisReady::class, 1);
    Event::assertDispatched(AiAnalysisReady::class, fn (AiAnalysisReady $event): bool => $event->reporterUuid === $this->teacher->uuid && $event->ticketUuid === $ticket->uuid);
});

it('announces nothing when the analysis was not written', function () {
    Event::fake([AiAnalysisReady::class]);
    $ticket = lowTicket($this);
    TicketPreScreeningAgent::fake([['summary' => 'malformed']]);

    prescreen($ticket);

    Event::assertNotDispatched(AiAnalysisReady::class);
});

/* ------------------------------------------------------------- setting */

it('lets an administrator switch auto-raise on through the AI settings', function () {
    $current = $this->actingAs($this->admin)->getJson('/api/admin/ai/settings')->assertOk()
        ->assertJsonPath('data.settings.priority_auto_raise', false)
        ->json('data.settings');

    $this->actingAs($this->admin)->putJson('/api/admin/ai/settings', [...$current, 'priority_auto_raise' => true])
        ->assertOk()
        ->assertJsonPath('data.settings.priority_auto_raise', true);
});

it('treats a never-seeded auto-raise setting as off', function () {
    SystemSetting::query()->where('key', 'ai.priority_auto_raise')->delete();
    $ticket = lowTicket($this);
    TicketPreScreeningAgent::fake([recommending('critical', 0.99)]);

    prescreen($ticket);

    expect($ticket->refresh()->priority_id)->toBe(priorityId('low'));
});
