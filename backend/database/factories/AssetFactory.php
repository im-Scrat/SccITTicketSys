<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssetStatus;
use App\Enums\PcCondition;
use App\Models\Asset;
use App\Models\HardwareModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'asset_tag' => fake()->unique()->bothify('ASSET-#####'),
            'hardware_model_id' => HardwareModel::factory(),
            'serial_number' => fake()->unique()->bothify('SN-########'),
            'barcode' => fake()->optional()->ean13(),
            'status' => AssetStatus::InStock->value,
            'condition' => PcCondition::Working->value,
            'purchase_price' => fake()->randomFloat(2, 20, 2000),
            'purchase_date' => fake()->dateTimeBetween('-3 years', '-1 month'),
            'warranty_expiration' => fake()->dateTimeBetween('now', '+2 years'),
        ];
    }
}
