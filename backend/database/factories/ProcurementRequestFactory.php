<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProcurementStatus;
use App\Models\ProcurementRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcurementRequest>
 */
class ProcurementRequestFactory extends Factory
{
    protected $model = ProcurementRequest::class;

    public function definition(): array
    {
        return [
            'request_number' => 'PR-'.fake()->unique()->numerify('######'),
            'requested_by' => User::factory(),
            'status' => ProcurementStatus::Draft->value,
            'purpose' => fake()->sentence(),
            'total_estimated_cost' => fake()->randomFloat(2, 100, 50000),
            'needed_by' => fake()->dateTimeBetween('now', '+3 months'),
        ];
    }
}
