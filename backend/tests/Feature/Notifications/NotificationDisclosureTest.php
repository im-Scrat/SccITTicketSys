<?php

declare(strict_types=1);

use App\Domains\Tickets\Actions\AssignTicket;
use App\Domains\Tickets\Actions\ManageTicketComment;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Enums\AssignmentStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Models\Notification as NotificationRecord;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\User;
use App\Models\WorkSupportRequest;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * WP-2.7a — **what a notification may say** (WP-2.7a security scope; SRS
 * FR-NOT-006, NFR-SEC-003).
 *
 * A notification is a **second surface onto a record the recipient may or may
 * not be allowed to read in full**. `TicketVisibility`, `MaintenanceVisibility`
 * and `WorkSupportVisibility` each work hard to withhold particular fields from
 * particular roles; a notification that quoted those fields would hand them over
 * by another route, and would do it into an email client.
 *
 * ── The rule this work package adopted ─────────────────────────────────────
 *
 * **A notification carries structural facts. Free text a person typed is never
 * copied into one** — not the comment body, not the transition remarks, not the
 * technician's explanation, not the administrator's decline reason. Structural
 * facts are ticket numbers, statuses, priorities, machine codes and dates.
 *
 * The rule is deliberately broader than "redact what this recipient may not
 * see", because that version needs a per-field, per-role judgement at every
 * trigger and is therefore wrong the first time somebody adds a trigger without
 * making it. This version is checkable, and the checks are below.
 *
 * ── Asserted against the stored payload, not the resource ──────────────────
 *
 * Every assertion reads the row as it was **written**, so a field cannot leak
 * back in later through a change to `NotificationResource`.
 */
beforeEach(function (): void {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(MaintenanceTypeSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
});

/** Everything written for one person, as one searchable string. */
function encodedInboxOf(User $user): string
{
    return (string) json_encode(
        NotificationRecord::query()
            ->where('user_id', $user->id)
            ->get(['type', 'title', 'message', 'data', 'action_url'])
            ->toArray()
    );
}

/* ---------------------------------------------------------------- T2 rules */

/**
 * DD-41 withholds internal notes and technician detail from a Teacher's ticket
 * projection. The status-change notification must withhold the same things.
 */
it('T2: does not carry the transition remarks to the reporter', function (): void {
    $ticket = ticketFor($this->teacher, 'assigned', ['assigned_technician_id' => $this->technician->id]);
    assign($ticket, $this->technician, AssignmentStatus::Accepted);

    app(TicketLifecycle::class)->transition(
        $ticket,
        ticketStatus('in-progress'),
        $this->technician,
        'Motherboard is fried; do not tell the teacher we dropped it.',
        Request::create('/'),
    );

    expect(encodedInboxOf($this->teacher))
        ->not->toContain('Motherboard is fried')
        ->not->toContain('dropped it')
        // What it does say is the fact of the move.
        ->toContain($ticket->ticket_number);
});

it('T2: does not name the technician who moved the ticket', function (): void {
    $technician = userWithRole('technician', ['first_name' => 'Zephyrine', 'last_name' => 'Quillfeather']);
    $ticket = ticketFor($this->teacher, 'assigned', ['assigned_technician_id' => $technician->id]);
    assign($ticket, $technician, AssignmentStatus::Accepted);

    app(TicketLifecycle::class)->transition($ticket, ticketStatus('in-progress'), $technician, null, Request::create('/'));

    expect(encodedInboxOf($this->teacher))
        ->not->toContain('Zephyrine')
        ->not->toContain('Quillfeather');
});

/* ---------------------------------------------------------------- T3 rules */

it('T3: does not carry the comment body to anybody', function (): void {
    $ticket = ticketFor($this->teacher, 'assigned', ['assigned_technician_id' => $this->technician->id]);
    assign($ticket, $this->technician, AssignmentStatus::Accepted);

    app(ManageTicketComment::class)->create(
        $ticket,
        'The pupil admitted spilling juice into the keyboard.',
        false,
        $this->technician,
        Request::create('/'),
    );

    expect(encodedInboxOf($this->teacher))
        ->not->toContain('spilling juice')
        ->not->toContain('pupil admitted')
        ->toContain('New comment on ticket');
});

/**
 * The narrower guard, and the one that matters most: on an internal note even
 * the **fact** of it is staff-only, so a requester is not a recipient at all.
 */
it('T3: never tells a teacher that an internal note exists', function (): void {
    $ticket = ticketFor($this->teacher, 'assigned', ['assigned_technician_id' => $this->technician->id]);
    assign($ticket, $this->technician, AssignmentStatus::Accepted);

    app(ManageTicketComment::class)->create(
        $ticket,
        'Third failure this term — recommend we stop repairing this unit.',
        true,
        $this->admin,
        Request::create('/'),
    );

    expect(NotificationRecord::query()->where('user_id', $this->teacher->id)->count())->toBe(0)
        // The staff side is told, so the absence above is a rule and not a bug.
        ->and(NotificationRecord::query()->where('user_id', $this->technician->id)->count())->toBe(1);
});

/* ---------------------------------------------------------------- T1 rules */

it('T1: does not name the reporter to the assigned technician', function (): void {
    $teacher = userWithRole('teacher', ['first_name' => 'Marigold', 'last_name' => 'Thistlewood']);
    $ticket = ticketFor($teacher);

    app(AssignTicket::class)->handle($ticket, $this->technician, $this->admin, Request::create('/'));

    expect(encodedInboxOf($this->technician))
        ->not->toContain('Marigold')
        ->not->toContain('Thistlewood')
        ->toContain($ticket->ticket_number);
});

/* ---------------------------------------------------------------- T5 rules */

/**
 * The matrix forbids price, supplier, warranty and procurement data in a
 * maintenance notification. `maintenance_records` carries `cost` and
 * `labor_hours` in the very columns beside the ones this notification reads, so
 * the hazard is live rather than theoretical.
 */
it('T5: carries no commercial field from the maintenance record', function (): void {
    $pcUnit = PcUnit::factory()->create(['unit_code' => 'PC-DISC-01', 'status' => PcStatus::Available->value]);

    $this->actingAs($this->admin)->postJson('/api/maintenance', [
        'title' => 'Replace the power supply',
        'type' => 'corrective',
        'pc_unit' => $pcUnit->uuid,
        'technician' => $this->technician->uuid,
        'cost' => 4571.25,
        'labor_hours' => 3.5,
        'scheduled_for' => now()->addDay()->toIso8601String(),
    ])->assertCreated();

    expect(encodedInboxOf($this->technician))
        ->not->toContain('4571')
        ->not->toContain('cost')
        ->not->toContain('labor')
        ->toContain('PC-DISC-01');
});

/* --------------------------------------------------------------- T10 rules */

it('T10: leaves the administrator\'s written reason on the request', function (): void {
    $pcUnit = PcUnit::factory()->create(['unit_code' => 'PC-DISC-02', 'status' => PcStatus::Available->value]);
    QrCode::factory()->create([
        'pc_unit_id' => $pcUnit->id,
        'asset_id' => null,
        'code' => 'PCDISCLOSE01',
        'status' => QrStatus::Active->value,
    ]);
    maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    $this->actingAs($this->technician)
        ->postJson('/api/qr/PCDISCLOSE01/support-requests', [
            'explanation' => 'The board is cracked where somebody forced the RAM in.',
            'items' => [['description' => 'Replacement mainboard', 'quantity' => 1]],
        ])->assertCreated();

    // T9 — the technician's account of the fault is not copied to administrators.
    expect(encodedInboxOf($this->admin))
        ->not->toContain('forced the RAM')
        ->not->toContain('cracked')
        ->toContain('PC-DISC-02');

    $request = WorkSupportRequest::query()->sole();

    $this->actingAs($this->admin)
        ->postJson("/api/admin/work-support-requests/{$request->uuid}/decline", [
            'decline_reason' => 'Budget exhausted; this unit is scheduled for disposal anyway.',
        ])->assertOk();

    expect(encodedInboxOf($this->technician))
        ->not->toContain('Budget exhausted')
        ->not->toContain('disposal')
        ->toContain('Work support declined');
});

/* --------------------------------------------------------------- T11 rules */

/**
 * The person reading a lockout notice may not be the person who caused it: an
 * attacker who locks somebody's account by guessing at it must not be handed a
 * report of their own reconnaissance.
 */
it('T11: discloses the duration and nothing about the attempts', function (): void {
    $user = userWithRole('teacher', ['email' => 'disclosure@sccit.local']);
    $attempts = (int) config('security.login.max_attempts', 5);

    for ($i = 0; $i < $attempts; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    /*
     * "Several failed attempts" is the notice itself and is fine; what must not
     * appear is anything an attacker could use as feedback — the address the
     * attempts came from, the client that made them, or a count.
     */
    expect(encodedInboxOf($user))
        ->not->toContain('203.0.113')
        ->not->toContain('user_agent')
        ->not->toContain('ip_address')
        ->not->toContain('attempt_count')
        ->toContain('temporarily locked');

    RateLimiter::clear('login|'.$user->email.'|203.0.113.42');
});

/* ------------------------------------------------- structural safety rules */

/**
 * FR-QR-011 established that a destination the system builds is safe and a
 * destination something else supplied is an open redirect. `action_url` is a
 * destination, so the same rule holds: relative, internal, never absolute.
 */
it('links only to internal paths the system built itself', function (): void {
    $ticket = ticketFor($this->teacher);
    app(AssignTicket::class)->handle($ticket, $this->technician, $this->admin, Request::create('/'));

    $urls = NotificationRecord::query()->whereNotNull('action_url')->pluck('action_url');

    expect($urls)->not->toBeEmpty();

    foreach ($urls as $url) {
        expect($url)->toStartWith('/app/')
            ->not->toContain('//')
            ->not->toContain('http');
    }
});

it('never publishes the idempotency key to a client', function (): void {
    $ticket = ticketFor($this->teacher);
    app(AssignTicket::class)->handle($ticket, $this->technician, $this->admin, Request::create('/'));

    /*
     * `first()`, not `sole()`: assigning a ticket legitimately produces two
     * notifications for the technician — the assignment itself (T1) and the
     * `open -> assigned` move the assignment causes (T2) — because the
     * administrator, not the technician, is the actor on both.
     */
    $row = NotificationRecord::query()->where('user_id', $this->technician->id)->firstOrFail();
    expect($row->dedupe_key)->not->toBeNull();

    $body = $this->actingAs($this->technician)->getJson('/api/notifications')->assertOk()->getContent();

    expect($body)->not->toContain('dedupe_key')
        ->not->toContain($row->dedupe_key)
        // Nor the internal primary keys the uuid exists to replace.
        ->not->toContain('"user_id"');
});
