<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetStatusHistory>
 */
class AssetStatusHistoryFactory extends Factory
{
    protected $model = AssetStatusHistory::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'from_status' => AssetStatus::InStock->value,
            'to_status' => AssetStatus::Deployed->value,
            'changed_by' => User::factory(),
            'reason' => fake()->optional()->sentence(),
        ];
    }
}
