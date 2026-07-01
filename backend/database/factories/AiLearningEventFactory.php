<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiEventType;
use App\Models\AiLearningEvent;
use App\Models\PcUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiLearningEvent>
 */
class AiLearningEventFactory extends Factory
{
    protected $model = AiLearningEvent::class;

    public function definition(): array
    {
        return [
            'pc_unit_id' => PcUnit::factory(),
            'event_type' => fake()->randomElement(AiEventType::values()),
            'event_summary' => fake()->sentence(),
        ];
    }
}
