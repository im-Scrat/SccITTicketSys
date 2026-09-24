<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationType;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An in-app notification row (SRS FR-NOT-001; SDD DD-52).
 *
 * `dedupe_key` is the WP-2.7a idempotency value behind the partial unique index
 * `notifications_dedupe_unique (user_id, dedupe_key) WHERE dedupe_key IS NOT
 * NULL`. It is written by the project database channel and deliberately never
 * leaves the application — see `NotificationResource`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property NotificationType $type
 * @property string $title
 * @property string|null $message
 * @property array<string, mixed>|null $data
 * @property string|null $dedupe_key
 * @property string|null $action_url
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property-read User|null $user
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory, HasUuidRouteKey;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
