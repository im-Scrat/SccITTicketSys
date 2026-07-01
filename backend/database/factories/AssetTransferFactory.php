<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetTransfer;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetTransfer>
 */
class AssetTransferFactory extends Factory
{
    protected $model = AssetTransfer::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'to_room_id' => Room::factory(),
            'transferred_by' => User::factory(),
            'reason' => fake()->optional()->sentence(),
            'transferred_at' => now(),
        ];
    }
}
