<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ComponentType;
use App\Models\HardwareComponent;
use App\Models\Manufacturer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HardwareComponent>
 */
class HardwareComponentFactory extends Factory
{
    protected $model = HardwareComponent::class;

    public function definition(): array
    {
        return [
            'component_type' => fake()->randomElement(ComponentType::values()),
            'manufacturer_id' => Manufacturer::factory(),
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
