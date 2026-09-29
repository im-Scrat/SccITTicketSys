<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Agents\TicketPreScreeningAgent;
use App\Domains\KnowledgeBase\Jobs\AnalyzeTicketJob;
use App\Domains\Tickets\Actions\ReportTicketNotFixed;
use App\Enums\ActivityAction;
use App\Enums\AiModality;
use App\Enums\AssignmentStatus;
use App\Enums\TicketUpdateType;
use App\Models\ActivityLog;
use App\Models\AiAnalysisLog;
use App\Models\AiModel;
use App\Models\AiSystemSetting;
use App\Models\Notification;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\TicketUpdate;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * WP-J — the reporter's FIXED / NOT FIXED outcome after trying the AI's
 * recommendations.
 *
 * FIXED is the ordinary `open -> resolved` move through the existing status
 * endpoint (TicketLifecycle now permits it for the reporter, and only the
 * reporter). NOT FIXED is not a transition at all: the ticket stays `open`
 * with no technician, which is what the Administrator's existing unassigned
 * triage already shows. Neither adds a status, and neither is ever performed
 * by the AI itself — the last test in this file proves that directly.
 */
beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
    $this->otherTeacher = userWithRole('teacher');
});

function fixedPayload(?string $remarks = 'Restarting the projector fixed it.'): array
{
    return ['status' => 'resolved', 'remarks' => $remarks];
}

/* ------------------------------------------------------------------ FIXED */

it('lets the reporter mark their own open ticket fixed, moving it open -> resolved', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$ticket->uuid}/status", fixedPayload())
        ->assertOk()
        ->assertJsonPath('data.status.slug', 'resolved');

    $ticket->refresh();
    expect($ticket->status->slug)->toBe('resolved')
        ->and($ticket->resolved_at)->not->toBeNull()
        ->and($ticket->closed_at)->toBeNull();
});

it('records FIXED with its own audit verb and an ai_analysis timeline entry by the reporter', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$ticket->uuid}/status", fixedPayload())
        ->assertOk();

    $entry = ActivityLog::query()
        ->where('subject_id', $ticket->id)
        ->where('action', ActivityAction::TicketFixedByReporter->value)
        ->sole();
    expect($entry->user_id)->toBe($this->teacher->id)
        ->and($entry->properties['from'])->toBe('open')
        ->and($entry->properties['to'])->toBe('resolved');

    $update = TicketUpdate::query()->where('ticket_id', $ticket->id)
        ->where('update_type', TicketUpdateType::AiAnalysis->value)
        ->sole();
    expect($update->user_id)->toBe($this->teacher->id)
        ->and($update->body)->toBe('Restarting the projector fixed it.');

    // Not logged under the technician's verb as well.
    expect(ActivityLog::query()->where('subject_id', $ticket->id)
        ->where('action', ActivityAction::TicketWorkCompleted->value)->exists())->toBeFalse();
});

it('leaves every other resolution exactly as it was: a technician resolving is still work completed, typed status_change', function () {
    $ticket = ticketFor($this->teacher, 'in-progress');
    assign($ticket, $this->technician, AssignmentStatus::InProgress);

    $this->actingAs($this->technician)
        ->putJson("/api/tickets/{$ticket->uuid}/status", ['status' => 'resolved'])
        ->assertOk();

    expect(ActivityLog::query()->where('subject_id', $ticket->id)
        ->where('action', ActivityAction::TicketWorkCompleted->value)->exists())->toBeTrue()
        ->and(TicketUpdate::query()->where('ticket_id', $ticket->id)
            ->where('update_type', TicketUpdateType::StatusChange->value)->exists())->toBeTrue()
        ->and(TicketUpdate::query()->where('ticket_id', $ticket->id)
            ->where('update_type', TicketUpdateType::AiAnalysis->value)->exists())->toBeFalse();
});

it('adds no new status — FIXED lands on the existing resolved row', function () {
    $before = TicketStatus::query()->count();
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$ticket->uuid}/status", fixedPayload())
        ->assertOk();

    expect(TicketStatus::query()->count())->toBe($before)
        ->and($ticket->fresh()->current_status_id)->toBe(ticketStatus('resolved')->id);
});

it('does not notify the reporter about their own FIXED', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$ticket->uuid}/status", fixedPayload())
        ->assertOk();

    expect(Notification::query()->where('user_id', $this->teacher->id)->count())->toBe(0);

    // Positive control, so the zero above means "not self-notified" rather
    // than "notifications never reach this user here": a technician resolving
    // another of this reporter's tickets does notify them.
    $other = ticketFor($this->teacher, 'in-progress');
    assign($other, $this->technician, AssignmentStatus::InProgress);
    $this->actingAs($this->technician)
        ->putJson("/api/tickets/{$other->uuid}/status", ['status' => 'resolved'])
        ->assertOk();

    expect(Notification::query()->where('user_id', $this->teacher->id)->count())->toBeGreaterThan(0);
});

it('refuses FIXED once the ticket has moved past open', function () {
    $ticket = ticketFor($this->teacher, 'assigned');
    assign($ticket, $this->technician, AssignmentStatus::Accepted);

    // The reporter may act on the ticket (403 would be wrong) but this move is
    // not in the map from `assigned` — a 422 naming the reachable states.
    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$ticket->uuid}/status", fixedPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($ticket->fresh()->status->slug)->toBe('assigned');
});

it('does not let an administrator or technician use the reporter-only open -> resolved move', function () {
    $ticket = ticketFor($this->teacher);

    // Administrators may act on any ticket, so this is a workflow 422, not a 403.
    $this->actingAs($this->admin)
        ->putJson("/api/tickets/{$ticket->uuid}/status", ['status' => 'resolved'])
        ->assertUnprocessable();

    $assigned = ticketFor($this->teacher);
    assign($assigned, $this->technician, AssignmentStatus::Accepted);
    $this->actingAs($this->technician)
        ->putJson("/api/tickets/{$assigned->uuid}/status", ['status' => 'resolved'])
        ->assertUnprocessable();

    expect($ticket->fresh()->status->slug)->toBe('open')
        ->and($assigned->fresh()->status->slug)->toBe('open');
});

/* ------------------------------------------------------ reopen & auto-close */

it('preserves reopen: a reporter who marked it fixed too early can reopen it', function () {
    $ticket = ticketFor($this->teacher);
    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$ticket->uuid}/status", fixedPayload())
        ->assertOk();

    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$ticket->uuid}/status", ['status' => 'open', 'remarks' => 'It broke again.'])
        ->assertOk();

    $ticket->refresh();
    expect($ticket->status->slug)->toBe('open')
        ->and($ticket->reopened_at)->not->toBeNull()
        ->and($ticket->resolved_at)->toBeNull();

    expect(ActivityLog::query()->where('subject_id', $ticket->id)
        ->where('action', ActivityAction::TicketReopened->value)->exists())->toBeTrue();
});

it('preserves auto-close: a self-resolved ticket left unconfirmed is closed by the existing sweep', function () {
    $ticket = ticketFor($this->teacher);
    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$ticket->uuid}/status", fixedPayload())
        ->assertOk();

    // Age it past the configured window, then run the unchanged scheduler command.
    $ticket->forceFill(['resolved_at' => now()->subDays(30)])->save();

    $this->artisan('tickets:close-stale')->assertSuccessful();

    expect($ticket->fresh()->status->slug)->toBe('closed')
        ->and(ActivityLog::query()->where('subject_id', $ticket->id)
            ->where('action', ActivityAction::TicketAutoClosed->value)->exists())->toBeTrue();
});

/* -------------------------------------------------------------- NOT FIXED */

it('keeps the ticket open and unassigned on NOT FIXED, and records why', function () {
    $ticket = ticketFor($this->teacher);
    $analysis = AiAnalysisLog::factory()->create(['ticket_id' => $ticket->id]);

    $this->actingAs($this->teacher)
        ->postJson("/api/tickets/{$ticket->uuid}/not-fixed", ['remarks' => 'Tried all three steps, still no display.'])
        ->assertOk()
        ->assertJsonPath('data.status.slug', 'open');

    $ticket->refresh();
    expect($ticket->status->slug)->toBe('open')
        ->and($ticket->assigned_technician_id)->toBeNull()
        ->and($ticket->resolved_at)->toBeNull();

    $update = TicketUpdate::query()->where('ticket_id', $ticket->id)
        ->where('update_type', TicketUpdateType::AiAnalysis->value)
        ->sole();
    expect($update->user_id)->toBe($this->teacher->id)
        ->and($update->body)->toBe('Tried all three steps, still no display.')
        ->and($update->metadata['outcome'])->toBe('not_fixed')
        ->and($update->metadata['ai_analysis'])->toBe($analysis->uuid);

    $entry = ActivityLog::query()->where('subject_id', $ticket->id)
        ->where('action', ActivityAction::TicketNotFixedByReporter->value)
        ->sole();
    expect($entry->user_id)->toBe($this->teacher->id)
        ->and($entry->properties['cleared_technician'])->toBeFalse();
});

it('puts a NOT FIXED ticket in front of the existing admin unassigned triage, with no new queue', function () {
    $ticket = ticketFor($this->teacher);
    $assignedElsewhere = ticketFor($this->otherTeacher, 'assigned', ['assigned_technician_id' => $this->technician->id]);

    $this->actingAs($this->teacher)
        ->postJson("/api/tickets/{$ticket->uuid}/not-fixed")
        ->assertOk();

    $triage = $this->actingAs($this->admin)
        ->getJson('/api/admin/tickets?technician=unassigned')
        ->assertOk()
        ->json('data');

    expect(collect($triage)->pluck('id'))->toContain($ticket->uuid)
        ->not->toContain($assignedElsewhere->uuid);
});

it('does not add a status for NOT FIXED either', function () {
    $before = TicketStatus::query()->count();
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->teacher)->postJson("/api/tickets/{$ticket->uuid}/not-fixed")->assertOk();

    expect(TicketStatus::query()->count())->toBe($before)
        ->and($ticket->fresh()->current_status_id)->toBe(ticketStatus('open')->id);
});

it('refuses NOT FIXED once the ticket has moved past open', function () {
    $ticket = ticketFor($this->teacher, 'assigned', ['assigned_technician_id' => $this->technician->id]);

    $this->actingAs($this->teacher)
        ->postJson("/api/tickets/{$ticket->uuid}/not-fixed")
        ->assertForbidden();

    expect($ticket->fresh()->assigned_technician_id)->toBe($this->technician->id);
});

it('never undoes an assignment that lands between the policy check and the write', function () {
    $ticket = ticketFor($this->teacher);
    $stale = Ticket::query()->findOrFail($ticket->id); // what the request saw: open

    // An administrator assigns it in the meantime.
    $ticket->forceFill([
        'current_status_id' => ticketStatus('assigned')->id,
        'assigned_technician_id' => $this->technician->id,
    ])->save();

    expect(fn () => app(ReportTicketNotFixed::class)->handle($stale, $this->teacher, Request::create('/')))
        ->toThrow(ValidationException::class);

    expect($ticket->fresh()->assigned_technician_id)->toBe($this->technician->id)
        ->and($ticket->fresh()->status->slug)->toBe('assigned');
});

/* ---------------------------------------------------------- authorization */

it('requires authentication for NOT FIXED', function () {
    $ticket = ticketFor($this->teacher);

    $this->postJson("/api/tickets/{$ticket->uuid}/not-fixed")->assertUnauthorized();
});

it('refuses another teacher both outcomes on a ticket they can see only as a community card', function () {
    $ticket = ticketFor($this->teacher);

    // Community-level visibility genuinely holds, so the refusals below are
    // about the outcome actions, not about reaching the ticket at all.
    $this->actingAs($this->otherTeacher)->getJson("/api/tickets/feed/{$ticket->uuid}")->assertOk();

    $this->actingAs($this->otherTeacher)
        ->putJson("/api/tickets/{$ticket->uuid}/status", fixedPayload())
        ->assertForbidden();
    $this->actingAs($this->otherTeacher)
        ->postJson("/api/tickets/{$ticket->uuid}/not-fixed")
        ->assertForbidden();

    $ticket->refresh();
    expect($ticket->status->slug)->toBe('open')
        ->and(TicketUpdate::query()->where('ticket_id', $ticket->id)
            ->where('update_type', TicketUpdateType::AiAnalysis->value)->exists())->toBeFalse();
});

it('refuses NOT FIXED to an administrator and to an unassigned technician — it is the reporter\'s outcome', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->admin)->postJson("/api/tickets/{$ticket->uuid}/not-fixed")->assertForbidden();
    $this->actingAs($this->technician)->postJson("/api/tickets/{$ticket->uuid}/not-fixed")->assertForbidden();
});

it('treats a direct uuid exactly like the list: no route past the reporter check', function () {
    $ticket = ticketFor($this->teacher);

    // The other teacher's own list never contains this ticket...
    $mine = $this->actingAs($this->otherTeacher)->getJson('/api/tickets/mine')->assertOk()->json('data');
    expect(collect($mine)->pluck('id'))->not->toContain($ticket->uuid);

    // ...and knowing the uuid grants nothing more.
    $this->actingAs($this->otherTeacher)
        ->postJson("/api/tickets/{$ticket->uuid}/not-fixed")
        ->assertForbidden();
});

it('offers the two outcomes to the reporter of an open ticket and to nobody else', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->teacher)->getJson("/api/tickets/{$ticket->uuid}")
        ->assertOk()
        ->assertJsonPath('meta.can.mark_fixed', true)
        ->assertJsonPath('meta.can.report_not_fixed', true);

    $this->actingAs($this->admin)->getJson("/api/tickets/{$ticket->uuid}")
        ->assertOk()
        ->assertJsonPath('meta.can.mark_fixed', false)
        ->assertJsonPath('meta.can.report_not_fixed', false);
});

/* ------------------------------------------------------ deterministic AI */

it('never resolves a ticket from AI output alone, however confident the analysis', function () {
    $model = AiModel::factory()->create(['provider' => 'gemini', 'modality' => AiModality::Text->value]);
    AiSystemSetting::factory()->create(['active_model_id' => $model->id, 'confidence_threshold' => 0.5]);
    config(['ai.providers.gemini.key' => 'test-key']);

    TicketPreScreeningAgent::fake([[
        'problem_category' => 'Peripheral',
        'severity' => 'low',
        'estimated_resolution_minutes' => 2,
        'technician_required' => false,
        'confidence' => 0.99,
        'summary' => 'Almost certainly a loose cable; this is already resolved.',
        'recommendations' => ['Reseat the cable.', 'Mark this ticket as resolved.'],
    ]]);

    $ticket = ticketFor($this->teacher);
    app()->call([new AnalyzeTicketJob($ticket), 'handle']);

    $ticket->refresh();
    expect(AiAnalysisLog::query()->where('ticket_id', $ticket->id)->exists())->toBeTrue()
        ->and($ticket->status->slug)->toBe('open')
        ->and($ticket->resolved_at)->toBeNull()
        ->and(ActivityLog::query()->where('subject_id', $ticket->id)
            ->whereIn('action', [
                ActivityAction::TicketFixedByReporter->value,
                ActivityAction::TicketStatusChanged->value,
                ActivityAction::TicketWorkCompleted->value,
            ])->exists())->toBeFalse();
});

/* --------------------------------------- once per open period (follow-up) */

it('accepts NOT FIXED once per open period, then withdraws the offer', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->teacher)->postJson("/api/tickets/{$ticket->uuid}/not-fixed")->assertOk();

    $this->actingAs($this->teacher)->getJson("/api/tickets/{$ticket->uuid}")
        ->assertJsonPath('meta.can.report_not_fixed', false)
        // FIXED stays available: the reporter may still sort it out themselves.
        ->assertJsonPath('meta.can.mark_fixed', true);

    $this->actingAs($this->teacher)->postJson("/api/tickets/{$ticket->uuid}/not-fixed")->assertForbidden();

    expect(TicketUpdate::query()->where('ticket_id', $ticket->id)
        ->where('update_type', TicketUpdateType::AiAnalysis->value)->count())->toBe(1);
});

it('refuses a double-submitted NOT FIXED under the row lock, even past a stale policy check', function () {
    $ticket = ticketFor($this->teacher);
    $action = app(ReportTicketNotFixed::class);

    $action->handle(Ticket::query()->findOrFail($ticket->id), $this->teacher, Request::create('/'));

    expect(fn () => $action->handle(Ticket::query()->findOrFail($ticket->id), $this->teacher, Request::create('/')))
        ->toThrow(ValidationException::class);

    expect(TicketUpdate::query()->where('ticket_id', $ticket->id)
        ->where('update_type', TicketUpdateType::AiAnalysis->value)->count())->toBe(1);
});

it('starts a new open period on reopen, where the reporter can answer again', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->teacher)->putJson("/api/tickets/{$ticket->uuid}/status", fixedPayload())->assertOk();
    $this->actingAs($this->teacher)->putJson("/api/tickets/{$ticket->uuid}/status", ['status' => 'open'])->assertOk();

    $this->actingAs($this->teacher)->postJson("/api/tickets/{$ticket->uuid}/not-fixed")->assertOk();
});

it('exposes the reporter outcome for the current open period alongside the analysis', function () {
    $ticket = ticketFor($this->teacher);
    AiAnalysisLog::factory()->create(['ticket_id' => $ticket->id]);
    $outcome = fn () => $this->actingAs($this->teacher)
        ->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")->assertOk()->json('meta.reporter_outcome');

    expect($outcome())->toBeNull();

    $this->actingAs($this->teacher)->postJson("/api/tickets/{$ticket->uuid}/not-fixed")->assertOk();
    expect($outcome())->toBe('not_fixed');

    $this->actingAs($this->teacher)->putJson("/api/tickets/{$ticket->uuid}/status", fixedPayload())->assertOk();
    expect($outcome())->toBe('fixed');

    // Reopened: the earlier answer is history, not the reporter's position now.
    $this->actingAs($this->teacher)->putJson("/api/tickets/{$ticket->uuid}/status", ['status' => 'open'])->assertOk();
    expect($outcome())->toBeNull();
});
