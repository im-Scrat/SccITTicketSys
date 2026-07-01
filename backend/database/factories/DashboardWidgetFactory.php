<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WidgetType;
use App\Models\DashboardWidget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardWidget>
 */
class DashboardWidgetFactory extends Factory
{
    protected $model = DashboardWidget::class;

    public function definition(): array
    {
        return [
            'name' => ucwords(fake()->words(2, true)),
            'widget_type' => fake()->randomElement(WidgetType::values()),
            'configuration' => ['refresh' => fake()->numberBetween(30, 300)],
            'position' => fake()->numberBetween(0, 12),
            'is_enabled' => true,
        ];
    }
}
