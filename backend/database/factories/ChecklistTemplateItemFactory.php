<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistTemplateItem>
 */
class ChecklistTemplateItemFactory extends Factory
{
    protected $model = ChecklistTemplateItem::class;

    public function definition(): array
    {
        return [
            'checklist_template_id' => ChecklistTemplate::factory(),
            'label' => fake()->sentence(4),
            'sort_order' => fake()->numberBetween(0, 20),
            'is_required' => fake()->boolean(),
        ];
    }
}
