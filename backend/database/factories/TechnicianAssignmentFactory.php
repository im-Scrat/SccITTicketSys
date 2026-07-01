<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssignmentStatus;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TechnicianAssignment>
 */
class TechnicianAssignmentFactory extends Factory
{
    protected $model = TechnicianAssignment::class;

    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'technician_id' => User::factory(),
            'assigned_by' => User::factory(),
            'status' => AssignmentStatus::Pending->value,
            'assigned_at' => now(),
        ];
    }
}
