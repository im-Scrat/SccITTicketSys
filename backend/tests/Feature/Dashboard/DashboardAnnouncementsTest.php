<?php

declare(strict_types=1);

use App\Enums\AnnouncementAudience;
use App\Models\Announcement;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    seedRbac();
    Cache::flush();
});

/**
 * @return array<string, mixed>|null
 */
function announcementWidget(array $widgets): ?array
{
    foreach ($widgets as $widget) {
        if ($widget['key'] === 'announcements') {
            return $widget;
        }
    }

    return null;
}

function widgetsFor(string $roleSlug): array
{
    Cache::flush();

    return test()->actingAs(userWithRole($roleSlug))
        ->getJson('/api/dashboard/widgets')
        ->assertOk()
        ->json('data.widgets');
}

it('omits the panel entirely when there is nothing to announce', function () {
    expect(announcementWidget(widgetsFor('teacher')))->toBeNull();
});

it('shows an all-audience announcement to every role', function () {
    Announcement::factory()->create([
        'title' => 'Network maintenance Saturday',
        'audience' => AnnouncementAudience::All->value,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
    ]);

    foreach (['administrator', 'technician', 'teacher'] as $roleSlug) {
        $widget = announcementWidget(widgetsFor($roleSlug));

        expect($widget)->not->toBeNull()
            ->and($widget['rows'][0]['title'])->toBe('Network maintenance Saturday');
    }
});

it('targets an audience-specific announcement to that role only', function () {
    Announcement::factory()->create([
        'title' => 'Technicians only',
        'audience' => AnnouncementAudience::Technicians->value,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
    ]);

    expect(announcementWidget(widgetsFor('technician')))->not->toBeNull()
        ->and(announcementWidget(widgetsFor('teacher')))->toBeNull()
        ->and(announcementWidget(widgetsFor('administrator')))->toBeNull();
});

it('hides inactive and out-of-window announcements', function () {
    Announcement::factory()->create([
        'title' => 'Inactive',
        'audience' => AnnouncementAudience::All->value,
        'is_active' => false,
        'starts_at' => null, 'ends_at' => null,
    ]);
    Announcement::factory()->create([
        'title' => 'Not started',
        'audience' => AnnouncementAudience::All->value,
        'is_active' => true,
        'starts_at' => now()->addWeek(), 'ends_at' => null,
    ]);
    Announcement::factory()->create([
        'title' => 'Expired',
        'audience' => AnnouncementAudience::All->value,
        'is_active' => true,
        'starts_at' => now()->subMonth(), 'ends_at' => now()->subWeek(),
    ]);

    expect(announcementWidget(widgetsFor('teacher')))->toBeNull();
});

it('puts pinned announcements first', function () {
    Announcement::factory()->create([
        'title' => 'Routine notice',
        'audience' => AnnouncementAudience::All->value,
        'is_active' => true, 'is_pinned' => false,
        'starts_at' => null, 'ends_at' => null,
    ]);
    Announcement::factory()->create([
        'title' => 'Pinned notice',
        'audience' => AnnouncementAudience::All->value,
        'is_active' => true, 'is_pinned' => true,
        'starts_at' => null, 'ends_at' => null,
    ]);

    $widget = announcementWidget(widgetsFor('teacher'));

    expect($widget['rows'][0]['title'])->toBe('Pinned notice')
        ->and($widget['rows'][0]['pinned'])->toBeTrue();
});

it('addresses announcements by uuid', function () {
    $announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::All->value,
        'is_active' => true, 'starts_at' => null, 'ends_at' => null,
    ]);

    $widget = announcementWidget(widgetsFor('teacher'));

    expect($widget['rows'][0]['id'])->toBe($announcement->uuid);
});
