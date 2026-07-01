<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QrStatus;
use App\Models\PcUnit;
use App\Models\QrCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QrCode>
 */
class QrCodeFactory extends Factory
{
    protected $model = QrCode::class;

    public function definition(): array
    {
        // CHECK constraint: exactly one of pc_unit_id / asset_id must be set.
        return [
            'pc_unit_id' => PcUnit::factory(),
            'asset_id' => null,
            'code' => fake()->unique()->uuid(),
            'location_label' => fake()->optional()->word(),
            'status' => QrStatus::Active->value,
            'generated_at' => now(),
        ];
    }
}
