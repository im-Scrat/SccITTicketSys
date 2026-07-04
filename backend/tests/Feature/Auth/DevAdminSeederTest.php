<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DevAdminSeeder;

beforeEach(fn () => seedRbac());

it('does not create the local dev admin outside the local environment', function () {
    // The test environment is "testing" (not "local"), so the seeder must no-op.
    expect(app()->environment('local'))->toBeFalse();

    $this->seed(DevAdminSeeder::class);

    expect(User::query()->where('email', 'arttesting@sccit.local')->exists())->toBeFalse();
});
