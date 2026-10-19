<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MaintenanceStatus;
use App\Models\Asset;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceType;
use App\Models\PcUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceRecord>
 */
class MaintenanceRecordFactory extends Factory
{
    protected $model = MaintenanceRecord::class;

    public function definition(): array
    {
        // CHECK constraint: at least one of pc_unit_id / asset_id must be set.
        return [
            'pc_unit_id' => PcUnit::factory(),
            'technician_id' => User::factory(),
            'maintenance_type_id' => MaintenanceType::factory(),
            'title' => fake()->sentence(4),
            'diagnosis' => fake()->optional()->paragraph(),
            'resolution' => fake()->optional()->paragraph(),
            'downtime_minutes' => fake()->numberBetween(0, 480),
            'labor_hours' => fake()->randomFloat(2, 0, 8),
            'status' => MaintenanceStatus::Scheduled->value,
            'maintenance_date' => now(),
        ];
    }

    /**
     * Owned by a specific technician.
     *
     * Sets `created_by` to the same person, which is the shape a
     * technician-initiated record actually has — and the shape
     * `MaintenanceVisibility` reads for the "assigned **or** created" rule
     * (FR-MNT-011).
     */
    public function ownedBy(User $technician): static
    {
        return $this->state(fn (): array => [
            'technician_id' => $technician->getKey(),
            'created_by' => $technician->getKey(),
        ]);
    }

    /** Assigned to one person but opened by another (the administrator path). */
    public function assignedBy(User $technician, User $creator): static
    {
        return $this->state(fn (): array => [
            'technician_id' => $technician->getKey(),
            'created_by' => $creator->getKey(),
        ]);
    }

    public function scheduledFor(string $when): static
    {
        return $this->state(fn (): array => [
            'status' => MaintenanceStatus::Scheduled->value,
            'scheduled_for' => new CarbonImmutable($when),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (): array => [
            'status' => MaintenanceStatus::InProgress->value,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => MaintenanceStatus::Completed->value,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
            'resolution' => fake()->paragraph(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => MaintenanceStatus::Cancelled->value]);
    }

    /** Targets a standalone asset rather than a PC unit. */
    public function forAsset(Asset $asset): static
    {
        return $this->state(fn (): array => [
            'pc_unit_id' => null,
            'asset_id' => $asset->getKey(),
        ]);
    }
}
