<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketUpdateType;
use Database\Factories\TicketUpdateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in a ticket's append-only activity feed (SRS FR-TKT-012).
 *
 * Distinct from `activity_logs`: this is the *ticket's* narrative, readable by
 * anyone who may see the ticket, whereas the audit log is the administrative
 * trail carrying actor IP and user agent.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $user_id
 * @property TicketUpdateType $update_type
 * @property string|null $body
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 */
class TicketUpdate extends Model
{
    /** @use HasFactory<TicketUpdateFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'update_type' => TicketUpdateType::class,
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
