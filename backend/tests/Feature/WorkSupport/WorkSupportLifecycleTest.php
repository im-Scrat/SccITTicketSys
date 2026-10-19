<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Enums\WorkSupportStatus;
use App\Models\ActivityLog;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\WorkSupportRequest;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * WP-2.6b Stage E — the six-state transition map
 * (SRS FR-WSR-004/006/007/008/011/014; AC-WSR-008; SDD DD-54).
 *
 * **The rule under test.** A status is never assigned; it is *reached*, through
 * a named operation the map permits for that actor from that state. FR-WSR-004
 * is explicit: *"Transitions shall be enforced by a transition map, not by
 * accepting a status field from the client — an arbitrary status update shall
 * be refused."*
 *
 * So the two properties that matter most here are negative ones:
 *
 *  1. **There is no endpoint that sets a status.** Asserted directly.
 *  2. **No decision is ever replaced.** `approved` and `declined` reach only
 *     `closed`, so a second decision is refused by the map rather than
 *     overwriting the first — and the superseded values of anything that *did*
 *     move stay recoverable from `activity_logs`.
 */
beforeEach(function (): void {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->seed(TicketLookupSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->otherAdmin = userWithRole('administrator');
    $this->technician = userWithRole('technician');

    $this->pcUnit = PcUnit::factory()->create([
        'unit_code' => 'PC-LIFE-01',
        'status' => PcStatus::Available->value,
    ]);

    QrCode::factory()->create([
        'pc_unit_id' => $this->pcUnit->id,
        'asset_id' => null,
        'code' => 'PC-LIFECYCLE1',
        'status' => QrStatus::Active->value,
    ]);

    $this->job = maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
        'scheduled_for' => now()->addDay(),
    ]);

    $this->actingAs($this->technician)
        ->postJson('/api/qr/PC-LIFECYCLE1/support-requests', [
            'explanation' => 'The power supply has failed and there is no spare unit on site.',
            'items' => [['description' => 'ATX power supply, 500W', 'quantity' => 1]],
        ])->assertCreated();

    $this->request = WorkSupportRequest::query()->sole();
});

/* -------------------------------------------------------------- helpers */

function decide(string $uuid, string $decision, array $payload = [])
{
    return test()->postJson("/api/admin/work-support-requests/{$uuid}/{$decision}", $payload);
}

/* =================================================== Decision A — approve */

it('approves a request and records who decided it and when', function (): void {
    $when = now()->addWeek()->startOfMinute();

    $this->actingAs($this->admin);

    decide($this->request->uuid, 'approve', [
        'rescheduled_to' => $when->toIso8601String(),
        'reschedule_reason' => 'The part arrives on Monday.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.status_label', 'Approved');

    $request = $this->request->fresh();

    expect($request->status)->toBe(WorkSupportStatus::Approved)
        ->and($request->decided_by)->toBe($this->admin->id)
        ->and($request->decided_at)->not->toBeNull()
        ->and($request->rescheduled_to->startOfMinute()->eq($when))->toBeTrue();
});

it('pushes the new date onto the linked maintenance record and keeps the old one in the history', function (): void {
    // FR-WSR-006 + FR-WSR-004: the column moves, and what it used to say stays
    // recoverable — that is what "not overwritten in place" means in practice.
    $previous = $this->job->scheduled_for->toIso8601String();
    $when = now()->addWeeks(2)->startOfMinute();

    $this->actingAs($this->admin);
    decide($this->request->uuid, 'approve', ['rescheduled_to' => $when->toIso8601String()])->assertOk();

    expect($this->job->fresh()->scheduled_for->startOfMinute()->eq($when))->toBeTrue();

    $log = ActivityLog::query()
        ->where('action', ActivityAction::MaintenanceRescheduled->value)
        ->sole();

    expect($log->properties['from'])->toBe($previous)
        ->and($log->properties['work_support_request'])->toBe($this->request->uuid);
});

it('records the technicians acknowledgement without changing the status', function (): void {
    // FR-WSR-006: an acknowledgement does not change what the request *is*, so
    // it is a timestamp and an audit row, never a seventh state.
    $this->actingAs($this->admin);
    decide($this->request->uuid, 'approve', ['rescheduled_to' => now()->addWeek()->toIso8601String()])->assertOk();

    $this->actingAs($this->technician)
        ->postJson("/api/work-support-requests/{$this->request->uuid}/acknowledge")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $request = $this->request->fresh();

    expect($request->acknowledged_at)->not->toBeNull()
        ->and($request->status)->toBe(WorkSupportStatus::Approved);
});

it('treats a second acknowledgement as the same acknowledgement', function (): void {
    $this->actingAs($this->admin);
    decide($this->request->uuid, 'approve', ['rescheduled_to' => now()->addWeek()->toIso8601String()])->assertOk();

    $this->actingAs($this->technician);
    $this->postJson("/api/work-support-requests/{$this->request->uuid}/acknowledge")->assertOk();
    $first = $this->request->fresh()->acknowledged_at;

    $this->postJson("/api/work-support-requests/{$this->request->uuid}/acknowledge")->assertOk();

    expect($this->request->fresh()->acknowledged_at->eq($first))->toBeTrue()
        ->and(ActivityLog::query()->where('action', ActivityAction::WorkSupportRequestAcknowledged->value)->count())
        ->toBe(1);
});

/* ======================================= Decision B — face-to-face */

it('records a request for a face-to-face discussion', function (): void {
    $this->actingAs($this->admin);

    decide($this->request->uuid, 'request-clarification', [
        'clarification_reason' => 'Come and show me why the whole unit needs replacing.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'clarification_requested')
        ->assertJsonPath('data.status_label', 'Face-to-face requested');

    $request = $this->request->fresh();

    expect($request->clarification_reason)->toContain('show me why')
        // Asking to talk is not a decision — stamping the decision columns here
        // would make the request look settled to the inbox's own filter.
        ->and($request->decided_at)->toBeNull()
        ->and($request->decided_by)->toBeNull()
        ->and($request->status->isUndecided())->toBeTrue();
});

it('refuses a face-to-face request with no reason', function (): void {
    $this->actingAs($this->admin);

    decide($this->request->uuid, 'request-clarification', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('clarification_reason');
});

it('lets a face-to-face request resolve to approved afterwards', function (): void {
    // FR-WSR-004: clarification is not terminal — it returns to approved or
    // declined once the conversation has happened.
    $this->actingAs($this->admin);
    decide($this->request->uuid, 'request-clarification', ['clarification_reason' => 'Lets discuss this.'])->assertOk();

    decide($this->request->uuid, 'approve', ['rescheduled_to' => now()->addWeek()->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    // Both are visible: the request was asked about *and then* approved, and
    // neither erased the other.
    $request = $this->request->fresh();

    expect($request->clarification_reason)->not->toBeNull()
        ->and($request->rescheduled_to)->not->toBeNull();
});

/* ============================================= Decision C — decline */

it('declines a request with a reason', function (): void {
    $this->actingAs($this->admin);

    decide($this->request->uuid, 'decline', [
        'decline_reason' => 'No budget this term; raise it in the next procurement round.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'declined')
        ->assertJsonPath('data.decision.decline_reason', 'No budget this term; raise it in the next procurement round.');

    expect($this->request->fresh()->decided_by)->toBe($this->admin->id);
});

it('refuses a decline with no reason', function (): void {
    $this->actingAs($this->admin);

    decide($this->request->uuid, 'decline', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('decline_reason');

    expect($this->request->fresh()->status)->toBe(WorkSupportStatus::Submitted);
});

it('refuses a decline whose reason is only whitespace', function (): void {
    // The FormRequest, the service and the database all refuse this. `min:5`
    // catches it first; the point of the test is that a technician never sees a
    // decline they cannot read a reason for.
    $this->actingAs($this->admin);

    decide($this->request->uuid, 'decline', ['decline_reason' => "   \t\n  "])
        ->assertStatus(422)
        ->assertJsonValidationErrors('decline_reason');

    expect($this->request->fresh()->status)->toBe(WorkSupportStatus::Submitted);
});

it('refuses a declined row at the database level even with the application bypassed', function (): void {
    // DR-020 asks for the rule "at the database level as well as the
    // application level". Asserted by going around the application entirely.
    expect(fn () => DB::table('work_support_requests')->where('id', $this->request->id)->update([
        'status' => 'declined',
        'decided_at' => now(),
        'decline_reason' => null,
    ]))->toThrow(QueryException::class, 'work_support_requests_decline_reason_check');
});

/* ================================================== the map itself */

it('refuses a second decision on an approved request', function (): void {
    // The property that makes "no prior decision is overwritten" structural:
    // there is no edge from `approved` to `declined`, so the attempt is
    // refused rather than silently re-deciding.
    $this->actingAs($this->admin);
    decide($this->request->uuid, 'approve', ['rescheduled_to' => now()->addWeek()->toIso8601String()])->assertOk();

    decide($this->request->uuid, 'decline', ['decline_reason' => 'Changed my mind about this one.'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    $request = $this->request->fresh();

    expect($request->status)->toBe(WorkSupportStatus::Approved)
        ->and($request->decline_reason)->toBeNull();
});

it('refuses a second decision on a declined request', function (): void {
    $this->actingAs($this->admin);
    decide($this->request->uuid, 'decline', ['decline_reason' => 'No budget this term at all.'])->assertOk();

    decide($this->request->uuid, 'approve', ['rescheduled_to' => now()->addWeek()->toIso8601String()])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($this->request->fresh()->status)->toBe(WorkSupportStatus::Declined);
});

it('refuses a second administrator overwriting the first ones decision', function (): void {
    // Two administrators, seconds apart. The row lock plus the map means the
    // second is refused rather than replacing a recorded decision.
    $this->actingAs($this->admin);
    decide($this->request->uuid, 'approve', ['rescheduled_to' => now()->addWeek()->toIso8601String()])->assertOk();

    $this->actingAs($this->otherAdmin);
    decide($this->request->uuid, 'approve', ['rescheduled_to' => now()->addMonth()->toIso8601String()])
        ->assertStatus(422);

    expect($this->request->fresh()->decided_by)->toBe($this->admin->id);
});

it('lets an administrator close a decided request, and nothing move afterwards', function (): void {
    $this->actingAs($this->admin);
    decide($this->request->uuid, 'approve', ['rescheduled_to' => now()->addWeek()->toIso8601String()])->assertOk();
    decide($this->request->uuid, 'close')->assertOk()->assertJsonPath('data.status', 'closed');

    expect($this->request->fresh()->closed_at)->not->toBeNull();

    decide($this->request->uuid, 'close')->assertStatus(422)->assertJsonValidationErrors('status');
});

it('refuses closing a request nobody has decided', function (): void {
    $this->actingAs($this->admin);

    decide($this->request->uuid, 'close')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

/* ====================================== withdrawal (FR-WSR-014) */

it('lets the submitting technician withdraw an undecided request', function (): void {
    $this->actingAs($this->technician)
        ->postJson("/api/work-support-requests/{$this->request->uuid}/cancel", [
            'cancellation_note' => 'Found a spare in the store cupboard.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $request = $this->request->fresh();

    expect($request->cancelled_by)->toBe($this->technician->id)
        ->and($request->cancelled_at)->not->toBeNull()
        ->and($request->cancellation_note)->toBe('Found a spare in the store cupboard.');
});

it('lets an administrator withdraw on the technicians behalf and records who did', function (): void {
    $this->actingAs($this->admin)
        ->postJson("/api/work-support-requests/{$this->request->uuid}/cancel", [])
        ->assertOk();

    expect($this->request->fresh()->cancelled_by)->toBe($this->admin->id);
});

it('refuses withdrawing a request that has already been decided', function (): void {
    // FR-WSR-014 bounds cancellation to undecided requests: withdrawing an
    // approved one would erase the administrator's answer.
    $this->actingAs($this->admin);
    decide($this->request->uuid, 'approve', ['rescheduled_to' => now()->addWeek()->toIso8601String()])->assertOk();

    $this->actingAs($this->technician)
        ->postJson("/api/work-support-requests/{$this->request->uuid}/cancel", [])
        ->assertForbidden();

    expect($this->request->fresh()->status)->toBe(WorkSupportStatus::Approved);
});

it('never reopens a withdrawn request', function (): void {
    $this->actingAs($this->technician)
        ->postJson("/api/work-support-requests/{$this->request->uuid}/cancel", [])->assertOk();

    $this->actingAs($this->admin);

    decide($this->request->uuid, 'approve', ['rescheduled_to' => now()->addWeek()->toIso8601String()])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($this->request->fresh()->status)->toBe(WorkSupportStatus::Cancelled);
});

/* ============================================ AC-WSR-008 — no status field */

it('exposes no endpoint that sets a status', function (): void {
    /*
     * AC-WSR-008, asserted as an absence. Every plausible shape of "just set
     * the status" is tried against both surfaces; all of them must fail to
     * route or to authorize, and the request must be untouched afterwards.
     */
    $this->actingAs($this->admin);

    $uuid = $this->request->uuid;

    /*
     * 405 rather than 404 on the first three, and that is the stronger answer:
     * the URI exists — it is how you *read* a request — and there is simply no
     * method on it that writes a status. The assertion is therefore "never
     * succeeds", not a particular code, because pinning the code would make
     * this test fail the day a route is regrouped without the property changing.
     */
    foreach ([
        ['patch', "/api/work-support-requests/{$uuid}"],
        ['put', "/api/work-support-requests/{$uuid}"],
        ['patch', "/api/admin/work-support-requests/{$uuid}"],
        ['put', "/api/admin/work-support-requests/{$uuid}"],
        ['patch', "/api/admin/work-support-requests/{$uuid}/status"],
        ['post', "/api/admin/work-support-requests/{$uuid}/status"],
    ] as [$method, $uri]) {
        $response = $this->json(strtoupper($method), $uri, ['status' => 'approved']);

        expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)
            ->and($response->getStatusCode())->toBeLessThan(500);
    }

    expect($this->request->fresh()->status)->toBe(WorkSupportStatus::Submitted);
});

it('ignores a status smuggled into a decision payload', function (): void {
    // The decision endpoints take evidence, not state. A `status` in the body
    // is not validated, not filled, and cannot reach the column.
    $this->actingAs($this->admin);

    decide($this->request->uuid, 'request-clarification', [
        'clarification_reason' => 'Lets talk this one through in person.',
        'status' => 'approved',
    ])->assertOk();

    expect($this->request->fresh()->status)->toBe(WorkSupportStatus::ClarificationRequested);
});

/* ================================================= audit (FR-WSR-011) */

it('logs every transition with its before and after state', function (): void {
    $this->actingAs($this->admin);
    decide($this->request->uuid, 'request-clarification', ['clarification_reason' => 'Lets discuss this one.'])->assertOk();
    decide($this->request->uuid, 'decline', ['decline_reason' => 'Not this term, sorry — no budget.'])->assertOk();
    decide($this->request->uuid, 'close')->assertOk();

    $actions = ActivityLog::query()
        ->where('module', 'work-support')
        ->orderBy('id')
        ->pluck('action')
        ->all();

    expect($actions)->toBe([
        'work_support_request_submitted',
        'work_support_clarification_requested',
        'work_support_request_declined',
        'work_support_request_closed',
    ]);

    $declined = ActivityLog::query()->where('action', 'work_support_request_declined')->sole();

    expect($declined->properties['from'])->toBe('clarification_requested')
        ->and($declined->properties['to'])->toBe('declined')
        ->and($declined->user_id)->toBe($this->admin->id);
});
