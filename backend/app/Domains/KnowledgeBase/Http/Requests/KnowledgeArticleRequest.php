<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Http\Requests;

use App\Models\AiKnowledgeArticle;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or edit a knowledge article. Authorization is the route gate
 * (`knowledge.create`) for a new one and the article policy for an edit.
 */
class KnowledgeArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $article = $this->route('article');

        return $article instanceof AiKnowledgeArticle
            ? (bool) $this->user()?->can('update', $article)
            : (bool) $this->user()?->hasPermissionTo('knowledge.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
            'problem_signature' => ['nullable', 'string', 'max:5000'],
            'root_cause' => ['nullable', 'string', 'max:5000'],
            'verified_solution' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
