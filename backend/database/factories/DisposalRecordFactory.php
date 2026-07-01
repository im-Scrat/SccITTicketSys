<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DisposalMethod;
use App\Models\Asset;
use App\Models\DisposalRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DisposalRecord>
 */
class DisposalRecordFactory extends Factory
{
    protected $model = DisposalRecord::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'disposal_method' => fake()->randomElement(DisposalMethod::values()),
            'disposal_reason' => fake()->sentence(),
            'disposal_date' => now(),
            'approved_by' => User::factory(),
            'salvage_value' => fake()->randomFloat(2, 0, 500),
        ];
    }
}
