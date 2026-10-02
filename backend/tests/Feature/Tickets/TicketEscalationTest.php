<?php

declare(strict_types=1);

use App\Enums\TicketUpdateType;
use App\Models\AiAnalysisLog;
use App\Models\Notification as NotificationRecord;
use App\Models\TicketUpdate;
use App\Models\User;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;

/*
 * Phase 2.9 — NOT FIXED escalates to the administrators.
 *
 * The reporter tries the AI's suggestions and says the fault remains (WP-J).
 * Before this phase that cleared the assignee and audited, but told nobody.
 * Now every active Administrator is notified — and only they are: the
 * reporter, technicians, and other teachers receive nothing.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->secondAdmin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
    $this->otherTeacher = userWithRole('teacher');
});

function escalationsFor(User $user): int
{
    return NotificationRecord::query()
        ->where('user_id', $user->id)
        ->get()
        ->filter(fn (NotificationRecord $n): bool => ($n->data['topic'] ?? null) === 'ticket.escalated')
        ->count();
}

it('notifies every administrator when a reporter says NOT FIXED', function () {
    $ticket = ticketFor($this->teacher);
    AiAnalysisLog::factory()->create(['ticket_id' => $ticket->id]);

    $this->actingAs($this->teacher)->postJson("/api/tickets/{$ticket->uuid}/not-fixed")->assertOk();

    expect(escalationsFor($this->admin))->toBe(1)
        ->and(escalationsFor($this->secondAdmin))->toBe(1);
});

it('notifies no reporter, technician or other teacher of an escalation', function () {
    $ticket = ticketFor($this->teacher);
    AiAnalysisLog::factory()->create(['ticket_id' => $ticket->id]);

    $this->actingAs($this->teacher)->postJson("/api/tickets/{$ticket->uuid}/not-fixed")->assertOk();

    expect(escalationsFor($this->teacher))->toBe(0)
        ->and(escalationsFor($this->technician))->toBe(0)
        ->and(escalationsFor($this->otherTeacher))->toBe(0);
});

it('carries no free text the reporter typed', function () {
    $ticket = ticketFor($this->teacher);
    AiAnalysisLog::factory()->create(['ticket_id' => $ticket->id]);

    $this->actingAs($this->teacher)
        ->postJson("/api/tickets/{$ticket->uuid}/not-fixed", ['remarks' => 'My password is on a sticky note'])
        ->assertOk();

    $notification = NotificationRecord::query()->where('user_id', $this->admin->id)->sole();
    expect(json_encode([$notification->title, $notification->message, $notification->data]))
        ->not->toContain('sticky note')
        ->and($notification->action_url)->toBe("/app/tickets/manage/{$ticket->uuid}");
});

it('notifies nobody when the report is refused', function () {
    $ticket = ticketFor($this->teacher, 'assigned');

    $this->actingAs($this->teacher)->postJson("/api/tickets/{$ticket->uuid}/not-fixed")->assertForbidden();

    expect(escalationsFor($this->admin))->toBe(0);
});

it('lists an escalated ticket under the administrators’ escalated filter', function () {
    $escalated = ticketFor($this->teacher);
    $quiet = ticketFor($this->teacher);
    AiAnalysisLog::factory()->create(['ticket_id' => $escalated->id]);

    $this->actingAs($this->teacher)->postJson("/api/tickets/{$escalated->uuid}/not-fixed")->assertOk();

    $ids = collect($this->actingAs($this->admin)->getJson('/api/admin/tickets?escalated=1')->assertOk()->json('data'))
        ->pluck('id');

    expect($ids->all())->toBe([$escalated->uuid])
        ->and($ids)->not->toContain($quiet->uuid);
});

it('forgets an old NOT FIXED once the ticket re-enters open', function () {
    $ticket = ticketFor($this->teacher);
    TicketUpdate::query()->create([
        'ticket_id' => $ticket->id, 'user_id' => $this->teacher->id,
        'update_type' => TicketUpdateType::AiAnalysis->value,
        'metadata' => ['outcome' => 'not_fixed'], 'created_at' => now()->subDay(),
    ]);
    // A later move back into `open` starts a new period (ReporterOutcome's rule).
    TicketUpdate::query()->create([
        'ticket_id' => $ticket->id, 'user_id' => $this->admin->id,
        'update_type' => TicketUpdateType::StatusChange->value,
        'metadata' => ['from' => 'resolved', 'to' => 'open'], 'created_at' => now(),
    ]);

    $this->actingAs($this->admin)->getJson('/api/admin/tickets?escalated=1')->assertOk()
        ->assertJsonCount(0, 'data');
});

it('keeps the escalated filter out of the community feed', function () {
    // The filter is administrative; on the feed it is simply not applied, so a
    // teacher cannot use it to learn which colleagues' tickets were escalated.
    $ticket = ticketFor($this->otherTeacher);
    AiAnalysisLog::factory()->create(['ticket_id' => $ticket->id]);
    $this->actingAs($this->otherTeacher)->postJson("/api/tickets/{$ticket->uuid}/not-fixed")->assertOk();
    ticketFor($this->otherTeacher);

    $this->actingAs($this->teacher)->getJson('/api/tickets/feed?escalated=1')->assertOk()
        ->assertJsonCount(2, 'data');
});
