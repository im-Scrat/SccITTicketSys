<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\TicketCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketComment extends Model
{
    /** @use HasFactory<TicketCommentFactory> */
    use HasFactory, HasUuidRouteKey, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'is_edited' => 'boolean',
            'edited_at' => 'datetime',
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

    /** @return BelongsTo<TicketComment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(TicketComment::class, 'parent_comment_id');
    }

    /** @return HasMany<TicketComment, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(TicketComment::class, 'parent_comment_id');
    }
}
