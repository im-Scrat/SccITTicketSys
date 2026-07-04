<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'employee_number' => fake()->unique()->numerify('EMP-#####'),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'contact_number' => fake()->optional()->phoneNumber(),
            'status' => UserStatus::Active->value,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UserStatus::Suspended->value]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Pending->value,
            'email_verified_at' => null,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Rejected->value,
            'rejection_reason' => fake()->sentence(),
            'rejected_at' => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UserStatus::Inactive->value]);
    }
}
