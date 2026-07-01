<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SystemSetting>
 */
class SystemSettingFactory extends Factory
{
    protected $model = SystemSetting::class;

    public function definition(): array
    {
        $key = fake()->unique()->words(2, true);

        return [
            'group' => fake()->randomElement(['general', 'school', 'branding', 'email', 'ai']),
            'key' => Str::slug($key, '.'),
            'label' => ucwords($key),
            'value' => fake()->words(3),
            'type' => 'array',
            'description' => fake()->optional()->sentence(),
            'is_public' => false,
            'is_protected' => false,
        ];
    }
}
