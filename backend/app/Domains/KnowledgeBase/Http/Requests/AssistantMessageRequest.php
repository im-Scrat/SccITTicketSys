<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One question to the assistant (SRS FR-AI-004). The route gate is `ai.view`;
 * whether the caller may use an attached ticket is `TicketPolicy::viewFull`,
 * decided in the controller — validation here only checks shape.
 */
class AssistantMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:2', 'max:2000'],
            'conversation_id' => ['nullable', 'uuid'],
            'ticket' => ['nullable', 'uuid'],
        ];
    }
}
