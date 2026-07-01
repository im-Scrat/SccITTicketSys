<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\AiSystemSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiSystemSetting>
 */
class AiSystemSettingFactory extends Factory
{
    protected $model = AiSystemSetting::class;

    public function definition(): array
    {
        return [
            'active_model_id' => AiModel::factory(),
            'embedding_model_id' => AiModel::factory()->embedding(),
            'confidence_threshold' => fake()->randomFloat(4, 0.5, 0.95),
            'enable_predictions' => false,
            'enable_learning' => false,
            'auto_generate_articles' => false,
        ];
    }
}
