<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'ticket_id' => Ticket::factory(),
            'uploaded_by' => User::factory(),
            'disk' => 'local',
            'storage_path' => "attachments/{$name}.png",
            'original_filename' => "{$name}.png",
            'mime_type' => 'image/png',
            'file_size' => fake()->numberBetween(1024, 5_000_000),
            'checksum' => fake()->sha256(),
        ];
    }
}
