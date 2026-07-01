<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TicketUpdateType;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketUpdate>
 */
class TicketUpdateFactory extends Factory
{
    protected $model = TicketUpdate::class;

    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'user_id' => User::factory(),
            'update_type' => fake()->randomElement(TicketUpdateType::values()),
            'body' => fake()->sentence(),
        ];
    }
}
