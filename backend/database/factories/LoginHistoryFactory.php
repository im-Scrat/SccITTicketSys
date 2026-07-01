<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LoginStatus;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoginHistory>
 */
class LoginHistoryFactory extends Factory
{
    protected $model = LoginHistory::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'login_at' => now(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'browser' => fake()->randomElement(['Chrome', 'Firefox', 'Edge', 'Safari']),
            'platform' => fake()->randomElement(['Windows', 'macOS', 'Linux', 'Android', 'iOS']),
            'login_status' => LoginStatus::Success->value,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => ['login_status' => LoginStatus::Failed->value]);
    }
}
