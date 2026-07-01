<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupHistory>
 */
class BackupHistoryFactory extends Factory
{
    protected $model = BackupHistory::class;

    public function definition(): array
    {
        return [
            'backup_type' => fake()->randomElement(BackupType::values()),
            'status' => BackupStatus::Completed->value,
            'filename' => 'backup-'.fake()->unique()->numerify('########').'.sql.gz',
            'storage_location' => 's3://backups/',
            'disk' => 's3',
            'file_size' => fake()->numberBetween(1_000_000, 500_000_000),
            'started_at' => now()->subMinutes(10),
            'completed_at' => now(),
            'created_by' => User::factory(),
        ];
    }
}
