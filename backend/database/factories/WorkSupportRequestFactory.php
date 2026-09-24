<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkSupportStatus;
use App\Models\PcUnit;
use App\Models\User;
use App\Models\WorkSupportRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkSupportRequest>
 */
class WorkSupportRequestFactory extends Factory
{
    protected $model = WorkSupportRequest::class;

    /**
     * A request as it exists the moment it is submitted.
     *
     * `explanation` is a real sentence rather than `fake()->word()`, because the
     * database CHECK refuses a blank one and a one-word explanation would make
     * every fixture read like a bug report about the fixture.
     */
    public function definition(): array
    {
        return [
            'pc_unit_id' => PcUnit::factory(),
            'technician_id' => User::factory(),
            'explanation' => 'The power supply has failed and there is no spare on site.',
            'status' => WorkSupportStatus::Submitted->value,
        ];
    }

    /** Approved with a new date, as the administrator's Decision A leaves it. */
    public function approved(?User $decidedBy = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => WorkSupportStatus::Approved->value,
            'decided_by' => $decidedBy?->getKey() ?? User::factory(),
            // NOT NULL whenever the status is approved or declined — the
            // database enforces it, so the factory must satisfy it.
            'decided_at' => now(),
            'rescheduled_to' => now()->addWeek(),
        ]);
    }

    /** Declined, with the reason the database insists on. */
    public function declined(?User $decidedBy = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => WorkSupportStatus::Declined->value,
            'decided_by' => $decidedBy?->getKey() ?? User::factory(),
            'decided_at' => now(),
            'decline_reason' => 'No budget this term; schedule for the next procurement round.',
        ]);
    }

    public function clarificationRequested(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => WorkSupportStatus::ClarificationRequested->value,
            'clarification_reason' => 'Come and talk me through why the whole unit needs replacing.',
        ]);
    }

    /** Withdrawn. `cancelled_at` is required by CHECK whenever the status is. */
    public function cancelled(?User $by = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => WorkSupportStatus::Cancelled->value,
            'cancelled_by' => $by?->getKey(),
            'cancelled_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => WorkSupportStatus::Closed->value,
            'closed_at' => now(),
        ]);
    }
}
