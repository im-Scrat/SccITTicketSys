<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\TicketStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketStatusHistory>
 */
class TicketStatusHistoryFactory extends Factory
{
    protected $model = TicketStatusHistory::class;

    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'from_status_id' => null,
            'to_status_id' => TicketStatus::factory(),
            'changed_by' => User::factory(),
            'remarks' => fake()->optional()->sentence(),
        ];
    }
}
