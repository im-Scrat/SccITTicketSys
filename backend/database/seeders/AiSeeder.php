<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AiModality;
use App\Models\AiModel;
use App\Models\AiSystemSetting;
use Illuminate\Database\Seeder;

/**
 * Registers a chat and an embedding model on a fresh install — and leaves an
 * existing installation's models alone.
 *
 * The identifiers are the ones production runs (2026-10-20): `gemini-3.5-flash`
 * and `gemini-embedding-001` (768 dimensions, the size the `ai_embeddings`
 * column requires). This seeder used to `updateOrCreate` `gemini-1.5-flash` and
 * `text-embedding-004`, so a re-seed reverted an administrator's model choice
 * to identifiers the provider has since retired. The current ids were first
 * written on the parked branch wip/pre-phase-2.9 (283ecfa); this re-applies
 * that intent without its overwrite.
 *
 * Models are changed afterwards in AI settings, which is audited.
 */
class AiSeeder extends Seeder
{
    public function run(): void
    {
        $chat = AiModel::query()
            ->where('modality', AiModality::Text->value)
            ->orderByDesc('is_default')
            ->first()
            ?? AiModel::query()->create([
                'name' => 'Gemini 3.5 Flash',
                'provider' => 'gemini',
                'model_identifier' => 'gemini-3.5-flash',
                'version' => '3.5',
                'modality' => AiModality::Text->value,
                'is_active' => true,
                'is_default' => true,
            ]);

        $embedding = AiModel::query()
            ->where('modality', AiModality::Embedding->value)
            ->first()
            ?? AiModel::query()->create([
                'name' => 'Gemini Embedding 001',
                'provider' => 'gemini',
                'model_identifier' => 'gemini-embedding-001',
                'version' => '001',
                'modality' => AiModality::Embedding->value,
                'embedding_dimensions' => 768,
                'is_active' => true,
                'is_default' => false,
            ]);

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
