<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AiModality;
use App\Models\AiModel;
use App\Models\AiSystemSetting;
use Illuminate\Database\Seeder;

class AiSeeder extends Seeder
{
    public function run(): void
    {
        $chat = AiModel::query()->updateOrCreate(
            ['name' => 'Gemini 1.5 Flash'],
            [
                'provider' => 'gemini',
                'model_identifier' => 'gemini-1.5-flash',
                'version' => '1.5',
                'modality' => AiModality::Text->value,
                'is_active' => true,
                'is_default' => true,
            ],
        );

        $embedding = AiModel::query()->updateOrCreate(
            ['name' => 'Gemini Text Embedding 004'],
            [
                'provider' => 'gemini',
                'model_identifier' => 'text-embedding-004',
                'version' => '004',
                'modality' => AiModality::Embedding->value,
                'embedding_dimensions' => 768,
                'is_active' => true,
                'is_default' => false,
            ],
        );

        if (! AiSystemSetting::query()->exists()) {
            AiSystemSetting::query()->create([
                'active_model_id' => $chat->id,
                'embedding_model_id' => $embedding->id,
                'confidence_threshold' => 0.7,
                'enable_predictions' => false,
                'enable_learning' => false,
                'auto_generate_articles' => false,
            ]);
        }
    }
}
