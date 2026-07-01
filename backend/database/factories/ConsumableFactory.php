<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Consumable;
use App\Models\HardwareModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consumable>
 */
class ConsumableFactory extends Factory
{
    protected $model = Consumable::class;

    public function definition(): array
    {
        return [
            'hardware_model_id' => HardwareModel::factory(),
            'name' => ucwords(fake()->unique()->words(2, true)),
            'item_code' => fake()->unique()->bothify('CONS-#####'),
            'unit_of_measure' => fake()->randomElement(['unit', 'box', 'pack', 'roll']),
            'quantity_on_hand' => fake()->numberBetween(0, 500),
            'reorder_level' => fake()->numberBetween(5, 50),
            'unit_cost' => fake()->randomFloat(2, 1, 200),
            'is_active' => true,
        ];
    }
}
