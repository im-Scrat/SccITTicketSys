<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssetImportRowStatus;
use App\Models\AssetImport;
use App\Models\AssetImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetImportRow>
 */
class AssetImportRowFactory extends Factory
{
    protected $model = AssetImportRow::class;

    public function definition(): array
    {
        return [
            'asset_import_id' => AssetImport::factory(),
            'row_number' => fake()->unique()->numberBetween(2, 100000),
            'raw' => [],
            'status' => AssetImportRowStatus::NeedsReview,
            'is_excluded' => false,
        ];
    }
}
