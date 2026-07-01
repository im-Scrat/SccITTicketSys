<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\HardwareComponent;
use App\Models\HardwareModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HardwareModel>
 */
class HardwareModelFactory extends Factory
{
    protected $model = HardwareModel::class;

    public function definition(): array
    {
        return [
            'hardware_component_id' => HardwareComponent::factory(),
            'model_name' => fake()->unique()->bothify('Model-####-???'),
            'model_number' => fake()->optional()->bothify('MN-#####'),
            'specifications' => ['notes' => fake()->sentence()],
        ];
    }
}
