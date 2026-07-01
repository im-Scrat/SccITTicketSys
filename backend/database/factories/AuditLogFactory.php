<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'auditable_type' => Ticket::class,
            'auditable_id' => Ticket::factory(),
            'event' => AuditEvent::Created->value,
            'new_values' => ['title' => fake()->sentence()],
            'ip_address' => fake()->ipv4(),
        ];
    }
}
