<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Agents\AssistantAgent;
use App\Domains\KnowledgeBase\Assistant\AssistantTool;
use App\Domains\KnowledgeBase\Assistant\Tools\TechnicianWorkloadTool;
use App\Domains\KnowledgeBase\Services\AssistantToolbox;
use App\Enums\AssignmentStatus;
use App\Models\Announcement;
use App\Models\Asset;
use App\Models\MaintenanceRecord;
use App\Models\Notification;
use App\Models\TicketComment;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Tools\Request;
use Tests\Support\KnowledgeIndexFixtures as Fx;

/**
 * The assistant's lookups (pre-commit fix pass 2, Phase 2.9; SRS FR-AI-004/
 * 030/032; SDD DD-40/DD-75).
 *
 * Each tool is called exactly as the generation loop calls it — `handle()`
 * with the model's arguments — as each role, and its *output text* is read:
 * that text goes to the provider verbatim. The assertions are the row-scoping
 * rules the list endpoints already obey, plus "no person's name, address or
 * credential leaves".
 */
const TOOL_PLAINTEXT = 'Orbit-Lantern-4471!';

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);
    Fx::configure();

    $person = fn (string $role, string $first, string $last) => userWithRole($role, [
        'first_name' => $first,
        'last_name' => $last,
        'email' => strtolower("{$first}.{$last}@school.test"),
        'password' => TOOL_PLAINTEXT,
    ]);

    $this->admin = $person('administrator', 'Adora', 'Aguinaldo');
    $this->techA = $person('technician', 'Tobias', 'Alpha');
    $this->techB = $person('technician', 'Tamsin', 'Bravo');
    $this->teacherA = $person('teacher', 'Teodora', 'Avila');
    $this->teacherB = $person('teacher', 'Teofilo', 'Bautista');

    $this->toolbox = app(AssistantToolbox::class);
});

/** The named tool as this user holds it, or null when the toolbox withholds it. */
function toolFor($user, string $name): ?AssistantTool
{
    foreach (app(AssistantToolbox::class)->for($user) as $tool) {
        if ($tool->name() === $name) {
            return $tool;
        }
    }

    return null;
}

function call($user, string $name, array $args = []): string
{
    $tool = toolFor($user, $name);
    expect($tool)->not->toBeNull("{$name} is not offered to this user");

    return $tool->handle(new Request($args));
}

/** Everything about a user that must never appear in a tool's output. */
function privateOf($user): array
{
    $user->refresh();

    return array_values(array_filter([$user->first_name, $user->last_name, $user->email, $user->getAuthPassword(), TOOL_PLAINTEXT, $user->remember_token]));
}

/* ------------------------------------------------------------- the toolbox */

it('offers each role only the lookups its pages would let it load', function () {
    $names = fn ($user) => array_map(fn (AssistantTool $t) => $t->name(), app(AssistantToolbox::class)->for($user));

    expect($names($this->teacherA))->toContain('search_tickets', 'get_ticket', 'account_overview', 'my_notifications', 'announcements', 'search_knowledge')
        ->not->toContain('search_maintenance', 'system_overview', 'technician_workload');

    expect($names($this->techA))->toContain('search_tickets', 'search_maintenance')
        ->not->toContain('system_overview', 'technician_workload');

    expect($names($this->admin))->toContain('system_overview', 'technician_workload', 'search_maintenance');
});

it('bounds one question to a few provider round-trips', function () {
    $attributes = (new ReflectionClass(AssistantAgent::class))->getAttributes(MaxSteps::class);

    expect($attributes)->toHaveCount(1)
        ->and($attributes[0]->newInstance()->value)->toBeLessThanOrEqual(4);
});

it('contains no write path in any tool source', function () {
    $files = glob(app_path('Domains/KnowledgeBase/Assistant/Tools/*.php')) ?: [];
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        foreach (['->save(', '->update(', '->delete(', '->create(', '::create(', '->insert(', 'forceFill(', '->increment(', '->decrement(', 'dispatch(', '->touch(', 'forceDelete', 'truncate('] as $write) {
            expect($source)->not->toContain($write, basename($file)." contains {$write}");
        }
    }
});

/* ------------------------------------------------------ tickets, per role */

it('counts and lists only the tickets Teacher A reported', function () {
    ticketFor($this->teacherA, 'open', ['title' => 'Projector flickers']);
    ticketFor($this->teacherA, 'resolved', ['title' => 'Mouse double-clicks']);
    ticketFor($this->teacherB, 'open', ['title' => 'B-ONLY projector dead']);

    $out = call($this->teacherA, 'search_tickets');

    expect($out)->toContain('tickets you reported — 2 total')
        ->toContain('Projector flickers')->toContain('Mouse double-clicks')
        ->not->toContain('B-ONLY');
    foreach (privateOf($this->teacherB) as $value) {
        expect($out)->not->toContain($value);
    }
});

it('answers "how many have I reported" from the account summary alone', function () {
    ticketFor($this->teacherA, 'open');
    ticketFor($this->teacherA, 'open');
    ticketFor($this->teacherA, 'resolved');
    ticketFor($this->teacherB, 'open');

    $out = call($this->teacherA, 'account_overview');

    expect($out)->toContain('Tickets you reported: 3 total')->toContain('Open 2')->toContain('My tickets: /app/tickets/mine');
    foreach ([...privateOf($this->teacherA), ...privateOf($this->teacherB)] as $value) {
        expect($out)->not->toContain($value);
    }
});

it('shows a technician only tickets they are assigned to, and the admin everything', function () {
    $a = ticketFor($this->teacherA, 'in-progress', ['title' => 'Keyboard stuck A']);
    $b = ticketFor($this->teacherB, 'in-progress', ['title' => 'Keyboard stuck B']);
    assign($a, $this->techA, AssignmentStatus::Accepted);
    assign($b, $this->techB, AssignmentStatus::Accepted);

    expect(call($this->techA, 'search_tickets'))->toContain('tickets assigned to you — 1 total')->toContain('Keyboard stuck A')->not->toContain('Keyboard stuck B');
    expect(call($this->techB, 'search_tickets'))->toContain('Keyboard stuck B')->not->toContain('Keyboard stuck A');
    expect(call($this->admin, 'search_tickets'))->toContain('all tickets in the system — 2 total');
});

it('refuses another person\'s ticket by reference exactly like a missing one', function () {
    $theirs = ticketFor($this->teacherB, 'open', ['title' => 'Private B ticket', 'description' => 'B-PRIVATE-DETAIL']);
    $bWork = ticketFor($this->teacherA, 'in-progress', ['title' => 'Tech B work']);
    assign($bWork, $this->techB, AssignmentStatus::Accepted);

    $missing = call($this->teacherA, 'get_ticket', ['reference' => 'TKT-2099-99999']);
    $forbidden = call($this->teacherA, 'get_ticket', ['reference' => $theirs->ticket_number]);

    expect($forbidden)->toBe($missing)->not->toContain('B-PRIVATE-DETAIL');
    expect(call($this->techA, 'get_ticket', ['reference' => $bWork->ticket_number]))->toBe($missing);
});

it('hides internal notes and the AI summary from a teacher, shows both to staff who may see them', function () {
    $ticket = ticketFor($this->teacherA, 'in-progress', ['title' => 'Speaker hums', 'ai_summary' => 'ADMIN-ONLY-SUMMARY']);
    assign($ticket, $this->techA, AssignmentStatus::Accepted);
    TicketComment::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $this->techA->id, 'body' => 'PUBLIC-REPLY']);
    TicketComment::factory()->internal()->create(['ticket_id' => $ticket->id, 'user_id' => $this->techA->id, 'body' => 'STAFF-INTERNAL-NOTE']);

    $teacher = call($this->teacherA, 'get_ticket', ['reference' => $ticket->ticket_number]);
    expect($teacher)->toContain('Speaker hums')->toContain('PUBLIC-REPLY')->toContain('Reported by: you')
        ->not->toContain('STAFF-INTERNAL-NOTE')->not->toContain('ADMIN-ONLY-SUMMARY');
    foreach (privateOf($this->techA) as $value) {
        expect($teacher)->not->toContain($value);
    }

    $tech = call($this->techA, 'get_ticket', ['reference' => $ticket->ticket_number]);
    expect($tech)->toContain('STAFF-INTERNAL-NOTE')->toContain('Assigned to: you')->not->toContain('ADMIN-ONLY-SUMMARY');
    foreach (privateOf($this->teacherA) as $value) {
        expect($tech)->not->toContain($value);
    }

    expect(call($this->admin, 'get_ticket', ['reference' => $ticket->ticket_number]))->toContain('ADMIN-ONLY-SUMMARY');
});

it('ignores any attempt to name a user in the arguments', function () {
    ticketFor($this->teacherB, 'open', ['title' => 'B-ONLY webcam frozen']);

    $out = call($this->teacherA, 'search_tickets', ['user_id' => $this->teacherB->id, 'reporter_id' => $this->teacherB->id, 'role' => 'administrator']);

    expect($out)->toContain('tickets you reported — 0 total')->not->toContain('B-ONLY');
});

/* ------------------------------------------------ maintenance, assets, etc. */

it('scopes maintenance to the technician\'s own records and refuses a teacher', function () {
    MaintenanceRecord::factory()->create(['technician_id' => $this->techA->id, 'title' => 'Clean fans A', 'status' => 'scheduled']);
    MaintenanceRecord::factory()->create(['technician_id' => $this->techB->id, 'title' => 'Clean fans B', 'status' => 'scheduled']);

    expect(call($this->techA, 'search_maintenance'))->toContain('your maintenance — 1 total')->toContain('Clean fans A')->not->toContain('Clean fans B');
    expect(call($this->admin, 'search_maintenance'))->toContain('all maintenance — 2 total');
    expect(toolFor($this->teacherA, 'search_maintenance'))->toBeNull();
});

it('lists only equipment in the user\'s own custody', function () {
    Asset::factory()->create(['assigned_technician_id' => $this->techA->id, 'asset_tag' => 'LAB-A-01']);
    Asset::factory()->create(['assigned_technician_id' => $this->techB->id, 'asset_tag' => 'LAB-B-01']);

    expect(call($this->techA, 'my_assets'))->toContain('LAB-A-01')->not->toContain('LAB-B-01');
    expect(call($this->techA, 'my_assets', ['tag' => 'LAB-B-01']))->toContain('No asset with that tag is available to you');
    expect(call($this->admin, 'my_assets', ['tag' => 'LAB-B-01']))->toContain('Asset tag: LAB-B-01')->not->toContain('Bravo');
});

it('reads only the user\'s own notifications', function () {
    Notification::factory()->create(['user_id' => $this->teacherA->id, 'title' => 'Your ticket was assigned', 'read_at' => null]);
    Notification::factory()->create(['user_id' => $this->teacherB->id, 'title' => 'B-ONLY notification', 'read_at' => null]);

    expect(call($this->teacherA, 'my_notifications'))->toContain('Unread notifications: 1')->toContain('Your ticket was assigned')->not->toContain('B-ONLY');
});

it('withholds admin-only lookups from other roles and refuses them if reached', function () {
    expect(toolFor($this->teacherA, 'system_overview'))->toBeNull();
    expect(toolFor($this->techA, 'technician_workload'))->toBeNull();

    // Even a tool constructed directly for a non-administrator refuses.
    $tool = app(TechnicianWorkloadTool::class, ['user' => $this->techA]);
    expect($tool->handle(new Request))->toContain('Only an administrator');
});

it('gives the administrator workload without technician names', function () {
    $t = ticketFor($this->teacherA, 'in-progress', ['assigned_technician_id' => $this->techA->id]);
    assign($t, $this->techA, AssignmentStatus::Accepted);

    $out = call($this->admin, 'technician_workload');

    expect($out)->toContain('Technician 1: 1 open')->not->toContain('Tobias')->not->toContain('Alpha');
});

it('redacts a credential or address a tool happens to read back', function () {
    ticketFor($this->teacherA, 'open', ['title' => 'Login help: my password is '.TOOL_PLAINTEXT.' and mail teodora.avila@school.test']);

    $out = call($this->teacherA, 'search_tickets');

    expect($out)->toContain('Login help')->not->toContain(TOOL_PLAINTEXT)->not->toContain('teodora.avila@school.test');
});

it('turns a bad argument into a sentence, never an exception', function () {
    expect(call($this->teacherA, 'get_ticket', []))->toContain('Invalid arguments');
    expect(call($this->teacherA, 'search_tickets', ['days' => 'yesterday']))->toContain('Invalid arguments');
});

it('reads announcements addressed to the user', function () {
    Announcement::factory()->create(['title' => 'Lab closed Friday', 'content' => 'Maintenance window.', 'audience' => 'all', 'is_active' => true]);

    expect(call($this->teacherA, 'announcements'))->toContain('Lab closed Friday');
});
