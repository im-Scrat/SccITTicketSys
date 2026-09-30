<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Http\Requests;

use App\Enums\AiModality;
use App\Models\AiSystemSetting;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Register a model in the registry — a data change, no deploy (FR-AI-015). */
class StoreAiModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage', AiSystemSetting::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('ai_models', 'name')],
            // The identifier is interpolated into a provider URL path, so it is
            // restricted to the characters a real model id uses.
            'model_identifier' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            'modality' => ['required', Rule::in(AiModality::values())],
            // ai_embeddings.embedding is vector(768): a model that cannot be asked
            // for 768 dimensions cannot be stored, so it is refused here rather
            // than failing at index time (OD-4).
            'embedding_dimensions' => ['nullable', 'integer', 'in:768'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('modality') === AiModality::Embedding->value && (int) $this->input('embedding_dimensions') !== 768) {
                $validator->errors()->add('embedding_dimensions', 'Embedding models must produce 768 dimensions.');
            }
        });
    }
}
