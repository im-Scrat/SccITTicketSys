<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TicketStatusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
