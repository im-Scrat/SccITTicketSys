<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TicketStatusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A configurable ticket status (SRS FR-TKT-005).
 *
 * The status set is **data, not an enum**, so `TicketLifecycle` keys its
 * transition map on `slug` and reads the semantics from `is_open`/`is_terminal`
 * — an Administrator adding a status later does not require a code change to
 * what "open" or "terminal" means.
 *
 * `Resolved` is deliberately seeded `is_open = true`: it means *awaiting
 * reporter confirmation*, not finished (FR-TKT-016).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $color
 * @property string|null $description
 * @property bool $is_default
 * @property bool $is_open
 * @property bool $is_terminal
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TicketStatus extends Model
{
    /** @use HasFactory<TicketStatusFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_open' => 'boolean',
            'is_terminal' => 'boolean',
        ];
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'current_status_id');
    }
}
