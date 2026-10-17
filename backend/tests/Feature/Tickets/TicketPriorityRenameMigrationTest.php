<?php

declare(strict_types=1);

use App\Domains\Tickets\Notifications\TicketAssignedNotification;
use App\Enums\TicketUpdateType;
use App\Models\TicketPriority;
use App\Models\TicketUpdate;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Phase 2.9 — "Critical" is shown as "Urgent" (SDD DD-76; Client decision
 * 2026-10-16). Names are display data; the slug `critical` is the contract and
 * never changes, nor do level 4, the red colour or the 15/480-minute budget.
 *
 * The migration is guarded the way a data change to a live lookup table must
 * be: it renames only the untouched seeded name, never into a collision, never
 * an administrator's own name, and never rewrites history.
 *
 * A fresh test database has no priorities when migrations run, so the seeder
 * already says "Urgent"; each migration test first puts the old name back.
 */

const URGENT_RENAME_MIGRATION = '2026_10_16_110000_rename_critical_priority_to_urgent.php';

function urgentRename(): object
{
    return require database_path('migrations/'.URGENT_RENAME_MIGRATION);
}

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->teacher = userWithRole('teacher');
});

it('seeds the top level as Urgent, with the slug and budget unchanged', function () {
    $row = TicketPriority::query()->where('slug', 'critical')->sole();

    expect($row->name)->toBe('Urgent')
        ->and($row->level)->toBe(4)
        ->and($row->color)->toBe('#ef4444')
        ->and($row->response_time_minutes)->toBe(15)
        ->and($row->resolution_time_minutes)->toBe(480);
});

it('renames the seeded Critical to Urgent and touches nothing else', function () {
    DB::table('ticket_priorities')->where('slug', 'critical')->update(['name' => 'Critical']);
    $before = DB::table('ticket_priorities')->where('slug', 'critical')->first();

    urgentRename()->up();

    $after = DB::table('ticket_priorities')->where('slug', 'critical')->first();

    expect($after->name)->toBe('Urgent');

    foreach (['id', 'slug', 'level', 'color', 'response_time_minutes', 'resolution_time_minutes', 'is_active'] as $column) {
        expect($after->{$column})->toBe($before->{$column}, $column);
    }
});

it('leaves a name an administrator chose alone', function () {
    DB::table('ticket_priorities')->where('slug', 'critical')->update(['name' => 'Emergency']);

    urgentRename()->up();

    expect(TicketPriority::query()->where('slug', 'critical')->value('name'))->toBe('Emergency');
});

it('does not rename into a name another level already has', function () {
    // `ticket_priorities.name` is unique: renaming into the collision would
    // fail the deploy, so the migration steps aside instead.
    DB::table('ticket_priorities')->where('slug', 'critical')->update(['name' => 'Critical']);
    DB::table('ticket_priorities')->where('slug', 'high')->update(['name' => 'Urgent']);

    urgentRename()->up();

    expect(TicketPriority::query()->where('slug', 'critical')->value('name'))->toBe('Critical')
        ->and(TicketPriority::query()->where('slug', 'high')->value('name'))->toBe('Urgent');
});

it('is idempotent', function () {
    DB::table('ticket_priorities')->where('slug', 'critical')->update(['name' => 'Critical']);

    urgentRename()->up();
    urgentRename()->up();

    expect(TicketPriority::query()->where('slug', 'critical')->value('name'))->toBe('Urgent')
        ->and(TicketPriority::query()->where('name', 'Urgent')->count())->toBe(1);
});

it('rolls back to Critical only while the name is still Urgent', function () {
    urgentRename()->down();
    expect(TicketPriority::query()->where('slug', 'critical')->value('name'))->toBe('Critical');

    DB::table('ticket_priorities')->where('slug', 'critical')->update(['name' => 'Emergency']);
    urgentRename()->down();
    expect(TicketPriority::query()->where('slug', 'critical')->value('name'))->toBe('Emergency');
});

it('never rewrites the name history recorded at the time', function () {
    DB::table('ticket_priorities')->where('slug', 'critical')->update(['name' => 'Critical']);
    $ticket = ticketFor($this->teacher);

    $update = TicketUpdate::query()->create([
        'ticket_id' => $ticket->id,
        'user_id' => null,
        'update_type' => TicketUpdateType::PriorityChange->value,
        'body' => null,
        'metadata' => ['from' => 'medium', 'from_label' => 'Medium', 'to' => 'critical', 'to_label' => 'Critical'],
        'created_at' => now(),
    ]);

    urgentRename()->up();

    expect($update->fresh()->metadata['to_label'])->toBe('Critical');
});

it('names the level in the assignment mail, not the slug', function () {
    $ticket = ticketFor($this->teacher, 'assigned', [
        'priority_id' => TicketPriority::query()->where('slug', 'critical')->value('id'),
    ]);
    $technician = userWithRole('technician');

    $mail = (new TicketAssignedNotification($ticket, 1, false))->toMail($technician);

    expect($mail->introLines)->toContain('Priority: Urgent.')
        ->and($mail->introLines)->not->toContain('Priority: critical.');
});

it('offers a teacher the level as Urgent, keyed by the unchanged slug', function () {
    $priorities = $this->actingAs($this->teacher)->getJson('/api/tickets/options')->assertOk()->json('data.priorities');

    expect(collect($priorities)->firstWhere('value', 'critical'))
        ->toMatchArray(['value' => 'critical', 'label' => 'Urgent', 'level' => 4]);
});
