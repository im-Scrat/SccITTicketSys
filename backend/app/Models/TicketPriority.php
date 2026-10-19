<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TicketPriorityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A ticket priority and its SLA budget (SRS FR-TKT-004, BR-05).
 *
 * `response_time_minutes` and `resolution_time_minutes` are the source of a
 * ticket's `response_due_at`/`resolution_due_at`, which `SlaCalculator` derives
 * at creation and **recomputes** whenever the priority changes.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $level
 * @property string $color
 * @property int|null $response_time_minutes
 * @property int|null $resolution_time_minutes
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TicketPriority extends Model
{
    /** @use HasFactory<TicketPriorityFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'priority_id');
    }
}
