<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnnouncementAudience;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'title' => fake()->sentence(6),
            'content' => fake()->paragraphs(2, true),
            'audience' => AnnouncementAudience::All->value,
            'starts_at' => now(),
            'ends_at' => now()->addDays(fake()->numberBetween(3, 30)),
            'is_active' => true,
            'is_pinned' => false,
        ];
    }
}
