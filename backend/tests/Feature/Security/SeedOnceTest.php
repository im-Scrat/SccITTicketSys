<?php

declare(strict_types=1);

use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * A re-run of `db:seed --force` against a live database must not undo what an
 * administrator changed. Before 2026-10-20 both seeders used updateOrCreate:
 * the administrator's password was reset to ADMIN_PASSWORD (or "password"),
 * and every system setting was put back to its default.
 */
beforeEach(fn () => seedRbac());

it('creates the first administrator on a fresh install', function () {
    $this->seed(AdminUserSeeder::class);

    $admin = User::query()->where('email', env('ADMIN_EMAIL', 'admin@school.test'))->firstOrFail();
    expect($admin->role->slug)->toBe('administrator');
});

it('leaves an existing administrator password untouched on a re-seed', function () {
    $this->seed(AdminUserSeeder::class);
    $admin = User::query()->where('email', env('ADMIN_EMAIL', 'admin@school.test'))->firstOrFail();
    $admin->forceFill(['password' => Hash::make('chosen-by-the-owner-2026')])->save();

    $this->seed(AdminUserSeeder::class);

    expect(Hash::check('chosen-by-the-owner-2026', (string) $admin->fresh()->password))->toBeTrue();
    expect(User::query()->where('email', $admin->email)->count())->toBe(1);
});

it('keeps an administrator-changed setting value on a re-seed, but still adds new settings', function () {
    $this->seed(SystemSettingSeeder::class);
    SystemSetting::query()->where('key', 'school.name')->update(['value' => json_encode('Real School Name')]);
    SystemSetting::query()->where('key', 'tickets.auto_close_days')->delete();
    $before = SystemSetting::query()->where('key', 'school.name')->value('value');

    $this->seed(SystemSettingSeeder::class);

    expect(SystemSetting::query()->where('key', 'school.name')->value('value'))->toBe($before);
    expect(SystemSetting::query()->where('key', 'tickets.auto_close_days')->exists())->toBeTrue();
});
