<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiSender;
use App\Models\AiConversationLog;
use App\Models\AiModel;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiConversationLog>
 */
class AiConversationLogFactory extends Factory
{
    protected $model = AiConversationLog::class;

    public function definition(): array
    {
        return [
            'conversation_id' => (string) Str::uuid(),
            'ticket_id' => Ticket::factory(),
            'user_id' => User::factory(),
            'ai_model_id' => AiModel::factory(),
            'sender' => fake()->randomElement(AiSender::values()),
            'message' => fake()->paragraph(),
        ];
    }
}
