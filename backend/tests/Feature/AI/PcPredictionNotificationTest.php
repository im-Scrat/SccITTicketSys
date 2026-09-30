<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Events\PcPredictionGenerated;
use App\Domains\KnowledgeBase\Notifications\PcPredictionGeneratedNotification;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Enums\NotificationType;
use App\Enums\UserStatus;
use App\Models\AiPrediction;
use App\Models\Notification as NotificationRecord;
use App\Models\NotificationPreference;
use App\Models\PcUnit;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * WP-M — telling administrators that a finding is waiting.
 *
 * Built on the notification infrastructure as WP-2.7a left it: the channel
 * driver, dispatcher, preference gate and policy are untouched. What is new is
 * one topic case, and the two claims worth holding are that *the right people
 * are told exactly once* and that *the baselined notification-type CHECK
 * constraint did not have to move*.
 *
 * (`PcPredictionReviewTest` covers the headline — every administrator, no
 * technician, nothing for a run that found nothing. These cover the edges.)
 */
beforeEach(function (): void {
    seedRbac();

    $this->admin = userWithRole('administrator');
    $this->otherAdmin = userWithRole('administrator');
    $this->suspendedAdmin = userWithRole('administrator', ['status' => UserStatus::Suspended->value]);
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->pc = PcUnit::factory()->create(['unit_code' => 'PC-NTF-01']);
    $this->prediction = AiPrediction::factory()->create([
        'pc_unit_id' => $this->pc->id,
        'predicted_issue' => 'Power supply failure',
        'explanation' => 'PRIVATE-MODEL-EXPLANATION',
        'recommendation' => 'PRIVATE-MODEL-RECOMMENDATION',
    ]);
});

/** Prediction notifications addressed to one person. */
function findingRowsFor(User $user): Collection
{
    return NotificationRecord::query()
        ->where('user_id', $user->id)
        ->where('data->topic', 'maintenance.prediction_generated')
        ->get();
}

it('tells no teacher and no suspended administrator', function (): void {
    event(new PcPredictionGenerated($this->prediction));

    expect(NotificationRecord::query()->where('user_id', $this->teacher->id)->count())->toBe(0)
        ->and(NotificationRecord::query()->where('user_id', $this->technician->id)->count())->toBe(0)
        ->and(NotificationRecord::query()->where('user_id', $this->suspendedAdmin->id)->count())->toBe(0)
        ->and(findingRowsFor($this->admin))->toHaveCount(1);
});

it('writes one row per administrator however many times the same finding is announced', function (): void {
    // A queue retry, or a replayed event: the key is the finding's own uuid, so
    // it collapses onto the row that already exists.
    event(new PcPredictionGenerated($this->prediction));
    event(new PcPredictionGenerated($this->prediction));
    event(new PcPredictionGenerated($this->prediction));

    expect(findingRowsFor($this->admin))->toHaveCount(1)
        ->and(findingRowsFor($this->otherAdmin))->toHaveCount(1);
});

it('tells them again about a genuinely later finding for the same machine', function (): void {
    event(new PcPredictionGenerated($this->prediction));

    $later = AiPrediction::factory()->create(['pc_unit_id' => $this->pc->id]);
    event(new PcPredictionGenerated($later));

    expect(findingRowsFor($this->admin))->toHaveCount(2);
});

it('keeps the model\'s explanation and recommendation out of what it says', function (): void {
    event(new PcPredictionGenerated($this->prediction));

    $row = findingRowsFor($this->admin)->sole();

    // The notification states the triage facts and nothing of the model's
    // prose; the full reading is one click away, behind authentication.
    expect(json_encode($row->only(['title', 'message', 'data', 'action_url'])))
        ->not->toContain('PRIVATE-')
        ->and($row->title)->toContain('PC-NTF-01');

    $mail = implode("\n", array_map(
        static fn ($line): string => (string) $line,
        (new PcPredictionGeneratedNotification($this->prediction))->toMail($this->admin)->introLines,
    ));

    expect($mail)->not->toContain('PRIVATE-');
});

it('honours an administrator who turned in-app maintenance notifications off', function (): void {
    NotificationPreference::factory()->create([
        'user_id' => $this->otherAdmin->id,
        'channel' => NotificationChannel::InApp->value,
        'notification_type' => NotificationType::Maintenance->value,
        'is_enabled' => false,
    ]);

    event(new PcPredictionGenerated($this->prediction));

    expect(findingRowsFor($this->admin))->toHaveCount(1)
        ->and(findingRowsFor($this->otherAdmin))->toHaveCount(0);
});

it('routes by the maintenance preference, in-app and email, with nothing forced', function (): void {
    $notification = new PcPredictionGeneratedNotification($this->prediction);

    expect($notification->via($this->admin))->toBe(['database', 'mail'])
        ->and($notification->forcedChannels())->toBe([])
        ->and($notification->excludedChannels())->toBe([]);

    NotificationPreference::factory()->create([
        'user_id' => $this->admin->id,
        'channel' => NotificationChannel::Email->value,
        'notification_type' => NotificationType::Maintenance->value,
        'is_enabled' => false,
    ]);

    expect($notification->via($this->admin->refresh()))->toBe(['database']);
});

it('links to a destination the system built, inside the application', function (): void {
    event(new PcPredictionGenerated($this->prediction));

    expect(findingRowsFor($this->admin)->sole()->action_url)->toBe("/app/predictions/{$this->prediction->uuid}");
});

it('reuses the maintenance type — the baselined CHECK constraint did not move', function (): void {
    // WP-M was told not to add a notification type if the constraint prevents
    // it. It did not need to: the topic files under `maintenance`, which the
    // Client's nine-value vocabulary already carries.
    expect(NotificationTopic::PcPredictionGenerated->type())->toBe(NotificationType::Maintenance)
        ->and(NotificationType::values())->toHaveCount(9)
        ->and(NotificationType::values())->toContain('maintenance');

    event(new PcPredictionGenerated($this->prediction));

    // The topic is a PHP-only identity carried in `data.topic`; it never reaches
    // a CHECK-constrained column.
    expect(NotificationRecord::query()->where('user_id', $this->admin->id)->sole()->getRawOriginal('type'))
        ->toBe('maintenance');
});

it('shows the administrator the notification in their own centre, and nobody else', function (): void {
    event(new PcPredictionGenerated($this->prediction));

    $centre = $this->withHeader('Origin', (string) config('app.url'))
        ->actingAs($this->admin)
        ->getJson('/api/notifications')
        ->assertOk();

    expect(collect($centre->json('data'))->pluck('topic')->all())->toContain('maintenance.prediction_generated');

    // Ownership is the existing NotificationPolicy's job and covers the new row
    // exactly as it covers every other — no special case was added or needed.
    $uuid = findingRowsFor($this->admin)->sole()->uuid;

    foreach ([$this->technician, $this->teacher, $this->otherAdmin] as $intruder) {
        $this->withHeader('Origin', (string) config('app.url'))
            ->actingAs($intruder)
            ->getJson("/api/notifications/{$uuid}")
            ->assertForbidden();
    }
});
