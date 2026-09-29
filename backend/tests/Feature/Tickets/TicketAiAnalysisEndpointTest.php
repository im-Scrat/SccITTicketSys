<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Models\AiAnalysisLog;
use App\Models\AiRecommendation;
use App\Models\AiSystemSetting;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;

/**
 * WP-I — the Teacher AI panel (`GET /tickets/{uuid}/ai-analysis`).
 *
 * The whole point of this file: `TicketVisibility::canSeeFull()`, not
 * `canSee()`. A Teacher who is not the reporter can *see* another Teacher's
 * ticket — that is the community feed's entire purpose — but must never
 * reach their full AI analysis through this weaker scope. That is the exact
 * IDOR the WP-I mandate names, and it is asserted here directly rather than
 * inferred from the job tests, which never touch HTTP or authorization at
 * all (see AnalyzeTicketJobTest for the pipeline itself).
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

it('requires authentication', function () {
    $ticket = ticketFor($this->teacher);

    $this->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")->assertUnauthorized();
});

it('lets the reporter reach their own ticket analysis (full visibility)', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->teacher)
        ->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")
        ->assertOk()
        ->assertJsonPath('meta.available', false)
        ->assertJsonPath('data', null);
});

it('lets an administrator reach any ticket analysis', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->admin)
        ->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")
        ->assertOk();
});

it('lets the assigned technician reach the analysis', function () {
    $ticket = ticketFor($this->teacher, 'assigned');
    assign($ticket, $this->technician, AssignmentStatus::Accepted);

    $this->actingAs($this->technician)
        ->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")
        ->assertOk();
});

it('refuses a technician with no assignment to this ticket', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->technician)
        ->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")
        ->assertForbidden();
});

/*
 * The critical case: canSee() would pass this (a Teacher can see any open
 * ticket as a community card, which is exactly why the duplicate-checking
 * feed works at all) — canSeeFull() must refuse it, and the route must be
 * using canSeeFull(), not canSee(). See TicketPolicy::viewFull().
 */
it('refuses another teacher who can see the ticket only as a community card', function () {
    $ticket = ticketFor($this->teacher);

    // Establish community-level visibility actually holds for this pair, so a
    // false negative here can't accidentally make the next assertion trivial.
    $this->actingAs($this->otherTeacher)
        ->getJson("/api/tickets/feed/{$ticket->uuid}")
        ->assertOk();

    $this->actingAs($this->otherTeacher)
        ->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")
        ->assertForbidden();
});

it('is equally unreachable by a direct uuid as it is absent from any list this teacher can see', function () {
    $ticket = ticketFor($this->teacher);

    // The uuid is real and the ticket exists; it simply never appears in a
    // list the other teacher's community feed can build (feed is open-only
    // and search-driven) — direct access must match that, not exceed it.
    $this->actingAs($this->otherTeacher)
        ->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")
        ->assertForbidden();
});

it('reports unavailable rather than a broken shape before any analysis has run', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->teacher)
        ->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")
        ->assertOk()
        ->assertExactJson(['data' => null, 'meta' => ['available' => false]]);
});

it('returns advisory, confidence-aware fields once an analysis exists', function () {
    AiSystemSetting::factory()->create(['confidence_threshold' => 0.75]);
    $ticket = ticketFor($this->teacher);
    $log = AiAnalysisLog::factory()->create([
        'ticket_id' => $ticket->id,
        'confidence_score' => 0.9,
    ]);
    AiRecommendation::factory()->create([
        'ai_analysis_log_id' => $log->id,
        'step_order' => 2,
        'recommendation' => 'Second step',
    ]);
    AiRecommendation::factory()->create([
        'ai_analysis_log_id' => $log->id,
        'step_order' => 1,
        'recommendation' => 'First step',
    ]);

    $response = $this->actingAs($this->teacher)
        ->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")
        ->assertOk()
        ->assertJsonPath('meta.available', true)
        ->assertJsonPath('data.ai_generated', true)
        ->assertJsonPath('data.advisory', true)
        ->assertJsonPath('data.confidence', 0.9)
        ->assertJsonPath('data.recommendations.0.text', 'First step')
        ->assertJsonPath('data.recommendations.1.text', 'Second step');

    expect($response->json('data.meets_confidence_threshold'))->toBeBool();
});

it('flags an analysis below the configured confidence threshold, without hiding it', function () {
    AiSystemSetting::factory()->create(['confidence_threshold' => 0.7]);
    $ticket = ticketFor($this->teacher);
    AiAnalysisLog::factory()->create(['ticket_id' => $ticket->id, 'confidence_score' => 0.3]);

    $this->actingAs($this->teacher)
        ->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")
        ->assertOk()
        ->assertJsonPath('data.confidence', 0.3)
        ->assertJsonPath('data.confidence_threshold', 0.7)
        ->assertJsonPath('data.meets_confidence_threshold', false);
});

it('applies the existing 20/hour ticket rate limiter to this route', function () {
    config(['security.rate_limits.tickets_per_hour' => 2]);
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->teacher)->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")->assertOk();
    $this->actingAs($this->teacher)->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")->assertOk();
    $this->actingAs($this->teacher)->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")->assertStatus(429);
});
