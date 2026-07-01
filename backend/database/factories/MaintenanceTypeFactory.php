<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MaintenanceType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MaintenanceType>
 */
class MaintenanceTypeFactory extends Factory
{
    protected $model = MaintenanceType::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucwords($name),
            'slug' => Str::slug($name),
            'description' => fake()->optional()->sentence(),
            'is_preventive' => fake()->boolean(),
            'is_active' => true,
        ];
    }
}
