<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Http\Requests;

use App\Models\AiSystemSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the administrator's AI settings (SRS FR-AI-014). Authorization is
 * the policy's `manage` — never the `ai.configure` permission string.
 */
class UpdateAiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage', AiSystemSetting::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Null clears the slot: no chat model selected means AI is off.
            'active_model' => ['nullable', 'uuid', Rule::exists('ai_models', 'uuid')],
            'embedding_model' => ['nullable', 'uuid', Rule::exists('ai_models', 'uuid')],
            'confidence_threshold' => ['required', 'numeric', 'between:0,1'],
            'enable_predictions' => ['required', 'boolean'],
            'enable_learning' => ['required', 'boolean'],
            'auto_generate_articles' => ['required', 'boolean'],
            'assistant_enabled' => ['required', 'boolean'],
        ];
    }
}
