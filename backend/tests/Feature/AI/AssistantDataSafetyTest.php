<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Agents\AssistantAgent;
use App\Enums\AiModality;
use App\Enums\AssignmentStatus;
use App\Models\AiModel;
use App\Models\AiSystemSetting;
use App\Models\TicketComment;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Tests\Support\KnowledgeIndexFixtures as Fx;

/**
 * What actually leaves for Gemini (pre-commit fix pass, Phase 2.9; SRS FR-AI-030).
 *
 * The provider is a third party and its retention depends on the API's own
 * terms, so the application must not send what it does not need. These tests
 * read the **exact text handed to the provider** — the prompt and the agent's
 * instructions, captured by `laravel/ai`'s fake gateway — and assert, role by
 * role, that it carries the allowlisted context (role, capabilities, records
 * the caller may see) and nothing else: no credential, no contact detail, no
 * other person's record, no field a role is not shown in the API.
 *
 * No real provider call is made anywhere in this file.
 */
const SAFETY_PLAINTEXT = 'Zebra-Quasar-9182!';

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    Fx::configure();
    $chat = AiModel::factory()->create(['provider' => 'gemini', 'model_identifier' => 'test-chat', 'modality' => AiModality::Text->value]);
    AiSystemSetting::query()->update(['active_model_id' => $chat->id]);
    $seen = [];
    Fx::fakeProvider($seen);

    // Secrets of this deployment, set to values a test can look for.
    config([
        'ai.providers.gemini.key' => 'AIzaSyTESTKEY0000000000000000000000000',
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'database.connections.pgsql.password' => 'pg-super-secret-value',
    ]);

    $person = fn (string $role, string $first, string $last) => userWithRole($role, [
        'first_name' => $first,
        'last_name' => $last,
        'email' => strtolower($first).'.'.strtolower($last).'@school.test',
        'employee_number' => 'EMP-'.strtoupper($first),
        'contact_number' => '0917 555 0'.random_int(100, 999),
        'password' => SAFETY_PLAINTEXT,
    ]);

    $this->admin = $person('administrator', 'Adora', 'Aguinaldo');
    $this->techA = $person('technician', 'Tobias', 'Alpha');
    $this->techB = $person('technician', 'Tamsin', 'Bravo');
    $this->teacherA = $person('teacher', 'Teodora', 'Avila');
    $this->teacherB = $person('teacher', 'Teofilo', 'Bautista');

    AssistantAgent::fake(['ok']);
});

function safetyAsk($test, $user, string $message, array $extra = [])
{
    return $test->actingAs($user)->postJson('/api/ai/assistant/messages', ['message' => $message, ...$extra]);
}

/**
 * Everything handed to the provider: instructions and prompt, of every call.
 * `assertNotPrompted` with a callback that never matches is used to *visit*
 * each recorded prompt — `assertPrompted` stops at the first one.
 */
function sentToProvider(): string
{
    $all = '';
    AssistantAgent::assertNotPrompted(function ($prompt) use (&$all): bool {
        $all .= "\n".$prompt->agent->instructions()."\n".$prompt->prompt;

        return false;
    });

    return $all;
}

/** The identifying and credential fields of a user, as stored. */
function sensitiveOf($user): array
{
    $user->refresh();

    return array_values(array_filter([
        $user->email,
        $user->getAuthPassword(),           // the hash
        SAFETY_PLAINTEXT,                   // the plaintext
        $user->remember_token,
        $user->employee_number,
        $user->contact_number,
        $user->uuid,
        $user->first_name,
        $user->last_name,
    ]));
}

/* --------------------------------------------------------- what is allowed */

it('sends the allowlisted context: role, capabilities and the caller\'s own ticket', function () {
    ticketFor($this->teacherA, 'open', ['title' => 'Projector bulb flickers']);

    safetyAsk($this, $this->teacherA, 'projector bulb flickers')->assertOk();

    expect(sentToProvider())
        ->toContain('The user is a teacher')
        ->toContain('report problems as tickets')
        ->toContain('Projector bulb flickers')
        ->not->toContain('assign tickets to technicians');
});

it('describes a technician and an administrator by role and capability only', function () {
    safetyAsk($this, $this->techA, 'printer jams')->assertOk();
    expect(sentToProvider())->toContain('The user is an IT technician')->not->toContain('change system settings');

    AssistantAgent::fake(['ok']);
    safetyAsk($this, $this->admin, 'printer jams')->assertOk();
    expect(sentToProvider())->toContain('The user is an IT administrator')->toContain('assign tickets to technicians');
});

it('keeps the role description intact when an account is named after its role', function () {
    // The production demo accounts are named exactly this. Name redaction of
    // the prompt must not reach the server-built role line in the instructions.
    $tech = userWithRole('technician', ['first_name' => 'technician', 'last_name' => 'system']);

    safetyAsk($this, $tech, 'printer jams')->assertOk();

    expect(sentToProvider())->toContain('The user is an IT technician')->not->toContain('IT [person]');
});

/* ------------------------------------------------------ what never leaves */

it('never sends the caller\'s password, hash, email, token or identifiers', function (string $role) {
    $user = $this->{$role};
    ticketFor($this->teacherA, 'open', ['title' => 'Monitor goes black']);

    safetyAsk($this, $user, 'monitor goes black')->assertOk();

    $sent = sentToProvider();
    foreach (sensitiveOf($user) as $value) {
        expect($sent)->not->toContain($value);
    }
})->with(['admin', 'techA', 'teacherA']);

it('never sends session, CSRF or configured secrets — even when the user types them', function () {
    $this->actingAs($this->teacherA)->startSession();
    $session = session()->getId();
    $csrf = csrf_token();
    $hash = $this->teacherA->getAuthPassword();

    safetyAsk($this, $this->teacherA, implode("\n", [
        'My PC will not boot. In case it helps:',
        'my password is '.SAFETY_PLAINTEXT,
        'password: '.SAFETY_PLAINTEXT,
        'hash '.$hash,
        'email teodora.avila@school.test',
        "session_id={$session}",
        "token: {$csrf}",
        'Authorization: Bearer 12|abcdefghijklmnopqrstuvwxyz0123456789ABCD',
        'gemini key AIzaSyTESTKEY0000000000000000000000000',
        'app key '.config('app.key'),
        'db pg-super-secret-value',
        'cookie eyJpdiI6IkFCQ0RFRkdISUpLTE1OT1AiLCJ2YWx1ZSI6Inh5eiJ9',
    ]))->assertOk();

    $sent = sentToProvider();
    foreach ([SAFETY_PLAINTEXT, $hash, 'teodora.avila@school.test', $session, $csrf, 'abcdefghijklmnopqrstuvwxyz0123456789ABCD', 'AIzaSyTESTKEY', substr((string) config('app.key'), 7), 'pg-super-secret-value', 'eyJpdiI6'] as $secret) {
        expect($sent)->not->toContain($secret);
    }
    // The fault description itself survives.
    expect($sent)->toContain('My PC will not boot')->toContain('[secret]');
});

it('does not replay a credential from an earlier turn of the conversation', function () {
    $first = safetyAsk($this, $this->techA, 'my password is '.SAFETY_PLAINTEXT.' and the PC is slow')->assertOk();

    AssistantAgent::fake(['ok']);
    safetyAsk($this, $this->techA, 'and then?', ['conversation_id' => $first->json('data.conversation_id')])->assertOk();

    expect(sentToProvider())->toContain('the PC is slow')->not->toContain(SAFETY_PLAINTEXT);
});

/* ------------------------------------------------------- role isolation */

it('keeps Teacher B\'s tickets and identity away from Teacher A', function () {
    $theirs = ticketFor($this->teacherB, 'open', ['title' => 'Laptop charger sparks', 'description' => 'B-PRIVATE-DETAIL']);

    safetyAsk($this, $this->teacherA, 'laptop charger sparks')->assertOk();
    $sent = sentToProvider();
    expect($sent)->not->toContain('Laptop charger sparks')->not->toContain('B-PRIVATE-DETAIL');
    foreach (sensitiveOf($this->teacherB) as $value) {
        expect($sent)->not->toContain($value);
    }

    safetyAsk($this, $this->teacherA, 'help', ['ticket' => $theirs->uuid])->assertForbidden();
});

it('keeps Technician B\'s assigned work away from Technician A', function () {
    $bWork = ticketFor($this->teacherA, 'in-progress', ['title' => 'Server rack fan rattles', 'description' => 'TECH-B-NOTES']);
    assign($bWork, $this->techB, AssignmentStatus::Accepted);

    safetyAsk($this, $this->techA, 'server rack fan rattles')->assertOk();
    $sent = sentToProvider();
    expect($sent)->not->toContain('Server rack fan rattles')->not->toContain('TECH-B-NOTES');
    foreach (sensitiveOf($this->techB) as $value) {
        expect($sent)->not->toContain($value);
    }

    safetyAsk($this, $this->techA, 'help', ['ticket' => $bWork->uuid])->assertForbidden();
});

it('never gives a technician admin-only data: the AI summary or unassigned tickets', function () {
    $mine = ticketFor($this->teacherA, 'in-progress', ['title' => 'Scanner offline', 'ai_summary' => 'ADMIN-ONLY-SUMMARY']);
    assign($mine, $this->techA, AssignmentStatus::Accepted);
    ticketFor($this->teacherB, 'open', ['title' => 'Scanner offline in library']);

    safetyAsk($this, $this->techA, 'scanner offline', ['ticket' => $mine->uuid])->assertOk();

    expect(sentToProvider())
        ->toContain('Scanner offline')
        ->not->toContain('ADMIN-ONLY-SUMMARY')
        ->not->toContain('Scanner offline in library');
});

it('never gives a teacher technician- or admin-only data', function () {
    $mine = ticketFor($this->teacherA, 'in-progress', ['title' => 'Speaker hums', 'ai_summary' => 'ADMIN-ONLY-SUMMARY']);
    assign($mine, $this->techA, AssignmentStatus::Accepted);
    TicketComment::factory()->internal()->create(['ticket_id' => $mine->id, 'user_id' => $this->techA->id, 'body' => 'STAFF-INTERNAL-NOTE']);
    ticketFor($this->teacherB, 'open', ['title' => 'Speaker hums loudly']);

    safetyAsk($this, $this->teacherA, 'speaker hums', ['ticket' => $mine->uuid])->assertOk();

    $sent = sentToProvider();
    expect($sent)->toContain('Speaker hums')
        ->not->toContain('ADMIN-ONLY-SUMMARY')
        ->not->toContain('STAFF-INTERNAL-NOTE')
        ->not->toContain('Speaker hums loudly');
    // The assigned technician's name is removed from anything that mentions it.
    foreach (sensitiveOf($this->techA) as $value) {
        expect($sent)->not->toContain($value);
    }
});

it('gives an administrator the admin scope and the AI summary — but still no personal data', function () {
    $ticket = ticketFor($this->teacherA, 'open', ['title' => 'Router reboots', 'ai_summary' => 'ADMIN-ONLY-SUMMARY', 'description' => 'Teodora Avila says it reboots hourly.']);
    ticketFor($this->teacherB, 'open', ['title' => 'Router reboots nightly']);

    safetyAsk($this, $this->admin, 'router reboots', ['ticket' => $ticket->uuid])->assertOk();

    $sent = sentToProvider();
    expect($sent)->toContain('ADMIN-ONLY-SUMMARY')->toContain('Router reboots nightly')->toContain('reboots hourly');
    foreach ([...sensitiveOf($this->teacherA), ...sensitiveOf($this->teacherB), ...sensitiveOf($this->admin)] as $value) {
        expect($sent)->not->toContain($value);
    }
});

/* ----------------------------------------------- no client-chosen identity */

it('ignores a client-supplied user id, role or reporter — the server decides who is asking', function () {
    ticketFor($this->teacherB, 'open', ['title' => 'Webcam frozen', 'description' => 'B-PRIVATE-DETAIL']);

    safetyAsk($this, $this->teacherA, 'webcam frozen', [
        'user_id' => $this->teacherB->id,
        'user' => $this->teacherB->uuid,
        'reporter_id' => $this->teacherB->id,
        'role' => 'administrator',
    ])->assertOk();

    expect(sentToProvider())
        ->toContain('The user is a teacher')
        ->not->toContain('IT administrator')
        ->not->toContain('Webcam frozen')
        ->not->toContain('B-PRIVATE-DETAIL');
});
