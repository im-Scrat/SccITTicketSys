<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Models\PcUnit;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PcUnit>
 */
class PcUnitFactory extends Factory
{
    protected $model = PcUnit::class;

    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'unit_code' => fake()->unique()->bothify('PC-####'),
            'asset_tag' => fake()->unique()->bothify('AT-#####'),
            'hostname' => fake()->unique()->domainWord().'-pc',
            'qr_identifier' => fake()->unique()->uuid(),
            'pc_name' => 'PC '.fake()->unique()->numerify('###'),
            'brand' => fake()->randomElement(['Dell', 'HP', 'Lenovo', 'Acer', 'Asus']),
            'model' => fake()->bothify('Model-###'),
            'serial_number' => fake()->unique()->bothify('SN-########'),
            'ip_address' => fake()->optional()->ipv4(),
            'mac_address' => fake()->optional()->macAddress(),
            'purchase_date' => fake()->dateTimeBetween('-4 years', '-1 year'),
            'warranty_expiration' => fake()->dateTimeBetween('now', '+2 years'),
            'status' => PcStatus::Available->value,
            'current_condition' => PcCondition::Working->value,
        ];
    }
}
