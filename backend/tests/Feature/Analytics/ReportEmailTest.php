<?php

declare(strict_types=1);

use App\Domains\Analytics\Notifications\ReportEmailNotification;
use App\Enums\ActivityAction;
use App\Enums\ReportContentProfile;
use App\Enums\ReportEmailDeliveryStatus;
use App\Models\ActivityLog;
use App\Models\ReportEmailDelivery;
use App\Models\ReportExport;
use App\Models\User;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\Support\ReportFixtures;

/*
 * Phase 2.10 R3 — emailing a report to any address, with safeguards (owner
 * decision D1, SDD DD-92).
 *
 * The attachment is identifier-only whoever receives it, so the test files a
 * ticket with a distinctive title and proves those bytes never leave; outside
 * addresses must be confirmed exactly; the per-day cap holds.
 */

beforeEach(function (): void {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    Storage::fake('local');
    $this->travelTo(ReportFixtures::local('2026-10-18 10:00')->utc());
    config(['mail.from.address' => 'noreply@sccit.local', 'reports.email.internal_domains' => []]);

    $this->admin = userWithRole('administrator');
    $room = ReportFixtures::room(ReportFixtures::floor(), 'laboratory', ['code' => 'LAB-301', 'name' => '[click here](http://evil.example)']);
    ReportFixtures::ticketAt(userWithRole('teacher'), '2026-10-03 09:00', 'open', [
        'room_id' => $room->id,
        'title' => 'SECRET-TITLE-7731 projector',
        'description' => 'SECRET-DESCRIPTION-7731',
    ]);

    $this->body = fn (array $extra = []): array => [
        'report_type' => 'ticket_overview',
        'format' => 'xlsx',
        'include_records' => true,
        'recipients' => ['head@sccit.local', 'auditor@example.org'],
        'confirmed_external' => ['auditor@example.org'],
        ...$extra,
    ];
});

it('says which addresses are outside the school and what would be sent', function (): void {
    $preview = $this->actingAs($this->admin)
        ->postJson('/api/admin/reports/emails/check-recipients', ($this->body)(['recipients' => " Head@SCCIT.local,\nauditor@example.org; head@sccit.local "]))
        ->assertOk()
        ->json('data');

    expect(array_column($preview['recipients'], 'address'))->toBe(['head@sccit.local', 'auditor@example.org'])
        ->and($preview['external'])->toBe(['auditor@example.org'])
        ->and($preview['delivers_externally'])->toBeFalse()
        ->and(implode(' ', $preview['contents']['excludes']))->toContain('No ticket titles');
});

it('never treats a public mailbox provider as inside the school, even when the system sends from one', function (): void {
    // The Render pilot sends from a single verified Gmail sender: every Gmail
    // address in the world must still need the outside-address confirmation.
    config(['mail.from.address' => 'pilot.sender@gmail.com']);

    $preview = $this->actingAs($this->admin)
        ->postJson('/api/admin/reports/emails/check-recipients', ($this->body)(['recipients' => 'stranger@gmail.com']))
        ->assertOk()
        ->json('data');

    expect($preview['external'])->toBe(['stranger@gmail.com'])
        ->and($preview['internal_domains'] ?? [])->not->toContain('gmail.com');
});

it('still treats the school’s own sending domain as inside the school', function (): void {
    config(['mail.from.address' => 'noreply@school.example.edu']);

    $preview = $this->actingAs($this->admin)
        ->postJson('/api/admin/reports/emails/check-recipients', ($this->body)(['recipients' => 'head@school.example.edu']))
        ->assertOk()
        ->json('data');

    expect($preview['external'])->toBe([]);
});

it('sends one identifier-only copy per recipient, and records each as sent', function (): void {
    $this->actingAs($this->admin)->postJson('/api/admin/reports/emails', ($this->body)())->assertStatus(202);

    $export = ReportExport::query()->sole();
    $file = (string) Storage::disk('local')->get((string) $export->path);
    $unzipped = '';
    $tmp = tempnam(sys_get_temp_dir(), 'em');
    file_put_contents($tmp, $file);
    $zip = new ZipArchive;
    $zip->open($tmp);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $unzipped .= (string) $zip->getFromIndex($i);
    }
    $zip->close();
    @unlink($tmp);

    expect($export->profile)->toBe(ReportContentProfile::Identifiers)
        ->and($unzipped)->not->toContain('SECRET-TITLE-7731')
        ->and($unzipped)->not->toContain('SECRET-DESCRIPTION-7731')
        ->and($unzipped)->toContain('LAB-301')
        ->and(ReportEmailDelivery::query()->pluck('status')->all())->toBe([ReportEmailDeliveryStatus::Sent, ReportEmailDeliveryStatus::Sent]);
});

it('audits the request with its recipients and the confirmed outside addresses', function (): void {
    $this->actingAs($this->admin)->postJson('/api/admin/reports/emails', ($this->body)())->assertStatus(202);

    $log = ActivityLog::query()->where('action', ActivityAction::ReportEmailRequested->value)->sole();
    expect($log->properties['recipients'])->toBe(['head@sccit.local', 'auditor@example.org'])
        ->and($log->properties['confirmed_external'])->toBe(['auditor@example.org']);
});

it('queues one notification per recipient with the file attached', function (): void {
    Notification::fake();

    $this->actingAs($this->admin)->postJson('/api/admin/reports/emails', ($this->body)())->assertStatus(202);

    Notification::assertSentOnDemandTimes(ReportEmailNotification::class, 2);
    Notification::assertSentOnDemand(ReportEmailNotification::class, function (ReportEmailNotification $n, array $channels, AnonymousNotifiable $notifiable): bool {
        $mail = $n->toMail($notifiable);

        return $notifiable->routes['mail'] === 'auditor@example.org'
            && count($mail->rawAttachments) + count($mail->attachments) === 1
            && ! str_contains(implode(' ', $mail->introLines), 'Administrator User')
            && $n->delivery->is_external;
    });
});

it('refuses an outside address that was not confirmed', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/admin/reports/emails', ($this->body)(['confirmed_external' => []]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('confirmed_external');

    expect(ReportExport::query()->count())->toBe(0);
});

it('refuses a confirmation list that does not match the outside addresses exactly', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/admin/reports/emails', ($this->body)(['confirmed_external' => ['auditor@example.org', 'someone@else.example']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('confirmed_external');
});

it('refuses more than ten recipients', function (): void {
    $many = array_map(static fn (int $i): string => "staff{$i}@sccit.local", range(1, 11));

    $this->actingAs($this->admin)
        ->postJson('/api/admin/reports/emails', ($this->body)(['recipients' => $many, 'confirmed_external' => []]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recipients');
});

it('refuses an invalid address', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/admin/reports/emails/check-recipients', ($this->body)(['recipients' => ['not-an-address']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recipients.0');
});

it('refuses CSV as an email format', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/admin/reports/emails/check-recipients', ($this->body)(['format' => 'csv']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('format');
});

it('holds the daily recipient cap', function (): void {
    config(['reports.email.daily_cap' => 3]);

    $this->actingAs($this->admin)->postJson('/api/admin/reports/emails', ($this->body)())->assertStatus(202);
    $this->actingAs($this->admin)
        ->postJson('/api/admin/reports/emails', ($this->body)())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recipients');

    expect(ReportEmailDelivery::query()->count())->toBe(2);
});

it('refuses, in the database itself, an emailed document that is not identifier-only', function (): void {
    expect(fn () => DB::table('report_exports')->insert([
        'user_id' => $this->admin->id,
        'report_type' => 'ticket_overview',
        'format' => 'pdf',
        'status' => 'pending',
        'profile' => 'full',
        'purpose' => 'email',
        'filters' => '{}',
        'options' => '{}',
        'as_of' => now(),
        'dedupe_key' => str_repeat('a', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'report_exports_email_profile_check');
});

it('renders a Markdown link in a value as text in the email', function (): void {
    Notification::fake();
    $this->actingAs($this->admin)->postJson('/api/admin/reports/emails', ($this->body)())->assertStatus(202);

    Notification::assertSentOnDemand(ReportEmailNotification::class, function (ReportEmailNotification $n, array $channels, AnonymousNotifiable $notifiable): bool {
        $html = (string) $n->toMail($notifiable)->render();

        return ! str_contains($html, 'href="http://evil.example"');
    });
});

it('marks copies still queued after a day as failed', function (): void {
    Notification::fake();
    $this->actingAs($this->admin)->postJson('/api/admin/reports/emails', ($this->body)())->assertStatus(202);
    $this->travel(25)->hours();

    $this->artisan('reports:prune-exports')->assertSuccessful();

    expect(ReportEmailDelivery::query()->pluck('failure_code')->unique()->all())->toBe(['stale']);
});

it('sends to an outside address that has no account, from the system sender, with no reply-to', function (): void {
    $this->actingAs($this->admin)->postJson('/api/admin/reports/emails', ($this->body)())->assertStatus(202);

    expect(User::query()->where('email', 'auditor@example.org')->exists())->toBeFalse();

    $messages = collect(Mail::mailer()->getSymfonyTransport()->messages())
        ->map(fn ($sent) => $sent->getOriginalMessage());
    $outside = $messages->first(fn ($m) => collect($m->getTo())->contains(fn ($a) => $a->getAddress() === 'auditor@example.org'));

    expect($outside)->not->toBeNull()
        ->and($outside->getFrom()[0]->getAddress())->toBe('noreply@sccit.local')
        ->and($outside->getReplyTo())->toBe([])
        ->and(collect($outside->getTo())->map->getAddress()->all())->toBe(['auditor@example.org']);
});

it('records why a copy failed, by SMTP reply code, and a later success as sent', function (): void {
    Notification::fake();
    $this->actingAs($this->admin)->postJson('/api/admin/reports/emails', ($this->body)())->assertStatus(202);

    $delivery = ReportEmailDelivery::query()->where('recipient', 'auditor@example.org')->sole();
    $notification = new ReportEmailNotification(ReportExport::query()->sole(), $delivery);
    $fail = fn (Throwable $e) => event(new NotificationFailed(new AnonymousNotifiable, $notification, 'mail', ['exception' => $e]));
    $code = fn (): ?string => $delivery->fresh()?->failure_code;

    $fail(new TransportException('Failed to authenticate on SMTP server with username "relay-user".', 535));
    expect($code())->toBe('smtp_auth_failed');

    $fail(new UnexpectedResponseException('Expected response code "250" but got code "550".', 550));
    expect($code())->toBe('smtp_rejected');

    $fail(new UnexpectedResponseException('Expected response code "250" but got code "421".', 421));
    expect($code())->toBe('smtp_deferred');

    $fail(new TransportException('Connection could not be established with host "mail.smtp2go.com:2525": timed out'));
    expect($code())->toBe('connection_failed');

    event(new NotificationSent(new AnonymousNotifiable, $notification, 'mail'));
    expect($delivery->fresh()?->status)->toBe(ReportEmailDeliveryStatus::Sent)
        ->and($code())->toBeNull();
});
