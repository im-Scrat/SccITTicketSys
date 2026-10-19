<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssetImportEntityMode;
use App\Enums\AssetImportStatus;
use App\Models\AssetImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetImport>
 */
class AssetImportFactory extends Factory
{
    protected $model = AssetImport::class;

    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'entity_mode' => AssetImportEntityMode::PcUnits,
            'status' => AssetImportStatus::Mapping,
            'original_filename' => 'inventory.csv',
            'extension' => 'csv',
            'mime' => 'text/csv',
            'size_bytes' => 100,
            'sha256' => hash('sha256', (string) fake()->uuid()),
            'disk' => 'local',
            'path' => null,
            'headers' => [],
            'mapping' => [],
            'expires_at' => now()->addDays(7),
        ];
    }
}
