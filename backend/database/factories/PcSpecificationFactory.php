<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PcSpecification;
use App\Models\PcUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PcSpecification>
 */
class PcSpecificationFactory extends Factory
{
    protected $model = PcSpecification::class;

    public function definition(): array
    {
        return [
            'pc_unit_id' => PcUnit::factory(),
            'cpu' => fake()->randomElement(['Intel Core i5-12400', 'Intel Core i7-13700', 'AMD Ryzen 5 5600']),
            'motherboard' => fake()->bothify('Board-###'),
            'ram' => fake()->randomElement(['8GB DDR4', '16GB DDR4', '32GB DDR5']),
            'gpu' => fake()->randomElement(['Integrated', 'NVIDIA GTX 1650', 'NVIDIA RTX 3060']),
            'storage_primary' => fake()->randomElement(['256GB SSD', '512GB SSD', '1TB SSD']),
            'operating_system' => fake()->randomElement(['Windows 11 Pro', 'Windows 10 Pro', 'Ubuntu 24.04']),
            'bios_version' => fake()->bothify('v#.##'),
            'network_adapter' => 'Realtek Gigabit Ethernet',
        ];
    }
}
