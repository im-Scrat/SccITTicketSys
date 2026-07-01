<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InstallationStatus;
use App\Models\Asset;
use App\Models\PcComponentInstallation;
use App\Models\PcUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PcComponentInstallation>
 */
class PcComponentInstallationFactory extends Factory
{
    protected $model = PcComponentInstallation::class;

    public function definition(): array
    {
        return [
            'pc_unit_id' => PcUnit::factory(),
            'asset_id' => Asset::factory(),
            'installed_by' => User::factory(),
            'installation_status' => InstallationStatus::Installed->value,
            'installation_date' => now(),
        ];
    }
}
