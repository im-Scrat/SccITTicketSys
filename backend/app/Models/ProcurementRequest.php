<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProcurementStatus;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\ProcurementRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProcurementRequest extends Model
{
    /** @use HasFactory<ProcurementRequestFactory> */
    use HasFactory, HasUuidRouteKey, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ProcurementStatus::class,
            'total_estimated_cost' => 'decimal:2',
            'needed_by' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<ProcurementRequestItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ProcurementRequestItem::class);
    }
}
