<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RepairImageType;
use App\Models\MaintenanceRecord;
use App\Models\RepairImage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepairImage>
 */
class RepairImageFactory extends Factory
{
    protected $model = RepairImage::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'maintenance_record_id' => MaintenanceRecord::factory(),
            'uploaded_by' => User::factory(),
            'image_type' => fake()->randomElement(RepairImageType::values()),
            'disk' => 'local',
            'storage_path' => "repairs/{$name}.jpg",
            // The four columns DR-021 added, so a factory-built row is shaped
            // like one AttachRepairImage would actually write.
            'original_filename' => "{$name}.jpg",
            'mime_type' => 'image/jpeg',
            'file_size' => fake()->numberBetween(20_000, 4_000_000),
            'checksum' => hash('sha256', $name),
            'caption' => fake()->optional()->sentence(),
        ];
    }
}
