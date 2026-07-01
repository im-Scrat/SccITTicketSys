<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TicketSource;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'ticket_number' => 'TKT-'.fake()->unique()->numerify('######'),
            'reporter_id' => User::factory(),
            'category_id' => TicketCategory::factory(),
            'priority_id' => TicketPriority::factory(),
            'current_status_id' => TicketStatus::factory(),
            'title' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'source' => TicketSource::Web->value,
            'technician_required' => fake()->boolean(),
        ];
    }
}
